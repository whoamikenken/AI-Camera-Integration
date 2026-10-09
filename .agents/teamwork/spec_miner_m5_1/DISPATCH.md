# DISPATCH DIRECTIVE — spec_miner_m5_1

## Identity
- Archetype: teamwork_preview_spec_miner
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Extract exhaustive behavioral specifications, schema requirements, queue naming conventions, event broadcasting contracts, and test assertions for Milestone M5 (Two-Tier Telemetry Decoupling & Downlink Command Correlator: Features #27 through #33).

## Authoritative Inputs
Read the following authoritative documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 1 & 2: Telemetry Pipeline Decoupling and Asynchronous Downlink Command Pattern)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Features 27–33, Milestone M5)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (Milestone 5 section)
5. `/home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md`
6. Inspect the test suites:
   - `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (`test_f27` through `test_f33`)
   - `tests/Feature/E2E/Tier2BoundaryTest.php`
   - `tests/Feature/E2E/Tier3CrossFeatureTest.php`
   - `tests/Feature/E2E/Tier4RealWorldScenariosTest.php` (`test_scenario_10`)

## Investigation Requirements
1. **Tier 1 Zero-Latency Telemetry (Feature #27)**:
   - How `MqttListenCommand` immediately publishes `PushAck` (<2ms) upon receiving `VerifyPush` / `StrSnapPush`.
   - Redis queue contract: exact connection and queue name (`camera-telemetry`).
2. **Tier 2 Asynchronous Telemetry Job (Feature #28 & #29)**:
   - Job class `app/Jobs/ProcessTelemetryPacketJob.php`.
   - Handling of `VerifyPush` (Base64 decode -> `ImageStorageService` -> `AccessLog::create` -> `AccessLogReceived` broadcast).
   - Handling of `StrSnapPush` (`StrangerSnap::create` -> `StrangerSnapReceived` broadcast).
   - `config/horizon.php` queue configuration for `camera-telemetry`.
3. **Downlink Command Table & Model (Feature #30)**:
   - Migration `create_device_commands_table`: table name `device_commands`, columns (`id`, `device_id`, `message_id`, `operator`, `status`, `payload`, `response`, `dispatched_at`, `completed_at`, `timestamps`).
   - Eloquent model `app/Models/DeviceCommand.php`.
4. **Asynchronous Command Ticket Dispatch (Feature #31)**:
   - `CameraGatewayInterface::dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand`.
   - `CameraMqttService::dispatchCommandAsync`.
   - Endpoint responses returning HTTP `202 Accepted` with command ticket.
5. **Hardware ACK Correlation & Event Broadcasting (Features #32 & #33)**:
   - Incoming packet on `mqtt/face/{DeviceID}/Ack`.
   - Correlation by `messageId` updating `device_commands` record from `'pending'` to `'completed'` or `'failed'`.
   - Event `app/Events/DeviceCommandCompleted.php` broadcast over WebSockets (Laravel Reverb / Pusher protocol).

Write your findings to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1/handoff.md`
When complete, notify parent via `send_message`.


## 2026-10-08T23:59:55Z
You are spec_miner_m5_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Areas 1 & 2: Telemetry Pipeline Decoupling and Asynchronous Downlink Command Pattern)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M5, Features #27-#33)
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md (Milestone 5 section)
5. /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md
6. Inspect the test suites:
   - tests/Feature/E2E/Tier1FeatureCoverageTest.php (test_f27 through test_f33)
   - tests/Feature/E2E/Tier2BoundaryTest.php
   - tests/Feature/E2E/Tier3CrossFeatureTest.php
   - tests/Feature/E2E/Tier4RealWorldScenariosTest.php (test_scenario_10: telemetry burst and async downlink correlation)

Extract exhaustive behavioral specifications, states, columns, routes, payloads, queue names ('camera-telemetry'), event classes (DeviceCommandCompleted), and error conditions.
Write your findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m5_1/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message with a summary and link to handoff.md.
