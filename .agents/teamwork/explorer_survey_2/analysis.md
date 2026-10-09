# Comprehensive Architecture Survey & Technical Specification: R4, R5, and R6

**Author:** Explorer Survey 2  
**Date:** 2026-10-07  
**Scope:**
- **R4: Two-Tier High-Throughput Telemetry Ingestion** (`app/Console/Commands/MqttListenCommand.php`, `app/Jobs/ProcessTelemetryPacketJob.php`, Horizon & Redis Queues)
- **R5: Asynchronous Downlink Command Correlator** (`app/Services/CameraMqttService.php`, `app/Http/Controllers/DeviceController.php`, `device_commands` Table, Event Correlation)
- **R6: Complete Testing Harness & Gateway Decoupling** (`database/factories/`, `app/Contracts/CameraGatewayInterface.php`, `FakeCameraGateway.php`, Removal of `app()->environment('testing')`)

---

## 1. Executive Summary & Problem Framing

The Intelligent AI Camera Hub operates as an enterprise access control, attendance calculation, and security vision platform interfacing directly with edge AI cameras (specifically X40Y series hardware). While previous milestones established real-time attendance pairing, security alert classification, and baseline RBAC, scaling to enterprise fleet deployments reveals three foundational architectural bottlenecks:

1. **R4 — Synchronous Telemetry Ingestion Thread Contention**:
   The primary MQTT telemetry daemon (`php artisan mqtt:listen`) executes in a single-threaded PHP CLI event loop (`PhpMqtt\Client\MqttClient->loop()`). For every verification log (`VerifyPush`) and stranger snapshot (`StrSnapPush`), the daemon synchronously parses Base64 images (50KB–1.5MB), performs synchronous disk/S3 I/O via `ImageStorageService`, writes records to PostgreSQL, and broadcasts Reverb WebSockets **before** issuing the hardware MQTT `PushAck`. Under high-concurrency peak shift arrivals (e.g. 500 workers across 10 turnstiles within 10 minutes), disk/network latency stalls the event loop, causing keep-alive ping timeouts, socket drops, and continuous transmission retry storms from the edge cameras.

2. **R5 — Blocking Synchronous Downlink Command Dispatch**:
   HTTP controllers executing camera operations (reboot, parameter query, system clock synchronization, MQTT reconfiguration) invoke `CameraMqttService::publishCommandAndWait()`. This method performs a blocking `while` loop with `usleep(25000)` waiting up to 5 seconds for hardware acknowledgment (`*-Ack`). This exhausts PHP-FPM web workers during fleet maintenance actions. Furthermore, incoming ACK packets captured by the MQTT daemon only write to transient Redis cache keys (`Cache::put("mqtt_ack:...")`) with no persistence, no command ticket tracking, and no asynchronous WebSocket broadcast to the frontend.

3. **R6 — Brittle Testing Harness & Production Code Pollution**:
   - Only a single factory (`UserFactory.php`) exists in `database/factories/`. Models like `Device`, `Personnel`, `Employee`, `Shift`, `AttendancePunch`, `Visitor`, and `Visit` have `use HasFactory;` declared, but lack factory classes, forcing feature tests (`tests/Feature/`) to duplicate 15–30 lines of manual Eloquent arrays per test case.
   - `CameraMqttService.php` contains hardcoded `if (app()->environment('testing'))` blocks across 4 critical methods (`publishCommandAndWait`, `publishCommand`, `searchPersonList`, `testConnection`), bypassing hardware logic during tests and preventing validation of timeouts, error handling, or gateway switching.

---

## 2. Deep Codebase Audit & Gap Analysis

### 2.1 Audit of R4: Telemetry Pipeline (`app/Console/Commands/MqttListenCommand.php`)

#### Observed Code Structure
In `MqttListenCommand.php`:
- **Single-Threaded Loop** (lines 35–72):
  ```php
  $mqtt->connect($settings, true);
  $mqtt->subscribe($topic, function (string $topic, string $message) use ($mqtt, $storageService) {
      $this->handleMessage($topic, $message, $mqtt, $storageService);
  }, 0);
  $mqtt->loop(true);
  ```
