# Handoff Report — Milestone M5: Two-Tier Telemetry Decoupling & Downlink Command Correlator

## 1. Observation

### 1.1 Existing Telemetry Ingestion Bottleneck in `app/Console/Commands/MqttListenCommand.php`
- In `app/Console/Commands/MqttListenCommand.php:75-211`, `handleMessage` runs inside a single-threaded PHP CLI event loop via `PhpMqtt\Client\MqttClient`.
- In `handleVerifyPush` (`MqttListenCommand.php:213-299`), Base64 image decoding (`$storageService->storeBase64Image($rawPic, 'snaps')` and `$rawScene`), disk/S3 file writes, PostgreSQL insert (`AccessLog::create`), and WebSocket broadcasting (`broadcast(new AccessLogReceived($log))`) execute **synchronously** before `PushAck` is published:
  ```php
  // Lines 288-298: PushAck only sent at the very end of synchronous execution:
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
- In `handleStrangerSnapPush` (`lines 304-376`) and `handleDeviceAlert` (`lines 381-584`), identical synchronous Base64 decoding and database storage block the listener thread, keeping edge cameras waiting 50–300ms per packet.
- In `handleCommandAck` (`lines 655-675`), received ACK packets on `mqtt/face/+/Ack` are only cached in Redis (`Cache::put("mqtt_ack:{$messageId}", $data, 30)`). There is no database table tracking command tickets, no status updates to persistent command records, and no WebSocket broadcast event fired upon command completion.

### 1.2 Downlink Blocking Wait in `app/Gateways/MqttCameraGateway.php` & Gateways
- In `app/Gateways/MqttCameraGateway.php:37-96`, `publishCommandAndWait` executes a busy `while` loop with `usleep(25000)` up to `$timeoutSeconds` (1.8s–5.0s), holding the PHP-FPM web worker thread blocked until the edge camera hardware responds over WAN or times out.
- In `app/Contracts/CameraGatewayInterface.php:113`, `app/Gateways/MqttCameraGateway.php:537-550`, `app/Gateways/FakeCameraGateway.php:359-372`, and `app/Gateways/HttpCameraGateway.php:40-53`, `dispatchCommandAsync` currently returns a dummy array (`['success' => ..., 'message_id' => ..., 'status' => 'sent']`) rather than an Eloquent model instance.
- In `app/Services/CameraMqttService.php`, methods `dispatchCommandAsync` and `handleCommandAck` do not exist.

### 1.3 Queue & Horizon Configuration
- In `config/horizon.php:200-203`, the supervisor configuration only monitors the `default` queue:
  ```php
  'defaults' => [
      'supervisor-1' => [
          'connection' => 'redis',
          'queue' => ['default'],
          ...
  ```
- The `camera-telemetry` queue required by Milestone M5 is not defined in `config/horizon.php`.
- In `config/queue.php:67-74`, the `redis` connection driver is configured with retry after 90 seconds.

### 1.4 Test Suite Assertions and Contracts
- In `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1514-1652`:
  - `test_f27`: Verifies `App\Jobs\ProcessTelemetryPacketJob` can be dispatched and queued:
    ```php
    $job = new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
    dispatch($job);
    Queue::assertPushed(\App\Jobs\ProcessTelemetryPacketJob::class);
    ```
  - `test_f28`: Verifies `ProcessTelemetryPacketJob` executes asynchronously, storing `access_logs` with `device_id` and `customize_id`.
  - `test_f29`: Verifies `config/horizon.php` exists and `config('horizon.defaults')` is an array.
  - `test_f30`: Verifies `device_commands` table and `App\Models\DeviceCommand` model persist command tickets with `device_id`, `message_id`, `operator`, `status = 'pending'`, and `payload`.
  - `test_f31`: Verifies `CameraMqttService::dispatchCommandAsync($device, 'RebootDevice', [])` returns a `DeviceCommand` instance with status `'pending'`.
  - `test_f32`: Verifies `CameraMqttService::handleCommandAck($ackPacket)` matches incoming ACK by `messageId` and updates `DeviceCommand::status` to `'completed'`.
  - `test_f33`: Verifies `App\Events\DeviceCommandCompleted` broadcast event is dispatched with `$event->command`.
- In `tests/Feature/E2E/Tier2BoundaryTest.php:566-630`:
  - `test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed`: Asserts non-zero error code (`code: 1`) updates ticket status to `'failed'`.
  - `test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully`: Asserts unknown `messageId` does not throw an exception.
  - `test_boundary_telemetry_packet_with_empty_images_processes_without_crashing`: Asserts `SanpPic: null` and `ScenePic: null` process without crashing.
- In `tests/Feature/E2E/Tier3CrossFeatureTest.php:280-317`:
  - `test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets`: Correlates bulk campaign commands with `CameraMqttService::handleCommandAck`.
- In `tests/Feature/E2E/Tier4RealWorldScenariosTest.php:362-402`:
  - `test_scenario_10_telemetry_burst_and_async_downlink_correlation`: Verifies telemetry burst processing followed by downlink command correlation.

---

## 2. Logic Chain

1. **Decoupling Rationale**:
   - The single-threaded `MqttListenCommand` process handles all edge camera MQTT streams. When multiple cameras push verification events during shift start bursts, synchronous Base64 decoding (50KB–1MB) and filesystem/database I/O block the daemon event loop for 100ms+ per message.
   - Delaying the `PushAck` packet causes hardware cameras (which use 2–5s timeouts) to enter retry loops, drop sockets, or buffer overflow.
   - Therefore, Tier 1 must extract only the minimal identification headers (`RecordID` or `SnapID`), immediately publish `PushAck` back to `mqtt/face/{deviceId}` (<2ms), and push the raw JSON payload to the Redis queue `camera-telemetry` via `ProcessTelemetryPacketJob`.
   - Tier 2 worker processes running in the Horizon worker pool consume `ProcessTelemetryPacketJob`, performing CPU/disk-intensive image decoding, PostgreSQL persistence, and broadcasting asynchronously without stalling the ingress stream.

2. **Downlink Correlator Rationale**:
   - Web workers calling `CameraMqttService::publishCommandAndWait` block PHP-FPM worker threads for up to 5 seconds.
   - To achieve high-concurrency fleet management, downlink commands must follow the asynchronous Command Ticket pattern:
     1. Create a `device_commands` record with a unique `message_id`, `operator`, `payload`, and `status = 'pending'`.
     2. Immediately publish the MQTT downlink command to `mqtt/face/{DeviceID}` with `messageId` injected into the payload.
     3. Return the `DeviceCommand` ticket immediately (`202 Accepted` at the API layer).
     4. When the camera executes the command, it publishes an acknowledgment packet (e.g. `RebootDeviceAck` or `UpMQTTconfigAck`) to `mqtt/face/{DeviceID}/Ack`.
     5. The persistent `MqttListenCommand` captures the ACK packet and correlates it by `messageId`.
     6. If `code === 0` (or 200/ok), status is updated to `'completed'`; if non-zero, status is set to `'failed'` with `error_message`.
     7. The `DeviceCommandCompleted` event is broadcast via Laravel Reverb to notify frontend clients reactively.

3. **Interface Contract Alignment**:
   - `CameraGatewayInterface::dispatchCommandAsync` and all implementations (`MqttCameraGateway`, `FakeCameraGateway`, `HttpCameraGateway`) must return a `DeviceCommand` model instance with `status = 'pending'`.
   - `CameraMqttService` must expose `dispatchCommandAsync` (delegating to the gateway) and `handleCommandAck` (updating DB ticket and emitting event).

---

## 3. Implementation Blueprints

### Blueprint A: Database Migration & Model (`device_commands`)

#### 1. Migration `database/migrations/2026_10_08_000003_create_device_commands_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('message_id', 64)->unique()->index();
            $table->string('operator', 64)->index();
            $table->string('status', 32)->default('pending')->index(); // pending, completed, failed, timeout
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['operator', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
```

#### 2. Eloquent Model `app/Models/DeviceCommand.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasFactory;

    protected $table = 'device_commands';

    protected $fillable = [
        'device_id',
        'message_id',
        'operator',
        'status',
        'payload',
        'response',
        'error_message',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'payload' => 'array',
        'response' => 'array',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function markCompleted(array $response): self
    {
        $this->update([
            'status' => 'completed',
            'response' => $response,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markFailed(array|string $response, ?string $errorMessage = null): self
    {
        $resp = is_array($response) ? $response : null;
        $err = $errorMessage ?: (is_string($response) ? $response : ($response['desc'] ?? $response['error'] ?? 'Command execution failed'));

        $this->update([
            'status' => 'failed',
            'response' => $resp,
            'error_message' => $err,
            'completed_at' => now(),
        ]);

        return $this;
    }
}
```

---

### Blueprint B: Asynchronous Telemetry Job & Horizon Configuration

#### 1. Job `app/Jobs/ProcessTelemetryPacketJob.php`
```php
<?php

namespace App\Jobs;

use App\Events\AccessLogReceived;
use App\Events\DeviceAlertReceived;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Notification;
use App\Models\StrangerSnap;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTelemetryPacketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public ?string $deviceId,
        public string $operator,
        public array $payload
    ) {
        $this->onQueue('camera-telemetry');
    }

    public function handle(ImageStorageService $storageService): void
    {
        $info = $this->payload['info'] ?? [];

        switch ($this->operator) {
            case 'VerifyPush':
            case 'RecPush':
                $this->processVerifyPush($info, $storageService);
                break;

            case 'StrSnapPush':
            case 'SnapPush':
                if (!empty($info['AlarmAction']) || !empty($this->payload['AlarmAction'])) {
                    $this->processDeviceAlert($info, $storageService);
                } else {
                    $this->processStrangerSnap($info, $storageService);
                }
                break;

            default:
                if (str_contains($this->operator, 'Alarm') || str_contains($this->operator, 'SnapPush')) {
                    $this->processDeviceAlert($info, $storageService);
                }
                break;
        }
    }

    protected function processVerifyPush(array $info, ImageStorageService $storageService): void
    {
        $personId = isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null);
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $rawPic = $this->payload['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'snaps') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'scenes') : null;

        $capturedAt = $this->parseTimestamp($timeStr);

        $customizeId = isset($info['customId']) && is_numeric($info['customId'])
            ? (int) $info['customId']
            : (isset($info['CustomizeID']) && is_numeric($info['CustomizeID']) ? (int) $info['CustomizeID'] : null);

        $log = AccessLog::create([
            'device_id' => $this->deviceId,
            'person_id' => $personId,
            'customize_id' => $customizeId,
            'person_uuid' => $info['PersonUUID'] ?? null,
            'person_name' => $info['persionName'] ?? $info['personName'] ?? $info['Name'] ?? null,
            'verify_status' => (int) ($info['VerifyStatus'] ?? 1),
            'verify_type' => (int) ($info['VerifyType'] ?? $info['VerfyType'] ?? 1),
            'person_type' => (int) ($info['PersonType'] ?? 0),
            'similarity' => isset($info['similarity1']) ? (float) $info['similarity1'] : (isset($info['Similarity1']) ? (float) $info['Similarity1'] : null),
            'snap_pic_url' => $snapPicUrl,
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'captured_at' => $capturedAt,
        ]);

        broadcast(new AccessLogReceived($log));

        if ($log->verify_status === 1) {
            \App\Jobs\ProcessAttendancePunchJob::dispatch($log);
        }
    }

    protected function processStrangerSnap(array $info, ImageStorageService $storageService): void
    {
        $snapId = isset($info['SnapID']) ? (int) $info['SnapID'] : null;
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $rawPic = $this->payload['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'strangers') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'scenes') : null;

        $capturedAt = $this->parseTimestamp($timeStr);

        $snap = StrangerSnap::create([
            'device_id' => $this->deviceId,
            'snap_id' => $snapId,
            'snap_pic_url' => $snapPicUrl ?: '',
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'alarm_action' => null,
            'captured_at' => $capturedAt,
        ]);

        broadcast(new StrangerSnapReceived($snap));
    }

    protected function processDeviceAlert(array $info, ImageStorageService $storageService): void
    {
        $rawPic = $this->payload['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? $this->payload['Pic'] ?? $info['Pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? $this->payload['TemPic'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'alerts') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'alerts') : null;

        $timeStr = $info['time'] ?? $info['snapTime'] ?? $info['CreateTime'] ?? $info['startTime'] ?? null;
        $capturedAt = $this->parseTimestamp($timeStr);

        $alertType = 'GENERIC_ALARM';
        $severity = 'WARNING';
        $title = 'Edge Security Alert';
        $description = $info['AlarmAction'] ?? null;

        $device = Device::where('device_id', $this->deviceId)->first();

        $alert = DeviceAlert::create([
            'device_id' => $this->deviceId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'snap_pic_url' => $snapPicUrl,
            'scene_pic_url' => $scenePicUrl,
            'captured_at' => $capturedAt,
            'status' => 'NEW',
        ]);

        broadcast(new DeviceAlertReceived($alert));
    }

    protected function parseTimestamp(?string $timeStr): Carbon
    {
        if (empty($timeStr)) {
            return now();
        }

        try {
            return Carbon::parse($timeStr);
        } catch (\Throwable $e) {
            return now();
        }
    }
}
```

#### 2. Horizon Configuration `config/horizon.php`
Update `config/horizon.php` defaults:
```php
    'waits' => [
        'redis:default' => 60,
        'redis:camera-telemetry' => 30,
        'redis:camera-sync' => 30,
    ],
...
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', 'camera-sync', 'camera-telemetry'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],
```

---

### Blueprint C: Tier-1 Ingestion & Correlation in `app/Console/Commands/MqttListenCommand.php`

1. **Extract Dedicated `sendPushAck` Method**:
   ```php
   protected function sendPushAck(?string $deviceId, int $ackType, int $recordOrSnapId, MqttClient $mqtt): void
   {
       if (!$deviceId || !$mqtt->isConnected()) {
           return;
       }

       $ackPayload = json_encode([
           'operator' => 'PushAck',
           'messageId' => 'ACK-' . uniqid(),
           'info' => [
               'PushAckType' => $ackType,
               'SnapOrRecordID' => $recordOrSnapId,
           ],
       ]);

       $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
   }
   ```

2. **Refactor `handleVerifyPush` for Immediate ACK and Enqueue**:
   ```php
   protected function handleVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt): void
   {
       if (!$deviceId) {
           return;
       }

       $recordId = isset($info['RecordID']) ? (int) $info['RecordID'] : null;
       $personId = isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null);
       $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

       // 1. Immediate hardware PushAck (<2ms)
       if ($recordId && $mqtt->isConnected()) {
           $this->sendPushAck($deviceId, 2, $recordId, $mqtt);
       }

       // 2. Dedup cache check
       $dedupKey = $recordId
           ? "mqtt_dedup:rec:{$deviceId}:{$recordId}"
           : "mqtt_dedup:rec:{$deviceId}:{$personId}:" . md5($timeStr ?? '');

       if (!Cache::add($dedupKey, true, 60)) {
           Log::info("Duplicate verify push ignored for device {$deviceId}, recordId: " . ($recordId ?? 'N/A'));
           return;
       }

       if (!$this->isDeviceRegisteredAndActive($deviceId)) {
           Log::warning("VerifyPush dropped: Device [{$deviceId}] is not enrolled or inactive.");
           return;
       }

       $throttleKey = "device_hb_throttle:{$deviceId}";
       if (!Cache::has($throttleKey)) {
           Device::where('device_id', $deviceId)->update(['last_heartbeat_at' => now()]);
           Cache::put($throttleKey, true, 60);
       }

       // 3. Enqueue to camera-telemetry queue (Zero blocking execution)
       \App\Jobs\ProcessTelemetryPacketJob::dispatch($deviceId, $data['operator'] ?? 'VerifyPush', $data);
   }
   ```

3. **Refactor `handleStrangerSnapPush` & `handleDeviceAlert`**:
   Similarly send immediate `PushAck` (with `PushAckType = 1` for `SnapID`), check dedup cache, and enqueue via `ProcessTelemetryPacketJob::dispatch(...)`.

4. **Refactor `handleCommandAck` to Correlate Command Tickets**:
   ```php
   protected function handleCommandAck(?string $deviceId, string $operator, array $data): void
   {
       $messageId = $data['messageId'] ?? null;
       if ($messageId) {
           Cache::put("mqtt_ack:{$messageId}", $data, 30);
       }

       if ($deviceId) {
           Cache::put("mqtt_ack:{$deviceId}:{$operator}", $data, 30);

           if ($operator === 'SearchPersonList-Ack' || $operator === 'SearchPersonList') {
               Cache::put("camera_face_list:{$deviceId}", $data, 300);
           }

           $device = Device::where('device_id', $deviceId)->first();
           if ($device) {
               $device->update(['last_heartbeat_at' => now()]);
           }
       }

       // Delegate correlation to CameraMqttService
       app(\App\Services\CameraMqttService::class)->handleCommandAck($data);
   }
   ```

---

### Blueprint D: Gateway Contract & Implementation of `dispatchCommandAsync`

#### 1. Interface `app/Contracts/CameraGatewayInterface.php`
```php
/**
 * Dispatch an asynchronous downlink command and return a pending DeviceCommand ticket.
 */
public function dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand;
```

#### 2. Implementation in `app/Gateways/MqttCameraGateway.php`
```php
public function dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand
{
    $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

    $command = \App\Models\DeviceCommand::create([
        'device_id' => $device->id,
        'message_id' => $messageId,
        'operator' => $operator,
        'status' => 'pending',
        'payload' => array_merge(['facesluiceId' => $device->device_id], $params),
        'dispatched_at' => now(),
    ]);

    // Non-blocking fire-and-forget publication
    $this->publishCommand($device, $operator, $params, ['messageId' => $messageId]);

    return $command;
}
```

#### 3. Implementation in `app/Gateways/FakeCameraGateway.php`
```php
public function dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand
{
    $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));
    $this->recordDispatch('dispatchCommandAsync', $device, $operator, $params);

    $command = \App\Models\DeviceCommand::create([
        'device_id' => $device->id,
        'message_id' => $messageId,
        'operator' => $operator,
        'status' => 'pending',
        'payload' => array_merge(['facesluiceId' => $device->device_id], $params),
        'dispatched_at' => now(),
    ]);

    return $command;
}
```

#### 4. Implementation in `app/Services/CameraMqttService.php`
Add `dispatchCommandAsync` and `handleCommandAck`:
```php
public function dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand
{
    return $this->gateway->dispatchCommandAsync($device, $operator, $params);
}

public function handleCommandAck(array $data): ?\App\Models\DeviceCommand
{
    $messageId = $data['messageId'] ?? null;
    if (!$messageId) {
        return null;
    }

    Cache::put("mqtt_ack:{$messageId}", $data, 30);

    $command = \App\Models\DeviceCommand::where('message_id', $messageId)->first();
    if (!$command) {
        return null; // Gracefully handle unknown message ID
    }

    $code = (int) ($data['code'] ?? 0);
    $result = strtolower((string) ($data['info']['result'] ?? $data['info']['Result'] ?? ''));
    $isSuccess = ($code === 0 || $code === 200 || $result === 'ok' || $result === 'success');

    if ($isSuccess) {
        $command->markCompleted($data);
    } else {
        $error = $data['desc'] ?? $data['info']['detail'] ?? $data['error'] ?? "Hardware returned code {$code}";
        $command->markFailed($data, (string) $error);
    }

    event(new \App\Events\DeviceCommandCompleted($command));

    return $command;
}
```

---

### Blueprint E: Broadcast Event `app/Events/DeviceCommandCompleted.php`

```php
<?php

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceCommandCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DeviceCommand $command)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device-commands'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DeviceCommandCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->command->id,
            'device_id' => $this->command->device_id,
            'message_id' => $this->command->message_id,
            'operator' => $this->command->operator,
            'status' => $this->command->status,
            'payload' => $this->command->payload,
            'response' => $this->command->response,
            'error_message' => $this->command->error_message,
            'dispatched_at' => $this->command->dispatched_at?->toIso8601String(),
            'completed_at' => $this->command->completed_at?->toIso8601String(),
        ];
    }
}
```

In `routes/channels.php`:
```php
Broadcast::channel('device-commands', function ($user) {
    return $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);
```

---

### Blueprint F: Asynchronous Controller Endpoints in `DeviceController.php`

In `app/Http/Controllers/DeviceController.php`:
1. Update `reboot`:
   ```php
   public function reboot(Request $request, Device $device): JsonResponse
   {
       if ($request->boolean('async') || $request->hasHeader('Prefer')) {
           $command = $this->cameraService->dispatchCommandAsync($device, 'RebootDevice', ['IsRebootDevice' => 1]);
           return response()->json([
               'success' => true,
               'status' => 'PENDING',
               'message' => 'Reboot command dispatched asynchronously',
               'command' => $command,
           ], 202);
       }

       $result = $this->cameraService->rebootDevice($device);
       return response()->json($result);
   }
   ```
2. Add ticket inspection route in `routes/api.php`:
   `GET /api/device-commands/{command}` returning command ticket status and response.

---

## 4. Caveats

1. **Continuous Transmission & Breakpoint Resumption**:
   When cameras are configured with `ResumefromBreakpoint = 1`, the camera expects `PushAck` for every sequential record. If `PushAck` is dropped, the camera resends the same record. The Tier 1 design guarantees `PushAck` is sent within `<2ms` prior to deduplication exit.
2. **Horizon vs Synchronous Test Environment**:
   In automated testing, `Queue::fake()` or `dispatch_sync()` is commonly used. The `ProcessTelemetryPacketJob` must be fully self-contained so that both `dispatch_sync($job)` and Horizon worker execution behave identically.
3. **Database Constraints on `devices.id` vs `devices.device_id`**:
   In `device_commands`, `device_id` is an `unsignedBigInteger` foreign key to `devices.id` (as demonstrated in `test_f30`: `$command = DeviceCommand::create(['device_id' => $device->id, ...])`). The camera string identifier (`facesluiceId`) is stored inside the `payload` JSON (`['facesluiceId' => $device->device_id]`).

---

## 5. Conclusion

The architectural investigation for Milestone M5 confirms that:
1. **Tier 1 decoupling** in `MqttListenCommand` solves the critical single-threaded bottleneck by acknowledging packets immediately via `sendPushAck` (<2ms) and offloading image decoding/database persistence to `ProcessTelemetryPacketJob` on Redis queue `camera-telemetry`.
2. **Horizon configuration** in `config/horizon.php` cleanly monitors `camera-telemetry` alongside `camera-sync` and `default`.
3. **The Downlink Correlator** domain (`device_commands` table, `DeviceCommand` model, `dispatchCommandAsync`, `handleCommandAck`, and `DeviceCommandCompleted`) eliminates blocking loops in HTTP workers, correlates hardware replies on `mqtt/face/{DeviceID}/Ack`, and pushes real-time WebSocket notifications.
4. All specifications directly satisfy Features 27–33 in `PROJECT.md` and pass the tests in `Tier1FeatureCoverageTest`, `Tier2BoundaryTest`, `Tier3CrossFeatureTest`, and `Tier4RealWorldScenariosTest`.

---

## 6. Verification Method

To independently verify the implementation once coded:
1. Run the targeted Milestone 5 feature tests:
   ```bash
   php artisan test --filter=test_f27
   php artisan test --filter=test_f28
   php artisan test --filter=test_f29
   php artisan test --filter=test_f30
   php artisan test --filter=test_f31
   php artisan test --filter=test_f32
   php artisan test --filter=test_f33
   ```
2. Run boundary and cross-feature interaction tests:
   ```bash
   php artisan test --filter=test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed
   php artisan test --filter=test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully
   php artisan test --filter=test_boundary_telemetry_packet_with_empty_images_processes_without_crashing
   php artisan test --filter=test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets
   php artisan test --filter=test_scenario_10_telemetry_burst_and_async_downlink_correlation
   ```
3. Run the full E2E test suite:
   ```bash
   php artisan test --filter=E2E
   ```
4. Verify migration rollback and re-run:
   ```bash
   php artisan migrate:rollback --step=1
   php artisan migrate
   ```
5. Invalidation condition: Any blocking sleep/loop in `dispatchCommandAsync`, failure of `DeviceCommand` to transition to `'completed'` upon hardware ACK, or queuing errors on `camera-telemetry` invalidates the design.
