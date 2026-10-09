# DISPATCH — Worker M1: Testing Harness & Gateway Decoupling

## Objective
Implement Milestone 1: Complete Testing Harness & Gateway Decoupling as specified in `PROJECT.md` and `explorer_survey_2/analysis.md`:
1. Create 10 comprehensive Eloquent Model Factories in `database/factories/` with expressive states:
   - `DeviceFactory.php` (states: online, offline, entryRole, exitRole, bidirectional, kiosk, withLocation)
   - `PersonnelFactory.php` (states: whitelist, blacklist, temporary, permanent)
   - `EmployeeFactory.php` (states: active, inactive, withShift, withDepartment, withPersonnel)
   - `ShiftFactory.php` (states: standard, overnight, flexible)
   - `AttendancePunchFactory.php` (states: in, out, approved, biometric)
   - `VisitorFactory.php` (states: blocked, approved)
   - `VisitFactory.php` (states: expected, checkedIn, checkedOut, cancelled, overstayed)
   - `LeaveRequestFactory.php` (states: pending, approved, rejected, cancelled)
   - `LeaveTypeFactory.php` (states: paid, unpaid, annual, sick)
   - `DepartmentFactory.php` & `LocationFactory.php`
2. Create `CameraGatewayInterface` contract in `app/Contracts/CameraGatewayInterface.php`.
3. Create implementations:
   - `MqttCameraGateway.php` in `app/Gateways/`
   - `HttpCameraGateway.php` in `app/Gateways/`
   - `FakeCameraGateway.php` in `app/Gateways/` with fluent assertions (`CameraGateway::fake()`)
4. Register container binding in `app/Providers/AppServiceProvider.php`.
5. Remove all 4 `app()->environment('testing')` conditionals in `app/Services/CameraMqttService.php` (lines 60, 201, 458, 586), delegating hardware publishing through `CameraGatewayInterface`.
6. Write feature test `tests/Feature/CameraGatewayAndFactoriesTest.php` asserting all factories produce valid models and states, and `CameraGateway::fake()` accurately intercepts and asserts downlink commands.
7. Run `php artisan test` and ensure all tests pass with zero failures.

## Mandatory Files to Read First
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (read header ## 2026-10-07T01:57:58Z)
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8/PROJECT.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/analysis.md`

## File Ownership
You exclusively own:
- `database/factories/*`
- `app/Contracts/CameraGatewayInterface.php`
- `app/Gateways/*`
- `app/Providers/AppServiceProvider.php`
- `app/Services/CameraMqttService.php`
- `tests/Feature/CameraGatewayAndFactoriesTest.php`

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Completion Criteria
1. All factories and gateway classes implemented genuinely.
2. All 4 `app()->environment('testing')` blocks completely removed from `CameraMqttService.php`.
3. `php artisan test` executes cleanly with 0 failures.
4. Detailed report in `handoff.md` with test execution evidence.
5. Notify parent via `send_message`.


## 2026-10-07T02:14:44Z
From: parent (b7d1e4eb-1a56-481f-aa27-2fac5acd5ff8)
Message:
You are worker_m1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m1
Your instructions are in: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m1/DISPATCH.md

You MUST read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (specifically the user request under header ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_2/analysis.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m1/DISPATCH.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Implement Milestone 1: Eloquent model factories with expressive states, CameraGatewayInterface, implementations (Mqtt, Http, Fake with fluent assertions), remove all 4 app()->environment('testing') branches in CameraMqttService.php, verify that php artisan test runs cleanly with zero failures.
Produce a full handoff report in handoff.md with test execution evidence.
Notify parent via send_message.