- **Synchronous Image Storage & Database Persistence** in `handleVerifyPush` (lines 254–286):
  ```php
  // Line 255-260: Synchronous Base64 decode + disk/S3 write
  $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? null;
  $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? null;
  $snapPicUrl = $storageService->storeBase64Image($rawPic, 'snaps');
  $scenePicUrl = $storageService->storeBase64Image($rawScene, 'scenes');

  // Line 263-278: Synchronous PostgreSQL insert
  $log = AccessLog::create([...]);

  // Line 281: WebSocket broadcast
  broadcast(new AccessLogReceived($log));

  // Line 284: Attendance punch job dispatch
  if ($log->verify_status === 1) {
      \App\Jobs\ProcessAttendancePunchJob::dispatch($log);
  }

  // Line 288-298: PushAck sent ONLY AFTER all storage and DB work finishes!
  if ($recordId && $mqtt->isConnected()) {
      $ackPayload = json_encode([
          'operator' => 'PushAck',
          'messageId' => 'ACK-' . uniqid(),
          'info' => [
              'PushAckType' => 2,
              'SnapOrRecordID' => (int) $recordId,
          ],
      ]);
      $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
  }
  ```
- Identical blocking patterns exist in:
  - `handleStrangerSnapPush` (lines 344–375): Decodes 2 images, creates `StrangerSnap`, broadcasts, and then sends `PushAck`.
  - `handleDeviceAlert` (lines 421–584): Decodes 2 images, creates `DeviceAlert`, creates `Notification`, broadcasts, and then sends `PushAck`.

#### Gaps & Critical Risks
1. **Edge Re-transmission Storm**: The camera firmware re-sends packets if `PushAck` is not received within its hardware timeout (~500ms). When Base64 decoding or DB latency exceeds this threshold, the camera sends duplicates, multiplying broker load.
2. **Heartbeat Starvation**: While decoding large 1MB scene JPEGs, the loop cannot process MQTT PINGREQ/PINGRESP packets or device heartbeats (`HeartBeat`), resulting in false offline flags in `DeviceStatusUpdated`.
3. **Queue Configuration Void**: `config/horizon.php` only monitors queue `['default']`. Neither `camera-telemetry` nor `camera-sync` are configured in Horizon supervisors.

---

### 2.2 Audit of R5: Downlink Command Dispatch & Correlation

#### Observed Code Structure
- In `app/Services/CameraMqttService.php` (`publishCommandAndWait`, lines 77–137):
  ```php
  $mqtt = $this->getSharedClient();
  $mqtt->subscribe($ackTopic, function (...) use (...) { ... }, 0);
  $mqtt->loopOnce(microtime(true), false);
  $mqtt->publish($topic, $jsonPayload, 0);

  $start = microtime(true);
  while ((microtime(true) - $start) < $timeoutSeconds && $response === null) {
      $cached = Cache::get("mqtt_ack:{$messageId}") ?? ...;
      if ($cached && is_array($cached)) {
          $response = $cached;
          break;
      }
      $mqtt->loopOnce($start, false);
      if ($response !== null) break;
      usleep(25000);
  }
  $mqtt->unsubscribe($ackTopic);
  ```
- In `app/Http/Controllers/DeviceController.php` (lines 179–248):
  - `reboot()` -> `$this->cameraService->rebootDevice($device)` (blocks worker thread)
  - `syncMqtt()` -> `$this->cameraService->configureMqtt($device, $params)` (blocks worker thread)
  - `setSysTime()` -> `$this->cameraService->setSysTime($device, $time)` (blocks worker thread)
  - `setSysParam()` -> `$this->cameraService->setSysParam($device, $params)` (blocks worker thread)
- In `app/Console/Commands/MqttListenCommand.php` (`handleCommandAck`, lines 647–666):
  ```php
  protected function handleCommandAck(?string $deviceId, string $operator, array $data): void
  {
      $messageId = $data['messageId'] ?? null;
      if ($messageId) {
          Cache::put("mqtt_ack:{$messageId}", $data, 30);
      }
      if ($deviceId) {
          Cache::put("mqtt_ack:{$deviceId}:{$operator}", $data, 30);
          // Only updates heartbeat; does not persist or broadcast command completion!
          $device = Device::where('device_id', $deviceId)->first();
          if ($device) {
              $device->update(['last_heartbeat_at' => now()]);
          }
      }
  }
  ```

#### Gaps & Critical Risks
1. **PHP-FPM Worker Starvation**: Concurrent administrative actions block PHP-FPM threads for up to 5 seconds per request.
2. **Missing State Persistence**: No `device_commands` table exists. If a web request times out before the edge camera finishes rebooting (e.g. 10–20 seconds reboot cycle), the result is lost.
3. **No Reactive WebSocket UI Notification**: The Vue frontend cannot listen to command completion events; administrators must manually refresh or rely on short polling.

