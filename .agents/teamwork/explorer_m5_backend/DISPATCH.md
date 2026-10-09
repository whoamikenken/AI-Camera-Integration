# DISPATCH DIRECTIVE — explorer_m5_backend

## Identity
- Archetype: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Investigate the existing MQTT daemon, queue worker pool, downlink command service, and hardware ACK handling for Milestone M5 (Two-Tier Telemetry Decoupling & Downlink Command Correlator).

## Authoritative Inputs
Read the following authoritative documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 1 & 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Features 27–33, Milestone M5)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md`

## Exploration Areas
1. **Existing Telemetry Ingestion (`app/Console/Commands/MqttListenCommand.php`)**:
   - Inspect `handleMessage`, `handleVerifyPush`, `handleStrangerPush`, `handleHeartbeat`, `sendPushAck`.
   - Identify where synchronous Base64 decoding, image storage, and database inserts currently occur.
   - Design Tier 1 non-blocking path: immediate `sendPushAck` (<2ms) and enqueue raw payload to `camera-telemetry` Redis queue via `ProcessTelemetryPacketJob::dispatch(...)`.
   - Inspect hardware command ACK handling: how `mqtt/face/{DeviceID}/Ack` is subscribed to and how `handleCommandAck` matches `messageId` against `device_commands`.
2. **Asynchronous Telemetry Worker (`app/Jobs/ProcessTelemetryPacketJob.php`)**:
   - Design `ProcessTelemetryPacketJob`: receives topic and payload.
   - Routes `VerifyPush` -> decodes images, stores to disk/S3, creates `AccessLog`, dispatches `AccessLogReceived`, triggers `AttendancePunchReceived`.
   - Routes `StrSnapPush` -> creates `StrangerSnap`, dispatches `StrangerSnapReceived`.
3. **Downlink Command Correlator Domain**:
   - Design migration `create_device_commands_table.php` and model `DeviceCommand.php`.
   - Design `CameraGatewayInterface::dispatchCommandAsync` and implementations on `MqttCameraGateway`, `FakeCameraGateway`, and `HttpCameraGateway`.
   - Design `DeviceCommandCompleted` broadcast event on private/public channel for Laravel Reverb.
4. **Horizon & Queue Configuration**:
   - Inspect `config/horizon.php` and `config/queue.php`.
   - Ensure queue `camera-telemetry` is defined and monitored.
5. **Controllers & Endpoints**:
   - Inspect `DeviceController.php` for asynchronous command dispatch endpoints (`dispatchAsync`, `rebootAsync`, or parameter command ticket endpoints).

Write your report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend/handoff.md`
When complete, notify parent via `send_message`.


## 2026-10-08T23:59:55Z
You are explorer_m5_backend.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Areas 1 & 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Investigate the existing MQTT daemon, queue worker pool, downlink command service, and hardware ACK handling:
1. Inspect app/Console/Commands/MqttListenCommand.php (handleMessage, handleVerifyPush, handleStrangerPush, handleCommandAck, sendPushAck).
2. Design ProcessTelemetryPacketJob on queue 'camera-telemetry' and Horizon configuration in config/horizon.php.
3. Design database migration create_device_commands_table and model DeviceCommand.
4. Design CameraGatewayInterface::dispatchCommandAsync and implementations across gateways.
5. Design DeviceCommandCompleted broadcast event and correlation logic in MqttListenCommand matching incoming *-Ack by messageId.

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m5_backend/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message.
