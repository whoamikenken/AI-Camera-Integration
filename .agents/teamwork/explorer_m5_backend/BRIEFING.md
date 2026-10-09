# BRIEFING — 2026-10-09T08:06:00Z

## Mission
Investigate and design Milestone M5: Two-Tier Telemetry Decoupling & Downlink Command Correlator for AI-Camera-Integration.

## 🔒 My Identity
- Archetype: explorer
- Roles: investigator, analyzer, architect
- Working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend
- Original parent: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb
- Milestone: M5 (Two-Tier Telemetry Decoupling & Downlink Command Correlator)

## 🔒 Key Constraints
- Read-only investigation — do NOT implement
- Inspect existing MQTT daemon, queue worker pool, downlink command service, and hardware ACK handling
- Design ProcessTelemetryPacketJob, Horizon config, DeviceCommand migration/model, CameraGatewayInterface::dispatchCommandAsync, DeviceCommandCompleted event
- Produce structured 5-component handoff report in handoff.md

## Current Parent
- Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb
- Updated: 2026-10-09T08:06:00Z

## Investigation State
- **Explored paths**:
  - `ORIGINAL_REQUEST.md`, `system-evo.md` (Areas 1 & 2), `orchestrator_11/PROJECT.md` (Features 27-33), `TEST_READY.md`
  - `app/Console/Commands/MqttListenCommand.php`
  - `app/Services/CameraMqttService.php`, `CameraService.php`
  - `app/Contracts/CameraGatewayInterface.php`, `app/Gateways/` (`MqttCameraGateway`, `FakeCameraGateway`, `HttpCameraGateway`, `CameraGateway`)
  - `config/horizon.php`, `config/queue.php`
  - `app/Http/Controllers/DeviceController.php`, `HttpWebhookController.php`
  - `tests/Feature/E2E/` (`Tier1FeatureCoverageTest.php`, `Tier2BoundaryTest.php`, `Tier3CrossFeatureTest.php`, `Tier4RealWorldScenariosTest.php`)
- **Key findings**:
  1. `MqttListenCommand` currently blocks event loop synchronously for image decoding, file I/O, database writes, and broadcasting before sending `PushAck` at the end. Needs Tier-1 immediate `<2ms` `PushAck` and raw packet dispatch to `camera-telemetry` queue.
  2. `ProcessTelemetryPacketJob` constructor signature is `(string $deviceId, string $operator, array $payload)`. It executes on `camera-telemetry` queue, handles image decoding, DB storage, `AccessLogReceived` / `StrangerSnapReceived` broadcasting, and punch job dispatch.
  3. `config/horizon.php` currently monitors only `default` queue. Needs `'camera-telemetry'` and `'camera-sync'` in `supervisor-1` queues and wait times.
  4. `DeviceCommand` migration `create_device_commands_table` requires `id`, `device_id` (FK devices), `message_id` (unique), `operator`, `status` (default 'pending'), `payload` (json), `response` (json), `error_message` (text), `dispatched_at`, `completed_at`, `timestamps`.
  5. `CameraGatewayInterface::dispatchCommandAsync` and `CameraMqttService::dispatchCommandAsync` must return `DeviceCommand` instances with status `'pending'`.
  6. Correlation method `handleCommandAck(array $data)` on `CameraMqttService` matches `messageId`, sets status to `'completed'` or `'failed'`, and dispatches `DeviceCommandCompleted`. `MqttListenCommand` delegates to this method upon receiving ACK packets on `mqtt/face/{DeviceID}/Ack`.
- **Unexplored areas**: None. All requirements, contracts, and test assertions analyzed.

## Key Decisions Made
- Architecture defined for Tier 1 (<2ms PushAck + Redis enqueue) and Tier 2 (ProcessTelemetryPacketJob).
- Schema defined for `device_commands` and model `DeviceCommand`.
- Lifecycle defined for `dispatchCommandAsync`, hardware ACK correlation, and `DeviceCommandCompleted` event broadcasting.

## Artifact Index
- DISPATCH.md — Dispatch directive
- BRIEFING.md — Working memory
- progress.md — Liveness heartbeat
- handoff.md — 5-component handoff report