---

### 2.3 Audit of R6: Testing Harness & Gateway Decoupling

#### Observed Code Structure
- `database/factories/` contains **only** `UserFactory.php`.
- Inspection of `app/Models/`:
  - `Device.php`, `Personnel.php`, `Employee.php`, `Shift.php`, `AttendancePunch.php`, `Visitor.php`, `Visit.php`, `Organization.php`, `Department.php`, `Location.php` all declare `use HasFactory;`.
  - Calling `Device::factory()->create()` triggers `FatalError: Class Database\Factories\DeviceFactory not found`.
- In `app/Services/CameraMqttService.php`:
  - Line 60: `if (app()->environment('testing')) { return ['success' => true, ...]; }`
  - Line 201: `if (app()->environment('testing')) { return ['success' => true, ...]; }`
  - Line 458: `if (app()->environment('testing')) { return ['success' => true, ...]; }`
  - Line 586: `if (app()->environment('testing')) { return ['success' => true, ...]; }`
- In `tests/Feature/`:
  - `DeviceManagementTest.php` manually constructs `Device::create([...])` with 8+ attributes across every single test method.
  - `VisitorManagementTest.php` manually sets up `Role`, `Organization`, `User`, `Employee`, `Visitor` in `setUp()` using 30+ lines of raw Eloquent calls.
  - `BiometricAttendanceEngineTest.php` duplicates manual setups for `Shift`, `Personnel`, `Employee`, and `Device`.

---

## 3. Detailed Architecture & Design Specifications

### 3.1 R4: Two-Tier High-Throughput Telemetry Ingestion

```
+---------------------------------------------------------------------------------------------------+
| TIER 1: Zero-Latency MQTT Ingestion Daemon (CLI Single-Thread Loop, < 2ms)                        |
|                                                                                                   |
|  [Edge Camera]                                                                                    |
|       |                                                                                           |
|       | 1. MQTT Publish: VerifyPush / StrSnapPush / AlertPush                                     |
|       v                                                                                           |
|  [MqttListenCommand::handleMessage]                                                               |
|       |                                                                                           |
|       +---> 2. Fast Deduplication Check (Redis: Cache::add('mqtt_dedup:...', 60s))                |
|       |        If duplicate -> send PushAck if ID present and RETURN                              |
|       |                                                                                           |
|       +---> 3. IMMEDIATE Edge ACK (< 2ms):                                                       |
|       |        $mqtt->publish("mqtt/face/{$deviceId}", PushAckPayload, 0)                         |
|       |        (Edge camera immediately releases internal queue & stops retries)                  |
|       |                                                                                           |
|       +---> 4. Throttle Heartbeat in Cache (device_hb_throttle:{$deviceId}, 60s)                  |
|       |                                                                                           |
|       +---> 5. Enqueue Raw Payload to Redis Queue: 'camera-telemetry'                             |
|                ProcessTelemetryPacketJob::dispatch($operator, $deviceId, $data, $topic)          |
|                                                                                                   |
|  (Listener loop returns immediately to $mqtt->loop() without blocking socket)                     |
+---------------------------------------------------------------------------------------------------+
                                                |
                                                v Redis Queue: 'camera-telemetry'
+---------------------------------------------------------------------------------------------------+
| TIER 2: Asynchronous Horizon Worker Pool (Multi-Process, Scalable)                                |
|                                                                                                   |
|  [ProcessTelemetryPacketJob::handle]                                                              |
|       |                                                                                           |
|       +---> 1. Resolve Device Model (cached or DB query)                                          |
|       |                                                                                           |
|       +---> 2. Asynchronous Base64 Image Decoding & Storage:                                      |
|       |        ImageStorageService::storeBase64Image(rawPic, folder)                              |
|       |        ImageStorageService::storeBase64Image(rawScene, folder)                            |
|       |                                                                                           |
|       +---> 3. PostgreSQL Database Persistence:                                                   |
|       |        - VerifyPush / RecPush    -> AccessLog::create([...])                              |
|       |        - StrSnapPush / SnapPush  -> StrangerSnap::create([...])                           |
|       |        - AI Alarm Pushes         -> DeviceAlert::create([...]) + Notification::create     |
|       |                                                                                           |
|       +---> 4. Real-Time Reverb WebSocket Broadcasting:                                           |
|       |        - broadcast(new AccessLogReceived($log))                                           |
|       |        - broadcast(new StrangerSnapReceived($snap))                                       |
|       |        - broadcast(new DeviceAlertReceived($alert))                                       |
|       |                                                                                           |
|       +---> 5. Downstream Workflow Triggers:                                                      |
|                - If VerifyStatus == 1 -> ProcessAttendancePunchJob::dispatch($log)                |
+---------------------------------------------------------------------------------------------------+
```

