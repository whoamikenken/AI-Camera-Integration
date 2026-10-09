# DISPATCH DIRECTIVE — worker_m5_1

## Identity
- **Agent:** `worker_m5_1`
- **Role:** Implementation Worker (Milestone M5: Two-Tier Telemetry Decoupling & Downlink Command Correlator)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Mandatory Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

---

## Authoritative Inputs to Read First
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 1 & 2: Telemetry Pipeline Decoupling and Asynchronous Downlink Command Pattern)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M5: Features #27 through #33)
4. Explorer Handoff Reports:
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1/handoff.md`
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend/handoff.md`

---

## Tasks to Implement

### 1. Database Migration & Eloquent Model (`device_commands`) [Feature #30]
- Create migration `database/migrations/2026_10_08_000003_create_device_commands_table.php`:
  - Columns: `id` (bigIncrements), `device_id` (foreignId to `devices.id` cascadeOnDelete), `message_id` (string 64 unique indexed), `operator` (string 64 indexed), `status` (string 32 default 'pending' indexed), `payload` (json nullable), `response` (json nullable), `error_message` (text nullable), `dispatched_at` (timestamp nullable), `completed_at` (timestamp nullable), `timestamps()`.
  - Composite indexes on `['device_id', 'status']` and `['operator', 'status']`.
- Create model `app/Models/DeviceCommand.php`:
  - Fillable attributes, casts (`payload` => array, `response` => array, `dispatched_at` => datetime, `completed_at` => datetime).
  - Relationship `public function device(): BelongsTo`.
  - Status helper methods: `markCompleted(array $response): self` and `markFailed(array|string $response, ?string $errorMessage = null): self`.
- Create factory `database/factories/DeviceCommandFactory.php` with states for `pending`, `completed`, and `failed`.
- Run migrations: `php artisan migrate`.

### 2. Tier 2 Asynchronous Telemetry Job (`ProcessTelemetryPacketJob`) [Feature #28]
- Create job `app/Jobs/ProcessTelemetryPacketJob.php`:
  - Constructor: `public function __construct(public ?string $deviceId, public string $operator, public array $payload)`
  - Configured queue: `$this->onQueue('camera-telemetry');`
  - In `handle(ImageStorageService $storageService)`:
    - Support operators: `VerifyPush`, `RecPush`, `StrSnapPush`, `SnapPush`, and alert push events.
    - Robust image decoding: inspect `SanpPic`, `pic`, `ScenePic`, `scene`. If null or empty, safely store `null` without throwing exceptions (satisfying `Tier2BoundaryTest::test_boundary_telemetry_packet_with_empty_images_processes_without_crashing`).
    - Parse timestamps safely via Carbon with fallback to `now()`.
    - Create `AccessLog` (for VerifyPush/RecPush) or `StrangerSnap` (for StrSnapPush/SnapPush) or `DeviceAlert`.
    - Broadcast events: `AccessLogReceived`, `StrangerSnapReceived`, or `DeviceAlertReceived`.
    - Trigger `ProcessAttendancePunchJob::dispatch($log)` when `verify_status === 1`.

### 3. Horizon Queue Configuration [Feature #29]
- Update `config/horizon.php`:
  - In `defaults.supervisor-1.queue`, include `'camera-telemetry'` alongside `'default'` and `'camera-sync'`.
  - In `waits`, configure `'redis:camera-telemetry' => 30`.

### 4. Downlink Gateway Contracts & Implementations [Feature #31]
- In `app/Contracts/CameraGatewayInterface.php`:
  - Update `dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand;`
- In `app/Gateways/MqttCameraGateway.php`:
  - Implement `dispatchCommandAsync` to generate unique `messageId` (`CMD-` + uniqid), persist pending `DeviceCommand`, call `publishCommand($device, $operator, $params, ['messageId' => $messageId])`, and return the `DeviceCommand` instance.
- In `app/Gateways/FakeCameraGateway.php`:
  - Implement `dispatchCommandAsync` to record dispatch, create and return pending `DeviceCommand` instance.
- In `app/Gateways/HttpCameraGateway.php`:
  - Implement `dispatchCommandAsync` returning pending `DeviceCommand` instance.
