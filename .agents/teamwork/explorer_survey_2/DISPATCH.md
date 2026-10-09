# DISPATCH — Explorer Survey 2: Telemetry Ingestion, Downlink Correlator & Test Harness

## Objective
Investigate and map the full specification, current codebase implementation, and gap analysis for:
- **R4: Two-Tier High-Throughput Telemetry Ingestion** (Area 1 in `system-evo.md`):
  - Inspect `app/Console/Commands/MqttListenCommand.php`: how it currently handles `VerifyPush`, `StrSnapPush`, `HeartBeat`, image decoding, `AccessLog::create`, `PushAck`.
  - Design the decoupling: immediate `PushAck` (<2ms) and enqueuing raw payload into Redis queue (`camera-telemetry`).
  - Design `ProcessTelemetryPacketJob`: background image decoding (`ImageStorageService`), DB persistence, Reverb event broadcasting (`AccessLogReceived`).
  - Horizon configuration and Redis queue setup.
- **R5: Asynchronous Downlink Command Correlator** (Area 2 in `system-evo.md`):
  - Inspect `app/Services/CameraMqttService.php` (`publishCommandAndWait`) and `DeviceController.php`.
  - Design `device_commands` table migration (`id`, `device_id`, `message_id`, `operator`, `status`, `response`, timestamps).
  - Immediate `202 Accepted` response with command ticket ID.
  - Ingestion ACK correlation in `MqttListenCommand` matching incoming `*-Ack` packets by `messageId`, updating status to `completed`, and broadcasting `DeviceCommandCompleted` via Reverb.
- **R6: Complete Testing Harness & Gateway Decoupling** (Area 3 in `system-evo.md`):
  - Audit existing factories in `database/factories/` and test setup patterns in `tests/Feature/`.
  - Detail required factories: `DeviceFactory`, `EmployeeFactory`, `PersonnelFactory`, `ShiftFactory`, `AttendancePunchFactory`, `VisitorFactory`, `VisitFactory` with expressive states.
  - Inspect hardcoded `app()->environment('testing')` occurrences across `app/Services/CameraMqttService.php` and any other services.
  - Design `CameraGatewayInterface`, `MqttCameraGateway`, `HttpCameraGateway`, and `FakeCameraGateway` with fluent mocking to completely eliminate `app()->environment('testing')` conditionals in production code.

## Authoritative Inputs
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (read header ## 2026-10-07T01:57:58Z)
- `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`
- Existing codebase in `app/Console/Commands/`, `app/Services/`, `database/factories/`, `tests/Feature/`.

## Output Requirements
Write your detailed report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/analysis.md`
and write your handoff to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/handoff.md`.
Enumerate all required features, concrete files to create/modify, existing code patterns, dependencies, and risk areas.
Communicate completion back to orchestrator via `send_message`.


## 2026-10-07T02:01:59Z
You are explorer_survey_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2
Your task instructions are in: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/DISPATCH.md
You MUST read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (specifically the user request under header ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/DISPATCH.md

Investigate the codebase and authoritative specifications for R4 (Two-Tier High-Throughput Telemetry Ingestion), R5 (Asynchronous Downlink Command Correlator), and R6 (Complete Testing Harness & Gateway Decoupling).
Inspect MqttListenCommand, CameraMqttService, existing factories in database/factories, tests in tests/Feature/, etc.
Produce a comprehensive analysis in analysis.md and a self-contained handoff in handoff.md in your working directory.
Communicate completion back to caller via send_message.