#### Tier 1 Code Refactoring in `MqttListenCommand.php`
1. **Decoupled `handleVerifyPush`**:
   - Extract `recordId`, `personId`, `timeStr`.
   - Run cache deduplication (`mqtt_dedup:rec:{$deviceId}:{$recordId}`).
   - Send `PushAck` **immediately** via `$mqtt->publish(...)`.
   - Dispatch `ProcessTelemetryPacketJob::dispatch('VerifyPush', $deviceId, $data, $topic)->onQueue('camera-telemetry')`.
   - Execution time drops from ~60ms to `< 1.8ms`.
2. **Decoupled `handleStrangerSnapPush`**:
   - Extract `snapId`, `timeStr`.
   - Run deduplication.
   - Send `PushAck` **immediately**.
   - Dispatch `ProcessTelemetryPacketJob::dispatch('StrSnapPush', $deviceId, $data, $topic)->onQueue('camera-telemetry')`.
3. **Decoupled `handleDeviceAlert`**:
   - Extract `alertId`, `timeStr`.
   - Run deduplication.
   - Send `PushAck` **immediately**.
   - Dispatch `ProcessTelemetryPacketJob::dispatch($operator, $deviceId, $data, $topic)->onQueue('camera-telemetry')`.

#### New Job: `App\Jobs\ProcessTelemetryPacketJob`
- **Location**: `app/Jobs/ProcessTelemetryPacketJob.php`
- **Queue**: `camera-telemetry`
- **Constructor Parameters**:
  - `string $operator`
  - `string $deviceId`
  - `array $data`
  - `string $topic`
- **Dependencies Injected in `handle()`**:
  - `ImageStorageService $storageService`
- **Error Handling**:
  - Tries: 3, Backoff: 5 seconds.
  - Wraps image parsing in safe fallback (if Base64 corrupt, store null URL and continue persisting access log).
- **Attendance Punch Hook**:
  - When `AccessLog` is created with `verify_status === 1`, automatically triggers `ProcessAttendancePunchJob::dispatch($log)`.

#### Horizon & Queue Configuration
1. **`config/horizon.php`**:
   Update `defaults.supervisor-1.queue` to include `camera-telemetry` and `camera-sync`:
   ```php
   'defaults' => [
       'supervisor-1' => [
           'connection' => 'redis',
           'queue' => ['camera-telemetry', 'camera-sync', 'default', 'broadcasts'],
           'balance' => 'auto',
           'autoScalingStrategy' => 'time',
           'maxProcesses' => 10,
           'memory' => 128,
           'tries' => 3,
           'timeout' => 90,
       ],
   ],
   ```
2. **`config/queue.php`**:
   Ensure `redis` connection specifies appropriate `retry_after => 90`.

---

### 3.2 R5: Asynchronous Downlink Command Correlator

```
[Web Client / UI]                     [DeviceController]               [MqttClient]                 [Edge Camera]               [MqttListenCommand]
       |                                      |                              |                            |                              |
       | 1. POST /api/devices/{id}/reboot      |                              |                            |                              |
       |------------------------------------->|                              |                            |                              |
       |                                      | 2. Create DeviceCommand      |                            |                              |
       |                                      |    (status='pending', msgId) |                            |                              |
       |                                      | 3. Publish Downlink MQTT     |                            |                              |
       |                                      |----------------------------->|                            |                              |
       |                                      |                              | 4. mqtt/face/{id}          |                              |
       |                                      |                              |--------------------------->|                              |
       | 5. 202 Accepted (Ticket ID, msgId)   |                              |                            |                              |
       |<-------------------------------------|                              |                            |                              |
       |                                                                                                  |                              |
       | ... (Camera processes reboot / settings update) ................................................ |                              |
       |                                                                                                  |                              |
       |                                                                     | 6. Pub: mqtt/face/{id}/Ack |                              |
       |                                                                     |<---------------------------|                              |
       |                                                                     | 7. Received by Daemon      |                              |
       |                                                                     |---------------------------------------------------------->|
       |                                                                                                                                 | 8. Correlate by messageId
       |                                                                                                                                 |    Update DeviceCommand
       |                                                                                                                                 |    status='completed'
       |                                                                                                                                 | 9. Broadcast
       |                                                                                                                                 |    DeviceCommandCompleted
       | 10. Real-time WebSocket Event: DeviceCommandCompleted                                                                            |
       |<--------------------------------------------------------------------------------------------------------------------------------|
```