- In `app/Services/CameraMqttService.php`:
  - Implement `dispatchCommandAsync(Device $device, string $operator, array $params = []): \App\Models\DeviceCommand` delegating to `$this->gateway->dispatchCommandAsync(...)`.
  - Implement `handleCommandAck(array $data): ?\App\Models\DeviceCommand`:
    - Cache in Redis (`mqtt_ack:{$messageId}`).
    - Find `DeviceCommand::where('message_id', $messageId)->first()`. If null, return `null` gracefully (satisfying `Tier2BoundaryTest::test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully`).
    - Check `$code = (int) ($data['code'] ?? 0);` and `$result`.
    - If success (`$code === 0` or `$code === 200` or `$result === 'ok'`), call `$command->markCompleted($data)`.
    - If non-zero code, call `$command->markFailed($data, ...)` (satisfying `Tier2BoundaryTest::test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed`).
    - Dispatch `event(new \App\Events\DeviceCommandCompleted($command))`.
    - Return `$command`.

### 5. Downlink Completed Broadcast Event [Feature #33]
- Create `app/Events/DeviceCommandCompleted.php`:
  - Implements `ShouldBroadcast`.
  - Broadcasts on `new PrivateChannel('device-commands')`.
  - Broadcast name: `'DeviceCommandCompleted'`.
  - Serializes `id`, `device_id`, `message_id`, `operator`, `status`, `payload`, `response`, `error_message`, `dispatched_at`, `completed_at`.
- In `routes/channels.php`:
  - Authorize `device-commands` channel for authenticated users.

### 6. Tier 1 Daemon Ingestion Decoupling (`MqttListenCommand.php`) [Feature #27 & #32]
- In `app/Console/Commands/MqttListenCommand.php`:
  - Add `sendPushAck(?string $deviceId, int $ackType, int $recordOrSnapId, MqttClient $mqtt): void`.
  - In `handleVerifyPush`:
    - If `RecordID` present and connected, immediately send `PushAck` (<2ms).
    - Check dedup cache (60s) and active device enrollment.
    - Update heartbeat throttle.
    - Immediately dispatch `ProcessTelemetryPacketJob::dispatch($deviceId, $data['operator'] ?? 'VerifyPush', $data);` (zero synchronous Base64 decoding or disk I/O in listener thread).
  - In `handleStrangerSnapPush` and `handleDeviceAlert`:
    - Send immediate `PushAck` (with `PushAckType = 1` for `SnapID`), check dedup cache, and dispatch `ProcessTelemetryPacketJob::dispatch(...)`.
  - In `handleCommandAck`:
    - Cache in Redis as before.
    - Delegate correlation to `app(\App\Services\CameraMqttService::class)->handleCommandAck($data);`.

### 7. Controller & API Support
- In `app/Http/Controllers/DeviceController.php`:
  - Update `reboot`: if `$request->boolean('async')` or has header `'Prefer'`, call `$this->cameraService->dispatchCommandAsync($device, 'RebootDevice', ['IsRebootDevice' => 1])` and return `response()->json(['success' => true, 'status' => 'PENDING', 'command' => $command], 202)`.
  - Add `commandStatus(DeviceCommand $command)` endpoint returning `$command`.
- In `routes/api.php`:
  - Register route `GET /api/device-commands/{command}`.

---

## Verification Commands
Execute the following verification commands and record outputs in your handoff report:
1. `php artisan test --filter="test_f2[7-9]|test_f3[0-3]"`
2. `php artisan test --filter="test_boundary_hardware_ack|test_boundary_telemetry_packet"`
3. `php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"`
4. `php artisan test --filter="test_scenario_10_telemetry_burst_and_async_downlink_correlation"`
5. `php artisan test --filter=E2E`
6. `php artisan test` (Verify 100% pass across all 700+ tests, 0 failures!)
7. `npm run build` (Verify clean bundle compilation with exit code 0)

---

## Output Requirements
Write your detailed report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md`.
Notify parent (`340b2ee2-86ac-4ca7-9f71-8c1542c65adb`) via `send_message`.
