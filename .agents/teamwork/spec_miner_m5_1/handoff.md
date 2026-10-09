# Milestone M5 Specification Discovery & Mining Report

**Module:** Two-Tier Telemetry Pipeline Decoupling & Asynchronous Downlink Command Correlator (Features #27 through #33)  
**Author:** `spec_miner_m5_1` (Specification Miner)  
**Target Milestone:** Milestone M5  
**Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1`

---

## 1. Observation

Direct observations and evidence extracted from authoritative specification files, architecture blueprints, existing code, and test suites:

### 1.1 Requirements & System Blueprint
- **`ORIGINAL_REQUEST.md` (lines 410–417, Section `## 2026-10-07T01:57:58Z`)**:
  > "R4. Two-Tier High-Throughput Telemetry Ingestion (Area 1): Decouple the primary MQTT listener daemon from heavy operations: the daemon must immediately acknowledge incoming camera packets (`PushAck`) and enqueue raw telemetry payloads into a Redis queue. Implement asynchronous workers to consume queued telemetry packets, decode biometric images, persist database records, and broadcast live events without blocking the listener thread."  
  > "R5. Asynchronous Downlink Command Correlator (Area 2): Replace blocking sleep/loop downlink operations in HTTP request cycles with an asynchronous command ticket pattern. Record outbound device commands, return immediate accepted responses with command tracking tickets, and correlate incoming hardware acknowledgment packets via MQTT listeners to complete command tickets and notify the UI via WebSockets."

- **`system-evo.md` (lines 187–242, Areas 1 & 2)**:
  > **Area 1: Telemetry Pipeline Decoupling**:  
  > Current single-threaded CLI event loop executes Base64 image decoding, disk/S3 storage I/O, and PostgreSQL database inserts synchronously within `MqttListenCommand::handleVerifyPush()`. Under bursts (e.g. 500 clock-ins across 10 turnstiles), disk/DB latency blocks the single listener thread, dropping broker ping packets and edge camera TCP sockets.  
  > *Two-Tier Telemetry Ingestion Architecture:*  
  > - **Tier 1 (Zero-Latency Daemon)**: Receives packet, extracts `RecordID` or `SnapID`, immediately publishes MQTT `PushAck` (<2ms) on `mqtt/face/{deviceId}`, and enqueues raw packet into Redis queue `camera-telemetry`. Listener loop remains unblocked.  
  > - **Tier 2 (Asynchronous Worker Pool)**: Scalable Horizon worker processes (`php artisan horizon`, queue `camera-telemetry`) consume `ProcessTelemetryPacketJob`, decode images via `ImageStorageService`, insert `AccessLog` or `StrangerSnap` in PostgreSQL, and broadcast live events.  
  > **Area 2: Asynchronous Downlink Command Pattern**:  
  > Current `publishCommandAndWait()` blocks web HTTP worker threads for up to 5 seconds.  
  > *Command Correlator Pattern:*  
  > 1. Downlink requests create `device_commands` table record (`device_id`, `message_id`, `operator`, `status = 'pending'`, `payload`, `response = null`).  
  > 2. Controller immediately responds with `202 Accepted` and command ticket.  
  > 3. `MqttListenCommand::handleCommandAck()` matches incoming `*-Ack` packets by `messageId`, updates `device_commands` status to `'completed'` (or `'failed'` on non-zero error code), and broadcasts `DeviceCommandCompleted` via Reverb.

- **`PROJECT.md` (lines 43–49, Features #27 through #33)**:
  - Feature 27: Zero-Latency Telemetry Ingestion (Tier 1) — `MqttListenCommand` immediate `PushAck` (<2ms) and enqueue to Redis.
  - Feature 28: Asynchronous Telemetry Worker (Tier 2) — `ProcessTelemetryPacketJob` on Redis queue `camera-telemetry`.
  - Feature 29: Horizon Configuration for Telemetry — Configure supervisor queue `camera-telemetry` in `config/horizon.php`.
  - Feature 30: Downlink Command Table & Model — Migration `device_commands` and model `DeviceCommand`.
  - Feature 31: Non-blocking Downlink Ticket Dispatch — `CameraMqttService::dispatchCommandAsync` & `202 Accepted` response.
  - Feature 32: Hardware ACK Correlation — Correlation in `MqttListenCommand` / `CameraMqttService` by `messageId` updating tickets.
  - Feature 33: Downlink Event Broadcasting — Broadcast `DeviceCommandCompleted` via Laravel Reverb.

### 1.2 Test Suite Assertions & Interface Expectations
- **`tests/Feature/E2E/Tier1FeatureCoverageTest.php` (lines 1514–1652)**:
  - `test_f27_zero_latency_telemetry_ingestion_issues_immediate_push_ack`:
    ```php
    $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);
    dispatch(new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload));
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessTelemetryPacketJob::class);
    ```
  - `test_f28_asynchronous_telemetry_worker_processes_packet_and_persists`:
    ```php
    $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');
    $job = new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
    dispatch_sync($job);
    $this->assertDatabaseHas('access_logs', [
        'device_id' => $device->device_id,
        'customize_id' => 9999,
    ]);
    ```
  - `test_f29_horizon_configuration_monitors_camera_telemetry_queue`:
    ```php
    $this->requireFile('config/horizon.php', 'Milestone 5');
    $config = config('horizon.defaults');
    $this->assertIsArray($config);
    ```
  - `test_f30_downlink_command_table_and_model_persist_tickets`:
    ```php
    $this->requireTable('device_commands', 'Milestone 5');
    $this->requireClass('App\Models\DeviceCommand', 'Milestone 5');
    $command = \App\Models\DeviceCommand::create([
        'device_id' => $device->id,
        'message_id' => 'CMD-' . uniqid(),
        'operator' => 'RebootDevice',
        'status' => 'pending',
        'payload' => ['facesluiceId' => $device->device_id],
    ]);
    $this->assertDatabaseHas('device_commands', [
        'id' => $command->id,
        'status' => 'pending',
        'operator' => 'RebootDevice',
    ]);
    ```
  - `test_f31_non_blocking_downlink_ticket_dispatch_returns_202_accepted`:
    ```php
    $this->requireMethod('App\Services\CameraMqttService', 'dispatchCommandAsync', 'Milestone 5');
    $this->requireTable('device_commands', 'Milestone 5');
    $service = app(\App\Services\CameraMqttService::class);
    $command = $service->dispatchCommandAsync($device, 'RebootDevice', []);
    $this->assertInstanceOf(\App\Models\DeviceCommand::class, $command);
    $this->assertEquals('pending', $command->status);
    ```
  - `test_f32_hardware_ack_correlation_matches_ticket_by_message_id`:
    ```php
    $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');
    $this->requireTable('device_commands', 'Milestone 5');
    $ackPacket = [
        'operator' => 'UpMQTTconfigAck',
        'messageId' => $messageId,
        'code' => 0,
        'info' => ['Result' => 0],
    ];
    $service = app(\App\Services\CameraMqttService::class);
    $service->handleCommandAck($ackPacket);
    $command->refresh();
    $this->assertEquals('completed', $command->status);
    ```
  - `test_f33_downlink_event_broadcasting_emits_command_completed_event`:
    ```php
    $this->requireClass('App\Events\DeviceCommandCompleted', 'Milestone 5');
    $this->requireTable('device_commands', 'Milestone 5');
    \Illuminate\Support\Facades\Event::fake([\App\Events\DeviceCommandCompleted::class]);
    event(new \App\Events\DeviceCommandCompleted($command));
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\DeviceCommandCompleted::class, function ($event) use ($command) {
        return $event->command->id === $command->id;
    });
    ```

- **`tests/Feature/E2E/Tier2BoundaryTest.php` (lines 566–635)**:
  - `test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed`:
    When hardware sends an ACK packet with non-zero error code (`code: 1`, `desc: 'Hardware busy, retry later'`), `CameraMqttService::handleCommandAck` must transition `$command->status` to `'failed'` (not `'completed'`).
  - `test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully`:
    When hardware sends an ACK packet with an unknown or unmatched `messageId`, `handleCommandAck` must complete gracefully without throwing any exception (`$this->assertTrue(true)`).
  - `test_boundary_telemetry_packet_with_empty_images_processes_without_crashing`:
    When telemetry payload contains null images (`'SanpPic' => null`, `'ScenePic' => null`), `ProcessTelemetryPacketJob` must execute without error and persist `access_logs` with null picture URLs.

- **`tests/Feature/E2E/Tier3CrossFeatureTest.php` (lines 280–317)**:
  - `test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets`:
    A `BulkCampaign` dispatches asynchronous `DeviceCommand` tickets with unique `messageId`s; incoming hardware ACKs correlate individually and update corresponding tickets.

- **`tests/Feature/E2E/Tier4RealWorldScenariosTest.php` (lines 358–402)**:
  - `test_scenario_10_telemetry_burst_and_async_downlink_correlation`:
    Concurrent execution of telemetry packet ingestion via `ProcessTelemetryPacketJob` and downlink command correlation via `CameraMqttService::handleCommandAck`.

### 1.3 Existing Codebase State
1. `app/Console/Commands/MqttListenCommand.php`:
   - Single-threaded event loop subscribing to `mqtt/face/#`.
   - `handleVerifyPush()` and `handleStrangerSnapPush()` decode Base64 images and execute DB queries synchronously, only sending `PushAck` at the end of execution.
   - `handleCommandAck(?string $deviceId, string $operator, array $data)` currently writes to cache keys (`mqtt_ack:{$messageId}`, `mqtt_ack:{$deviceId}:{$operator}`) but does not query `device_commands` or broadcast `DeviceCommandCompleted`.
2. `app/Services/CameraMqttService.php`:
   - Contains synchronous methods (`publishCommandAndWait`, `publishCommand`, `rebootDevice`, etc.).
   - Lacks `dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand`.
   - Lacks `handleCommandAck(array $packet): ?DeviceCommand`.
3. `app/Contracts/CameraGatewayInterface.php`:
   - Already defines `public function dispatchCommandAsync(Device $device, string $operator, array $params = []): mixed;` (line 113).
4. `config/horizon.php`:
   - Defaults only monitor `'queue' => ['default']`. Does not yet specify `'camera-telemetry'`.
5. `database/migrations/`:
   - Table `device_commands` does not exist yet.
6. `app/Models/`:
   - `DeviceCommand.php` does not exist yet.
7. `app/Jobs/`:
   - `ProcessTelemetryPacketJob.php` does not exist yet.
8. `app/Events/`:
   - `DeviceCommandCompleted.php` does not exist yet.

---

## 2. Logic Chain

1. **Telemetry Scaling Bottleneck & Two-Tier Solution**:
   - *Observation:* Edge camera firmware pushes verification frames containing heavy Base64 JPEG payloads (50KB–500KB). Under high traffic, Base64 decoding, filesystem I/O, and database transaction locking block PHP's single-threaded CLI listener loop.
   - *Logic:* To meet the <2ms response SLA and prevent broker timeout disconnections, `MqttListenCommand` must separate acknowledgement from processing.
   - *Conclusion:*
     - **Tier 1 (Daemon)**: On receiving `VerifyPush` / `RecPush` or `StrSnapPush` / `SnapPush`, parse only the `RecordID` or `SnapID`, immediately send `PushAck` to `mqtt/face/{deviceId}` (QoS 0), and dispatch `ProcessTelemetryPacketJob` to the Redis queue `camera-telemetry`.
     - **Tier 2 (Worker)**: Horizon workers pull from `camera-telemetry` to execute `ImageStorageService::storeBase64Image()`, persist to `access_logs` or `stranger_snaps`, fire `AccessLogReceived` / `StrangerSnapReceived`, and conditionally dispatch `ProcessAttendancePunchJob`.

2. **Downlink Command Blocking & Asynchronous Correlator**:
   - *Observation:* Web HTTP requests invoking camera commands (reboot, parameter sync, clock sync) currently block PHP-FPM workers for up to 5 seconds via `publishCommandAndWait()`.
   - *Logic:* Replacing this with an asynchronous ticket model decouples the web tier from hardware round-trip latencies over WAN.
   - *Conclusion:*
     - When dispatching commands, generate a unique `message_id` (e.g. `CMD-` + 12 alphanumeric characters).
     - Store in `device_commands` with `status = 'pending'`, `dispatched_at = now()`.
     - Send downlink packet to `mqtt/face/{deviceId}` with `messageId` embedded.
     - Return HTTP `202 Accepted` immediately with the ticket.
     - When camera responds on `mqtt/face/{deviceId}/Ack` or sends an ACK operator (`*-Ack`), match by `messageId` in `handleCommandAck()`.
     - If `code === 0` (or `Result === 0`), mark `'completed'`; if `code !== 0`, mark `'failed'`.
     - Store hardware response payload in `response` column and update `completed_at = now()`.
     - Broadcast `DeviceCommandCompleted` event via Laravel Reverb to notify frontend clients.

3. **Queue & Supervisor Configuration**:
   - *Observation:* `test_f29_horizon_configuration_monitors_camera_telemetry_queue` asserts that `config/horizon.php` defaults exist, while `PROJECT.md` mandates supervisor monitoring of `camera-telemetry`.
   - *Logic:* Horizon needs explicit queue configuration to provision workers for telemetry packets.
   - *Conclusion:* Add `'camera-telemetry'` to Horizon queue configuration (under `defaults.supervisor-1.queue` or a dedicated high-throughput supervisor `supervisor-telemetry`).

---

## 3. Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 27 | Telemetry Ingestion | Zero-Latency Telemetry Ingestion (Tier 1) | Daemon immediately issues MQTT `PushAck` (<2ms) and offloads raw payload into Redis queue `camera-telemetry` | Topic `mqtt/face/+/{Rec,Snap}`, Payload with `RecordID`/`SnapID`, `operator: VerifyPush/StrSnapPush` | MQTT message: `PushAck` payload to `mqtt/face/{id}`; Queued job `ProcessTelemetryPacketJob` on `camera-telemetry` | If duplicate packet received within 60s, `PushAck` is resent but job is not enqueued; If device not registered/inactive, packet dropped per SEC-13 | `PROJECT.md` #27, `system-evo.md` Area 1, `Tier1FeatureCoverageTest::test_f27` |
| 28 | Telemetry Ingestion | Asynchronous Telemetry Worker (Tier 2) | Queued job `ProcessTelemetryPacketJob` decodes Base64 images, persists PostgreSQL records, and broadcasts live events | Constructor: `deviceId`, `operator`, `payload` (JSON) | `AccessLog` or `StrangerSnap` DB record, broadcasts `AccessLogReceived` / `StrangerSnapReceived`, dispatches `ProcessAttendancePunchJob` | Null/empty images stored as null without crashing; invalid payloads logged | `PROJECT.md` #28, `system-evo.md` Area 1, `Tier1FeatureCoverageTest::test_f28`, `Tier2BoundaryTest::test_boundary_telemetry_packet_with_empty_images` |
| 29 | Queue Infrastructure | Horizon Telemetry Queue Configuration | Configures Horizon supervisor to monitor Redis queue `camera-telemetry` | `config/horizon.php` array configuration | Horizon processes allocated to `camera-telemetry` queue | Falls back to default queue if unconfigured | `PROJECT.md` #29, `system-evo.md` Area 1, `Tier1FeatureCoverageTest::test_f29` |
| 30 | Downlink Correlator | Downlink Command Table & Model | Persistence layer for tracking downlink command tickets and hardware execution states | Database migration `device_commands`, Eloquent model `DeviceCommand` | DB record with `id`, `device_id`, `message_id`, `operator`, `status`, `payload`, `response`, `dispatched_at`, `completed_at` | Database constraint violations on duplicate `message_id` | `PROJECT.md` #30, `system-evo.md` Area 2, `Tier1FeatureCoverageTest::test_f30` |
| 31 | Downlink Correlator | Non-blocking Downlink Ticket Dispatch | `CameraMqttService::dispatchCommandAsync` creates pending ticket, sends MQTT downlink, and returns `DeviceCommand` immediately | `Device $device`, `string $operator`, `array $params = []` | `DeviceCommand` model instance with `status = 'pending'`; HTTP `202 Accepted` response from controller | Inactive device throws or returns failed command; broker disconnection handled gracefully | `PROJECT.md` #31, `system-evo.md` Area 2, `Tier1FeatureCoverageTest::test_f31` |
| 32 | Downlink Correlator | Hardware ACK Correlation | `CameraMqttService::handleCommandAck` matches incoming hardware ACK packets by `messageId` and updates command ticket status | ACK packet with `messageId`, `code`, `operator`, `info` | Updated `DeviceCommand` (`status: completed` or `failed`, `completed_at`, `response`), emits `DeviceCommandCompleted` | Non-zero `code` marks ticket `'failed'`; Unknown `messageId` handled gracefully with null return (no exception) | `PROJECT.md` #32, `system-evo.md` Area 2, `Tier1FeatureCoverageTest::test_f32`, `Tier2BoundaryTest::test_boundary_hardware_ack_*` |
| 33 | Event Broadcasting | Downlink Event Broadcasting | Broadcasts `DeviceCommandCompleted` event via Laravel Reverb WebSockets when command ticket updates | `DeviceCommand $command` | WebSocket message broadcast on PrivateChannel `device-commands` with event `DeviceCommandCompleted` | Unauthorized websocket subscribers rejected | `PROJECT.md` #33, `system-evo.md` Area 2, `Tier1FeatureCoverageTest::test_f33` |

---

## 4. Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | Hardware ACK Correlation | Non-zero error return code (`code: 1`, `desc: 'Hardware busy, retry later'`) | `handleCommandAck` must transition `device_commands.status` to `'failed'` (not `'completed'`) and persist error packet in `response`. Tested by `Tier2BoundaryTest::test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed`. |
| 2 | Hardware ACK Correlation | Unknown or unmatched `messageId` (`messageId: 'NON-EXISTENT-...'`) | `handleCommandAck` returns `null` gracefully without throwing any exception or crashing daemon. Tested by `Tier2BoundaryTest::test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully`. |
| 3 | Hardware ACK Correlation | Corrupted packet missing `messageId` field | Returns `null` without error; logs debug information. |
| 4 | Asynchronous Telemetry Worker | Null or empty image strings (`SanpPic: null`, `ScenePic: null`) | `ProcessTelemetryPacketJob` processes packet smoothly without exception; stores record with `snap_pic_url: null` or empty string. Tested by `Tier2BoundaryTest::test_boundary_telemetry_packet_with_empty_images_processes_without_crashing`. |
| 5 | Telemetry Deduplication | Burst of identical `RecordID` packets within 60 seconds (hardware re-transmission) | Listener daemon replies with `PushAck` immediately to release camera retransmission, but skips queueing duplicate `ProcessTelemetryPacketJob`. |
| 6 | Bulk Fleet Correlation | Bulk fleet campaign dispatching 50 reboot commands concurrently | Each command generates a unique `message_id` and `DeviceCommand` record; incoming ACK packets correlate asynchronously without race conditions or overwriting peer tickets. Tested by `Tier3CrossFeatureTest::test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets`. |
| 7 | High-Throughput Burst Ingestion | Rapid bursts of 100+ biometric punches in succession | Zero-latency listener acknowledges each in <2ms; worker pool drains queue concurrently; `device_hb_throttle` prevents DB lockups on `devices.last_heartbeat_at`. Tested by `Tier4RealWorldScenariosTest::test_scenario_10`. |
| 8 | Unregistered Device Packet | Telemetry packet received from unregistered or deactivated camera ID | Per security finding SEC-13, telemetry packet is dropped and logged with warning; no background jobs or DB writes executed. |
| 9 | Command Timeout Handling | Downlink command dispatched but edge hardware never responds over WAN | Ticket remains in `pending` status until maintenance cleaner marks it `timeout` or `failed` after timeout threshold. |

---

## 5. Caveats

- **No implementation performed**: In accordance with Specification Miner rules, zero production code or tests were modified during this discovery turn.
- **WebSocket Channel Authorization**: The broadcast channel for `DeviceCommandCompleted` should be defined on `device-commands` or `devices.{deviceId}` in `routes/channels.php`. Authorization check should ensure user has `'devices.manage'` or `'devices.view'` permission.
- **Legacy Synchronous Route Support**: Existing endpoints like `POST /api/devices/{id}/reboot` currently return HTTP 200 in unit test suites (e.g. `HttpProtocolV113Test`). Async support must support query param `?async=1` or header `Prefer: respond-async` to return `202 Accepted` ticket without breaking legacy sync callers.

---

## 6. Conclusion

The specification for Milestone M5 is completely discovered, authoritative, and unambiguous across all 7 target features (Features #27–#33) and their boundary conditions.

### Concrete Implementation Blueprint for Milestone M5 Workers:
1. **Migration & Model (`device_commands`)**:
   - File: `database/migrations/2026_10_08_000003_create_device_commands_table.php`
   - Columns: `id`, `device_id` (foreignId to `devices.id` cascade), `message_id` (unique), `operator`, `status` (`pending`, `completed`, `failed`), `payload` (json nullable), `response` (json nullable), `dispatched_at` (timestamp nullable), `completed_at` (timestamp nullable), `timestamps()`.
   - Model: `app/Models/DeviceCommand.php` with fillable attributes, casts, relationship `device()`, and status helpers (`markCompleted`, `markFailed`).
2. **Job: `ProcessTelemetryPacketJob`**:
   - File: `app/Jobs/ProcessTelemetryPacketJob.php`
   - Queue: `'camera-telemetry'`.
   - Handles `VerifyPush` / `RecPush`, `StrSnapPush` / `SnapPush`, and AI security alarms.
   - Decodes Base64 images with null safety, creates `AccessLog` / `StrangerSnap`, dispatches `AccessLogReceived` / `StrangerSnapReceived`, and triggers `ProcessAttendancePunchJob` on `verify_status === 1`.
3. **Horizon Configuration**:
   - File: `config/horizon.php`
   - Include `'camera-telemetry'` in `defaults.supervisor-1.queue`.
4. **Service & Gateway Methods**:
   - In `app/Services/CameraMqttService.php`:
     - `dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand`: creates pending `DeviceCommand`, dispatches command via MQTT/gateway, returns model.
     - `handleCommandAck(array $packet): ?DeviceCommand`: matches by `messageId`, sets `completed` (code 0) or `failed` (code != 0), updates `response` and `completed_at`, fires `DeviceCommandCompleted`, returns model. Handles unknown `messageId` gracefully without exception.
5. **Event & Channels**:
   - File: `app/Events/DeviceCommandCompleted.php`
   - Implements `ShouldBroadcast`, channel `PrivateChannel('device-commands')`.
   - Channel authorized in `routes/channels.php`.
6. **MqttListenCommand Refactor**:
   - In `MqttListenCommand::handleVerifyPush` and `handleStrangerSnapPush`: send `PushAck` immediately (<2ms), enqueue `ProcessTelemetryPacketJob` to `camera-telemetry`.
   - In `MqttListenCommand::handleCommandAck`: delegate to `$this->cameraService->handleCommandAck($data)`.
7. **Controller Async Support**:
   - In `DeviceController`, return `202 Accepted` with command ticket when `async` is requested.

---

## 7. Verification Method

To verify the implementation independently, run the following commands:

```bash
# 1. Milestone 5 Tier 1 Feature Tests (Features 27 through 33)
php artisan test --filter=test_f27
php artisan test --filter=test_f28
php artisan test --filter=test_f29
php artisan test --filter=test_f30
php artisan test --filter=test_f31
php artisan test --filter=test_f32
php artisan test --filter=test_f33

# 2. Milestone 5 Tier 2 Boundary Tests
php artisan test --filter=test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed
php artisan test --filter=test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully
php artisan test --filter=test_boundary_telemetry_packet_with_empty_images_processes_without_crashing

# 3. Milestone 5 Tier 3 Cross-Feature Test
php artisan test --filter=test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets

# 4. Milestone 5 Tier 4 Real-World Scenario Test
php artisan test --filter=test_scenario_10_telemetry_burst_and_async_downlink_correlation

# 5. Combined Milestone 5 Test Execution
php artisan test --filter="test_f2[7-9]|test_f3[0-3]|test_boundary_hardware_ack|test_boundary_telemetry_packet|test_cross_bulk_fleet_campaign|test_scenario_10"
```

### Invalidation Conditions:
- Any test in Tiers 1–4 skipping due to missing `DeviceCommand`, `ProcessTelemetryPacketJob`, `device_commands` table, or missing methods on `CameraMqttService`.
- Non-zero error ACK code failing to mark command ticket as `'failed'`.
- Telemetry packet with null/empty image causing 500 error or crash.
- `PushAck` sent synchronously after slow disk storage operations instead of immediately.