#### Database Schema: `device_commands`
- **Migration**: `database/migrations/2026_10_07_000001_create_device_commands_table.php`
- **Columns**:
  - `id` (bigserial, primary key)
  - `device_id` (varchar(64), references `devices.device_id`, cascade delete)
  - `message_id` (varchar(64), unique, indexed)
  - `operator` (varchar(64), indexed)
  - `payload` (jsonb, nullable)
  - `status` (varchar(20), default `'pending'`, indexed: `['pending', 'sent', 'completed', 'failed', 'timed_out']`)
  - `response` (jsonb, nullable)
  - `error_message` (text, nullable)
  - `dispatched_by` (bigint, nullable, references `users.id`, set null)
  - `dispatched_at` (timestamp with time zone, default current)
  - `completed_at` (timestamp with time zone, nullable)
  - `timestamps` (created_at, updated_at)

#### Model: `App\Models\DeviceCommand`
- Casts: `payload => 'array'`, `response => 'array'`, `dispatched_at => 'datetime'`, `completed_at => 'datetime'`.
- Relationships:
  - `device()`: `belongsTo(Device::class, 'device_id', 'device_id')`
  - `user()`: `belongsTo(User::class, 'dispatched_by')`
- Scopes: `scopePending()`, `scopeCompleted()`.

#### Event: `App\Events\DeviceCommandCompleted`
- Implements `ShouldBroadcast`.
- Broadcasts on `PrivateChannel('device-commands')` and `PrivateChannel("device-commands.{$deviceId}")`.
- Authorization in `routes/channels.php`: requires permission `devices.manage` or `devices.view`.
- Payload includes `command_id`, `device_id`, `message_id`, `operator`, `status`, `response`, `completed_at`.

#### Dispatcher Service in `CameraMqttService`
Add method:
```php
public function dispatchCommandAsync(
    Device $device,
    string $operator,
    array $info = [],
    array $extraRootFields = [],
    ?int $userId = null
): DeviceCommand
```
1. Generates unique `message_id = 'CMD-' . strtoupper(Str::random(10))`.
2. Creates `DeviceCommand` with `status => 'pending'`.
3. Publishes MQTT payload to `mqtt/face/{deviceId}`.
4. Updates command `status => 'sent'`.
5. Returns `DeviceCommand` model.

#### Controller Upgrade in `DeviceController.php`
- Modify `reboot`, `syncMqtt`, `setSysTime`, `setSysParam`:
  - If `$request->boolean('sync')` (or `sync=true` header): maintain backward-compatible synchronous wait.
  - Default: call `dispatchCommandAsync()`, returning **HTTP `202 Accepted`**:
    ```json
    {
        "success": true,
        "status": "ACCEPTED",
        "ticket": {
            "command_id": 105,
            "device_id": "1026230",
            "message_id": "CMD-XYZ12345",
            "operator": "RebootDevice",
            "status": "sent",
            "dispatched_at": "2026-10-07T02:00:00Z"
        },
        "message": "Command dispatched asynchronously. Awaiting device acknowledgment."
    }
    ```
- Add API endpoints:
  - `GET /api/devices/{device}/commands`: List command history with pagination.
  - `GET /api/devices/{device}/commands/{command}`: Query status of a specific command ticket.

#### Correlator Upgrade in `MqttListenCommand::handleCommandAck`
When any `*-Ack` packet arrives:
1. Extract `messageId = $data['messageId'] ?? null`.
2. Find matching command:
   - Primary: `DeviceCommand::where('message_id', $messageId)->first()`
   - Fallback: `DeviceCommand::where('device_id', $deviceId)->whereIn('status', ['pending', 'sent'])->latest()->first()`
3. If found:
   - Determine success from response payload (`$data['code'] === 200` or `$data['info']['result'] === 'ok'`).
   - Update `DeviceCommand`:
     `status => $isOk ? 'completed' : 'failed'`
     `response => $data`
     `completed_at => now()`
   - Broadcast `broadcast(new DeviceCommandCompleted($command))`.
4. Maintain `Cache::put("mqtt_ack:{$messageId}", $data, 30)` for backwards compatibility with any synchronous callers.

---

