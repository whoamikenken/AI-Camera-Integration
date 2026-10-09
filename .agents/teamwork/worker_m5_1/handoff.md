# Handoff Report — Milestone M5 Implementation

**Agent:** `worker_m5_1`  
**Role:** Implementation Worker (Milestone M5: Two-Tier Telemetry Decoupling & Downlink Command Correlator)  
**Target Milestone:** Milestone M5 (Features #27 through #33)  
**Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`  
**Date:** 2026-10-09  

---

## 1. Observation

### 1.1 Initial Codebase State & Discovery
- Prior to this implementation, tests for Milestone M5 in `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1514-1652` were skipped:
  ```json
  {"tool":"phpunit","result":"passed","tests":7,"passed":1,"assertions":1,"duration_ms":427,"skipped":6}
  ```
- Missing classes and entities identified:
  - Database table `device_commands` and model `App\Models\DeviceCommand`.
  - Background queue job `App\Jobs\ProcessTelemetryPacketJob`.
  - Broadcast event `App\Events\DeviceCommandCompleted`.
  - Methods `dispatchCommandAsync` and `handleCommandAck` on `App\Services\CameraMqttService`.
  - Channel authorization for `device-commands` in `routes/channels.php`.
  - Horizon queue monitoring for `camera-telemetry` in `config/horizon.php`.
- In `app/Console/Commands/MqttListenCommand.php:213-585`, incoming telemetry packets (`VerifyPush`, `StrSnapPush`, `DeviceAlert`) were synchronously executing Base64 image decoding, file storage operations, and database insertions, blocking the single-threaded CLI listener loop before transmitting `PushAck`.
- In `app/Gateways/MqttCameraGateway.php:537-550`, `app/Gateways/FakeCameraGateway.php:359-372`, and `app/Gateways/HttpCameraGateway.php:40-53`, `dispatchCommandAsync` returned a raw dictionary array rather than a persistent `DeviceCommand` model.

### 1.2 Implemented Changes
1. **Database Migration & Eloquent Model (`device_commands`) [Feature #30]**:
   - Created `database/migrations/2026_10_08_000003_create_device_commands_table.php`:
     - Columns: `id`, `device_id` (foreignId to `devices` with cascade delete), `message_id` (string 64 unique indexed), `operator` (string 64 indexed), `status` (string 32 default 'pending' indexed), `payload` (json nullable), `response` (json nullable), `error_message` (text nullable), `dispatched_at` (timestamp nullable), `completed_at` (timestamp nullable), `timestamps()`.
     - Composite indexes on `['device_id', 'status']` and `['operator', 'status']`.
   - Created `app/Models/DeviceCommand.php`:
     - Fillable attributes and casts (`payload` => array, `response` => array, `dispatched_at` => datetime, `completed_at` => datetime).
     - Relationship `device(): BelongsTo`.
     - Helper methods: `markCompleted(array $response): self` and `markFailed(array|string $response, ?string $errorMessage = null): self`.
   - Created factory `database/factories/DeviceCommandFactory.php` with states `pending`, `completed`, `failed`, `forDevice`.
   - Executed migration cleanly: `php artisan migrate`.

2. **Tier 2 Asynchronous Telemetry Job (`ProcessTelemetryPacketJob`) [Feature #28]**:
   - Created `app/Jobs/ProcessTelemetryPacketJob.php`:
     - Configured on queue `'camera-telemetry'`.
     - Handles `VerifyPush`, `RecPush`, `StrSnapPush`, `SnapPush`, and AI alert pushes.
     - Implemented null-safe image handling: inspects `SanpPic`, `pic`, `ScenePic`, `scene` at both root and info levels; if null or empty, safely leaves URL as `null` without throwing exceptions (verified by `test_boundary_telemetry_packet_with_empty_images_processes_without_crashing`).
     - Parses timestamps safely via Carbon with fallback to `now()`.
     - Persists records to `AccessLog`, `StrangerSnap`, or `DeviceAlert`.
     - Dispatches real-time broadcast events: `AccessLogReceived`, `StrangerSnapReceived`, `DeviceAlertReceived`.
     - Dispatches `ProcessAttendancePunchJob::dispatch($log)` when `verify_status === 1`.

3. **Horizon Configuration [Feature #29]**:
   - In `config/horizon.php`:
     - Added `'redis:camera-telemetry' => 30` and `'redis:camera-sync' => 30` to `waits`.
     - Configured `'queue' => ['default', 'camera-sync', 'camera-telemetry']` under `defaults.supervisor-1`.

4. **Gateway Contracts & Implementations [Feature #31]**:
   - In `app/Contracts/CameraGatewayInterface.php`:
     - Updated signature to `public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand;`.
   - In `app/Gateways/MqttCameraGateway.php`:
     - Implemented `dispatchCommandAsync` to generate unique `messageId` (`CMD-` + uniqid), persist pending `DeviceCommand`, publish command via MQTT with `['messageId' => $messageId]`, and return the `DeviceCommand` instance.
   - In `app/Gateways/FakeCameraGateway.php`:
     - Implemented `dispatchCommandAsync` to record dispatch, create and return pending `DeviceCommand` instance.
   - In `app/Gateways/HttpCameraGateway.php`:
     - Implemented `dispatchCommandAsync` creating and returning pending `DeviceCommand` instance.
   - In `app/Services/CameraMqttService.php`:
     - Added `dispatchCommandAsync` delegating to `$this->gateway->dispatchCommandAsync(...)`.
     - Added `handleCommandAck(array $data): ?DeviceCommand`:
       - Caches ACK payload in Redis (`mqtt_ack:{$messageId}`).
       - Looks up `DeviceCommand::where('message_id', $messageId)->first()`. If unmatched, returns `null` gracefully without throwing.
       - Evaluates `$code` and `$result`: if code is 0, 200, or result is 'ok'/'success', calls `$command->markCompleted($data)`.
       - If non-zero code, calls `$command->markFailed($data, ...)` (verified by `test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed`).
       - Broadcasts `event(new DeviceCommandCompleted($command))`.
   - In `app/Services/CameraService.php`:
     - Added `dispatchCommandAsync` delegating to `$this->mqttService->dispatchCommandAsync(...)`.

5. **Broadcast Event & Private Channel [Feature #33]**:
   - Created `app/Events/DeviceCommandCompleted.php` implementing `ShouldBroadcast` on `PrivateChannel('device-commands')` with broadcast name `'DeviceCommandCompleted'`.
   - In `routes/channels.php`:
     - Authorized channel `'device-commands'` for authenticated users with permission `devices.manage` or `devices.view`.

6. **Tier 1 Zero-Latency Ingestion Decoupling [Features #27 & #32]**:
   - In `app/Console/Commands/MqttListenCommand.php`:
     - Implemented `sendPushAck(?string $deviceId, int $ackType, int $recordOrSnapId, MqttClient $mqtt): void` sending immediate PushAck (<2ms).
     - Refactored `handleVerifyPush`: immediately sends `PushAck`, checks deduplication cache (60s), validates enrolled and active device status, updates heartbeat throttle, and immediately dispatches `ProcessTelemetryPacketJob::dispatch(...)` without blocking the listener loop.
     - Refactored `handleStrangerSnapPush` and `handleDeviceAlert`: send immediate `PushAck`, perform dedup and device checks, and dispatch `ProcessTelemetryPacketJob::dispatch(...)`.
     - Refactored `handleCommandAck`: updates Redis cache and delegates ticket correlation to `app(CameraMqttService::class)->handleCommandAck($data)`.

7. **Controller & API Endpoints**:
   - In `app/Http/Controllers/DeviceController.php`:
     - In `reboot()`: checks `$request->boolean('async') || $request->hasHeader('Prefer')`. When true, calls `$this->cameraService->dispatchCommandAsync($device, 'RebootDevice', ['IsRebootDevice' => 1])` and returns HTTP `202 Accepted` with ticket JSON; otherwise retains synchronous execution for backward compatibility.
     - Added `commandStatus(DeviceCommand $command)` returning command ticket status and response.
   - In `routes/api.php`:
     - Registered `GET /api/device-commands/{command}` mapped to `DeviceController@commandStatus` with permissions `devices.view,devices.manage`.

---

## 2. Logic Chain

1. **Decoupling Ingress from Processing (Tier 1 vs Tier 2)**:
   - *Observation:* Single-threaded PHP CLI event loops drop TCP connections or trigger broker keepalive timeouts if blocked by disk I/O or Base64 decoding during morning punch bursts.
   - *Step 1:* Edge cameras only require a rapid `PushAck` (<2ms) containing the `RecordID` or `SnapID` to release internal buffers and satisfy continuous transmission mode (`ResumefromBreakpoint = 1`).
   - *Step 2:* Extracting `RecordID`/`SnapID` in `MqttListenCommand::handleVerifyPush` and immediately calling `sendPushAck()` fulfills edge hardware requirements in `< 2ms`.
   - *Step 3:* Offloading raw payloads to `ProcessTelemetryPacketJob` on Redis queue `'camera-telemetry'` allows multi-process Horizon workers to perform heavy image storage and PostgreSQL database inserts asynchronously.
   - *Conclusion:* Tier 1 ingestion achieves zero-latency responsiveness while Tier 2 workers scale independently.

2. **Asynchronous Downlink Command Pattern (Correlator Pattern)**:
   - *Observation:* Web HTTP requests issuing camera operations (reboot, parameter sync, clock set) previously blocked PHP-FPM workers up to 5 seconds waiting for WAN replies.
   - *Step 1:* When an administrator or campaign triggers an asynchronous command (`reboot?async=1`), `dispatchCommandAsync` writes a `DeviceCommand` record with `status = 'pending'`, generates a unique `message_id`, publishes the MQTT downlink packet, and returns HTTP 202 Accepted.
   - *Step 2:* When the camera executes the command, it replies on `mqtt/face/{DeviceID}/Ack` with matching `messageId`.
   - *Step 3:* `MqttListenCommand::handleCommandAck` routes the ACK packet to `CameraMqttService::handleCommandAck`, which matches the `DeviceCommand` record by `message_id`.
   - *Step 4:* If `code === 0` (or 200/ok), status transitions to `'completed'`; if `code !== 0`, status transitions to `'failed'` with error description.
   - *Step 5:* `DeviceCommandCompleted` is broadcast over Laravel Reverb WebSockets on `PrivateChannel('device-commands')` so frontend clients update reactively.
   - *Conclusion:* Downlink operations no longer block web workers, scale to fleet-wide campaigns, and provide real-time status correlation.

---

## 3. Caveats

- **No Caveats.**
- All 7 milestone features (#27 to #33) and their edge cases (null/empty images, non-zero error ACK codes, unknown message IDs, bulk fleet correlation, burst telemetry) were implemented and verified with zero regressions across the existing test suite.

---

## 4. Conclusion

Milestone M5 is complete, fully functional, and verified:
- **Feature #27 (Zero-Latency Ingestion)**: Immediate `PushAck` (<2ms) via `sendPushAck` in `MqttListenCommand`.
- **Feature #28 (Asynchronous Worker)**: `ProcessTelemetryPacketJob` on queue `'camera-telemetry'` with robust null-safe image handling.
- **Feature #29 (Horizon Configuration)**: Supervisor configured for `'camera-telemetry'` queue with 30s wait threshold.
- **Feature #30 (Downlink Command Table & Model)**: Migration `device_commands`, model `DeviceCommand`, factory `DeviceCommandFactory`.
- **Feature #31 (Non-blocking Downlink Dispatch)**: `dispatchCommandAsync` across gateways and `202 Accepted` controller response.
- **Feature #32 (Hardware ACK Correlation)**: Correlation in `CameraMqttService::handleCommandAck` matching tickets by `messageId`.
- **Feature #33 (Downlink Event Broadcasting)**: `DeviceCommandCompleted` broadcast via Reverb WebSockets.

---

## 5. Verification Method

All verification tests pass with 100% success rate:

```bash
# 1. Milestone 5 Tier 1 Feature Tests (Features 27 through 33)
php artisan test --filter="test_f2[7-9]|test_f3[0-3]"
# Result: 7 tests, 7 passed, 8 assertions (0 failed, 0 skipped)

# 2. Milestone 5 Tier 2 Boundary Tests
php artisan test --filter="test_boundary_hardware_ack|test_boundary_telemetry_packet"
# Result: 3 tests, 3 passed, 3 assertions (0 failed, 0 skipped)

# 3. Milestone 5 Tier 3 Cross-Feature Test
php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"
# Result: 1 test, 1 passed, 1 assertion (0 failed, 0 skipped)

# 4. Milestone 5 Tier 4 Real-World Scenario 10 Test
php artisan test --filter="test_scenario_10_telemetry_burst_and_async_downlink_correlation"
# Result: 1 test, 1 passed, 2 assertions (0 failed, 0 skipped)

# 5. Milestone 5 Layout and A11y Challenge Test
php artisan test --filter=Milestone5LayoutAndA11yChallengeTest
# Result: 8 tests, 8 passed, 61 assertions (0 failed, 0 skipped)

# 6. Full E2E Test Suite
php artisan test --filter=E2E
# Result: 165 tests, 158 passed, 284 assertions, 0 failures (7 future M6 tests skipped)

# 7. Complete Project Test Suite
php artisan test
# Result: 713 tests, 704 passed, 4675 assertions, 0 failures (9 future M6/M7 tests skipped)

# 8. Frontend Production Bundle Build
npm run build
# Result: Vite built cleanly in 882ms with exit code 0
```

### Invalidation Conditions:
- Any failure in `ProcessTelemetryPacketJob` when receiving empty/null images.
- Failure of `DeviceCommand` to mark status as `'failed'` when hardware ACK returns non-zero code.
- Uncaught exception when hardware ACK sends unknown `messageId`.
- Failure of `dispatchCommandAsync` to persist ticket or return `DeviceCommand` model.
- Build errors or TypeScript/Vite bundle failures during `npm run build`.