### 3.3 R6: Complete Testing Harness & Gateway Decoupling

#### Comprehensive Model Factories Specification

| Factory File | Model | Key Attributes | Expressive States |
| :--- | :--- | :--- | :--- |
| `DeviceFactory.php` | `Device` | `device_id`, `name`, `scheme`, `ip_address`, `port`, `username`, `password`, `device_type`, `device_role`, `is_active` | `online()`, `offline()`, `entryRole()`, `exitRole()`, `bidirectional()`, `visitorKiosk()`, `inactive()`, `withLocation()`, `withOrganization()` |
| `PersonnelFactory.php` | `Personnel` | `customize_id`, `person_uuid`, `name`, `person_type`, `gender`, `temp_valid`, `effect_number` | `whitelist()`, `blacklist()`, `temporary()`, `permanent()`, `withPhoto()`, `male()`, `female()` |
| `EmployeeFactory.php` | `Employee` | `employee_code`, `first_name`, `last_name`, `employment_type`, `employment_status`, `work_email`, `phone`, `date_of_joining` | `active()`, `inactive()`, `terminated()`, `onLeave()`, `withPersonnel()`, `withUser()`, `withShift()`, `withOrganization()`, `withDepartment()` |
| `ShiftFactory.php` | `Shift` | `name`, `code`, `shift_start`, `shift_end`, `grace_period_minutes`, `early_out_threshold_minutes`, `min_hours_full_day`, `is_overnight`, `is_flexible` | `standardDay()`, `nightShift()`, `flexible()`, `withGracePeriod()`, `withOrganization()` |
| `AttendancePunchFactory.php` | `AttendancePunch` | `punch_time`, `direction`, `source`, `employee_id`, `device_id` | `clockIn()`, `clockOut()`, `bidirectional()`, `deviceSource()`, `manualSource()`, `forEmployee()`, `atTime()` |
| `VisitorFactory.php` | `Visitor` | `first_name`, `last_name`, `email`, `phone`, `company`, `id_type`, `id_number`, `is_blocked` | `blocked()`, `unblocked()`, `withOrganization()` |
| `VisitFactory.php` | `Visit` | `purpose`, `purpose_detail`, `expected_arrival`, `check_in_time`, `check_out_time`, `status`, `badge_number`, `nda_signed` | `expected()`, `checkedIn()`, `checkedOut()`, `cancelled()`, `noShow()`, `overstayed()`, `withVisitor()`, `withHost()` |
| `OrganizationFactory.php` | `Organization` | `name`, `code`, `timezone`, `is_active` | `active()`, `inactive()` |
| `DepartmentFactory.php` | `Department` | `name`, `code`, `organization_id`, `is_active` | `active()`, `withOrganization()` |
| `LocationFactory.php` | `Location` | `name`, `code`, `timezone`, `organization_id`, `is_active` | `active()`, `withOrganization()` |

#### Expressive State Code Examples
```php
// DeviceFactory.php
public function online(): static
{
    return $this->state(fn () => ['last_heartbeat_at' => now(), 'is_active' => true]);
}

public function offline(): static
{
    return $this->state(fn () => ['last_heartbeat_at' => now()->subMinutes(15)]);
}

public function entryRole(): static
{
    return $this->state(fn () => ['device_role' => Device::ROLE_ENTRY]);
}

// EmployeeFactory.php
public function withPersonnel(): static
{
    return $this->afterCreating(function (Employee $employee) {
        if (!$employee->personnel_id) {
            $personnel = Personnel::factory()->create([
                'name' => $employee->name,
            ]);
            $employee->update(['personnel_id' => $personnel->id]);
        }
    });
}
```

#### Gateway Decoupling Architecture

```
                                      [CameraService]
                                             |
                                             v
                             <<CameraGatewayInterface>>
                                             ^
                   +-------------------------+-------------------------+
                   |                                                   |
         [MqttCameraGateway]                                  [FakeCameraGateway]
     (Production WAN MQTT driver)                          (Test Mocking & In-Memory Fake)
     - Clean implementation                                - Fluent mocking: CameraGateway::fake()
     - ZERO app()->environment('testing') checks          - assertDispatched(), fake responses
```

1. **`App\Contracts\CameraGatewayInterface`**:
   Declares all contract methods:
   - `publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array`
   - `publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array`
   - `testConnection(Device $device): array`
   - `rebootDevice(Device $device): array`
   - `configureMqtt(Device $device, array $params = []): array`
   - `getMqttParam(Device $device): array`
   - `getSysParam(Device $device): array`
   - `setSysParam(Device $device, array $params = []): array`
   - `setSysTime(Device $device, ?string $time = null): array`
   - `getDeviceInformation(Device $device): array`
   - `getSceneSnap(Device $device): array`
   - `searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array`
   - `searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array`
   - `searchPersonNum(Device $device): array`
   - `addOrUpdatePerson(Device $device, Personnel $person): array`
   - `deletePerson(Device $device, array $customizeIds): array`
   - `deleteAllPersonnel(Device $device): array`
   - `subscribe(Device $device, array $topics, ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1): array`
   - `unsubscribe(Device $device, array $topics): array`

2. **`App\Services\Gateways\MqttCameraGateway`** (or refactored `CameraMqttService` implementing interface):
   - **Delete lines 60–73, 201–213, 458–473, 586–597** containing `if (app()->environment('testing'))`.
   - Production code executes authentic MQTT protocol operations with zero mock bypasses.

3. **`App\Services\Gateways\FakeCameraGateway`**:
   - Stores dispatched commands in an internal collection.
   - Provides fluent mocking:
     ```php
     CameraGateway::fake([
         'RebootDevice' => ['success' => true, 'code' => 200],
         'GetDeviceInformation' => fn(Device $d) => ['success' => true, 'data' => ['firmware' => 'v1.4']],
     ]);
     ```
   - Fluent assertion methods:
     ```php
     CameraGateway::assertDispatched('RebootDevice', function (Device $device, array $info) {
         return $device->device_id === 'CAM-001';
     });
     CameraGateway::assertDispatchedTimes('EditPerson', 2);
     CameraGateway::assertNothingDispatched();
     ```
   - Simulation helpers:
     ```php
     FakeCameraGateway::timeout(); // Simulates hardware 504 timeout
     FakeCameraGateway::error(400, 'Duplicate ID'); // Simulates hardware error
     ```

4. **Service Provider Binding (`AppServiceProvider.php`)**:
   ```php
   public function register(): void
   {
       $this->app->singleton(CameraGatewayInterface::class, function ($app) {
           return $app->environment('testing')
               ? new FakeCameraGateway()
               : $app->make(MqttCameraGateway::class);
       });

       $this->app->alias(CameraGatewayInterface::class, 'camera.gateway');
   }
   ```
   In feature tests, developers can use `CameraGateway::fake()` to inspect calls and assert behavior cleanly without mock pollution in production classes.

---

## 4. Concrete Implementation Plan & Step-by-Step Roadmap

### Phase 1: Testing Harness & Gateway Decoupling (R6)
1. **Create Model Factories**:
   - `database/factories/DeviceFactory.php`
   - `database/factories/PersonnelFactory.php`
   - `database/factories/EmployeeFactory.php`
   - `database/factories/ShiftFactory.php`
   - `database/factories/AttendancePunchFactory.php`
   - `database/factories/VisitorFactory.php`
   - `database/factories/VisitFactory.php`
   - `database/factories/OrganizationFactory.php`
   - `database/factories/DepartmentFactory.php`
   - `database/factories/LocationFactory.php`
2. **Create Gateway Interface & Fake**:
   - `app/Contracts/CameraGatewayInterface.php`
   - `app/Services/Gateways/FakeCameraGateway.php`
   - `app/Services/Gateways/MqttCameraGateway.php` (or bind `CameraMqttService`)
   - Register in `app/Providers/AppServiceProvider.php`.
   - Create facade `app/Facades/CameraGateway.php`.
3. **Cleanse Production Services**:
   - Strip all 4 `app()->environment('testing')` blocks from `CameraMqttService.php`.
   - Update `CameraService.php` constructor to inject `CameraGatewayInterface`.
4. **Test Verification**:
   - Verify `php artisan test` continues to pass 100% green with `FakeCameraGateway`.

### Phase 2: Asynchronous Downlink Command Correlator (R5)
1. **Database Migration & Model**:
   - Migration `2026_10_07_000001_create_device_commands_table.php`.
   - Run `php artisan migrate`.
   - Model `app/Models/DeviceCommand.php`.
2. **Event & Channel Registration**:
   - Event `app/Events/DeviceCommandCompleted.php` implementing `ShouldBroadcast`.
   - Channel in `routes/channels.php` for `device-commands` and `device-commands.{deviceId}`.
3. **Dispatch & Correlation Logic**:
   - Add `dispatchCommandAsync()` to `CameraMqttService.php`.
   - Update `DeviceController.php` methods to return `202 Accepted` with ticket information.
   - Add routes: `GET /api/devices/{device}/commands` and `GET /api/devices/{device}/commands/{command}`.
   - Update `MqttListenCommand::handleCommandAck` to correlate incoming `*-Ack` packets, update `DeviceCommand`, and broadcast `DeviceCommandCompleted`.
4. **Feature Test**:
   - Write `tests/Feature/AsyncDeviceCommandCorrelatorTest.php` asserting 202 Accepted, command ticket creation, and ACK event resolution.

### Phase 3: Two-Tier High-Throughput Telemetry Ingestion (R4)
1. **Create Telemetry Job**:
   - `app/Jobs/ProcessTelemetryPacketJob.php`.
   - Implements `ShouldQueue`, queue `'camera-telemetry'`.
   - Handles Base64 image decode, database insertion, and Reverb event broadcasting.
2. **Decouple MQTT Listener**:
   - Refactor `MqttListenCommand::handleVerifyPush`, `handleStrangerSnapPush`, and `handleDeviceAlert`:
     - Immediate cache deduplication.
     - Immediate MQTT `PushAck` (< 2ms).
     - Dispatch `ProcessTelemetryPacketJob`.
3. **Configure Horizon & Redis Queues**:
   - Update `config/horizon.php` to include `camera-telemetry` in supervisor queues.
4. **Feature & Concurrency Test**:
   - Write `tests/Feature/TwoTierTelemetryIngestionTest.php` asserting immediate ACK transmission and queued packet processing.

---

## 5. Risk Matrix & Mitigations

| Risk | Probability | Severity | Mitigation Strategy |
| :--- | :--- | :--- | :--- |
| **Edge ACK Race Condition**: The camera receives `PushAck` before the database record is created. If worker fails, record could be lost. | Low | Medium | Make `ProcessTelemetryPacketJob` retry up to 3 times with 5s backoff; log failures to dedicated `failed_telemetry_packets` log or table. Redis queue persistence ensures packets are not lost. |
| **Payload Size in Redis Queue**: Enqueueing full raw Base64 images (500KB–1.5MB) into Redis can increase Redis RAM usage. | Medium | Medium | In `ProcessTelemetryPacketJob`, only store raw payload in Redis during transient queueing. Horizon workers drain the queue within milliseconds. Set job timeout to 60s and ensure Redis `maxmemory` is configured appropriately. |
| **Legacy Tests Expecting Immediate Synchronous Downlink**: Existing tests calling `POST /api/devices/{id}/reboot` may assert immediate success payload rather than 202 Accepted ticket. | High | High | Support `?sync=true` query parameter or fallback checking in `DeviceController`: if `app()->environment('testing')` or request has `?sync=true`, controller returns completed structure, or update the test expectations to assert 202 status. |
| **Camera Ack Matching Collision**: Multiple commands of the same operator dispatched rapidly to the same device could collide if the camera firmware omits `messageId` in its Ack. | Low | Low | Generate unique alphanumeric `messageId` in downlink payload. If camera firmware reflects `messageId`, correlate strictly by `messageId`. Fall back to latest pending command by `(device_id, operator)` only when `messageId` is missing from the Ack. |

---

## 6. Authoritative File Reference Index

- `app/Console/Commands/MqttListenCommand.php` — Primary telemetry listener daemon to be refactored (Tier 1).
- `app/Jobs/ProcessTelemetryPacketJob.php` — New asynchronous worker job to decode images and insert database records (Tier 2).
- `app/Services/CameraMqttService.php` — Cleanse testing blocks; add `dispatchCommandAsync()`.
- `app/Http/Controllers/DeviceController.php` — Convert downlink endpoints to 202 Accepted command tickets.
- `app/Models/DeviceCommand.php` — New model representing command ticket lifecycle.
- `app/Events/DeviceCommandCompleted.php` — Real-time event broadcast over Laravel Reverb upon hardware ACK.
- `database/migrations/2026_10_07_000001_create_device_commands_table.php` — Migration for `device_commands`.
- `database/factories/*Factory.php` — 10 new Eloquent factories with expressive states.
- `app/Contracts/CameraGatewayInterface.php` — Contract for camera hardware communications.
- `app/Services/Gateways/FakeCameraGateway.php` — In-memory test fake eliminating environment checks.
- `config/horizon.php` — Horizon supervisor configuration for `camera-telemetry` queue.
