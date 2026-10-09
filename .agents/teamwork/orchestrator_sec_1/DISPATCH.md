# DISPATCH DIRECTIVE — Orchestrator Security Remediation (SEC-11 to SEC-19)

## Mission
Remediate active security findings SEC-11 through SEC-19 documented in `tasks-security.md` across the Intelligent AI Camera Hub codebase, ensuring authorization integrity, edge input sanitization, dependency safety, and automated test regression coverage.

## Authoritative User Request
Refer to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (section `## 2026-10-07T01:46:02Z`) and `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`.

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_sec_1`

## State of Codebase & Prior Work
1. **Milestone 1 (SEC-11, SEC-12, SEC-14) is IMPLEMENTED**:
   - **SEC-11**: `EmployeeController::attendanceSummary` checks user permissions and roles (`employees.manage`, `employees.view`, `attendance.view`, manager roles) or restricts non-managers strictly to `$user->employee?->id === (int) $id`, returning HTTP 403 on mismatch.
   - **SEC-12**: `NotificationCreated` broadcasts to `notifications.{userId}` and `routes/channels.php` verifies `$user->id === (int) $userId`.
   - **SEC-14**: `AuthenticateQueryToken` deprecated; `routes/api.php` media route requires signed URL or authenticated Sanctum user; `ImageStorageService` generates temporary signed routes; `media.js` uses signed URLs without query tokens.
2. **Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19) — READY FOR WORKER**:
   - Reference detailed blueprint in `.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md`.
   - **SEC-13**: In `MqttListenCommand.php`, reject rogue/unregistered devices (do not auto-create active devices or ingest unauthorized telemetry). In `start-dev.sh`, disable insecure public WAN MQTT tunneling (`bore`) by default or require explicit opt-in.
   - **SEC-15**: In `PersonnelController.php`, restrict biometric photo upload MIME types to raster formats (`image/jpeg`, `image/png`, `image/webp`), explicitly disallowing SVG / preventing stored XSS.
   - **SEC-16**: Enforce consistent SSRF address validation on `photo_path` / URL parameters in `PersonnelController.php`, blocking loopback (`127.0.0.1`), private RFC1918 ranges, and cloud metadata (`169.254.169.254`).
   - **SEC-19**: In `HttpWebhookController.php`, prevent reverse-proxy loopback IP authentication bypass unless in permitted local/testing environments with configured trusted proxies.
3. **Milestone 3 (SEC-17, SEC-18) — READY FOR WORKER**:
   - Reference detailed blueprint in `.agents/teamwork/teamwork_preview_explorer_survey_3/handoff.md`.
   - **SEC-17**: In `SecurityHeaders.php`, tighten Content-Security-Policy directives (restrict script evaluation and wildcards in `connect-src` and `img-src`).
   - **SEC-18**: Update known vulnerable npm and Composer upstream dependencies (`@vue/server-renderer`, `concurrently`, `laravel/framework`) and verify with `composer audit` and `npm audit`.
4. **Milestone 4 (Comprehensive Verification & Tasks Documentation)**:
   - Run tests: `tests/Feature/SecurityRemediationTest.php`, `tests/Feature/SecurityAdversarialGateTest.php`, `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php`, and full suite: `php artisan test`.
   - Build frontend: `npm run build`.
   - Update `tasks-security.md` to mark SEC-11 through SEC-19 completed (`[x]`).

## Deliverables
- Fully remediated SEC-11 through SEC-19.
- Clean pass on all tests (`php artisan test`).
- Clean build (`npm run build`).
- Updated `tasks-security.md`.
- Final completion handoff report.


## 2026-10-07T06:52:28Z
[Message] sender=80b5b368-09ca-4bfb-b8e2-4d6154e89c27 priority=MESSAGE_PRIORITY_HIGH
You are the Project Orchestrator for Security Remediation (SEC-11 through SEC-19) in Intelligent AI Camera Hub (AI-Camera-Integration).

Your assigned working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_sec_1

Read your dispatch directive:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_sec_1/DISPATCH.md

Authoritative user request:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (Section ## 2026-10-07T01:46:02Z)
Task specification:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md (SEC-11 through SEC-19)

Prior blueprints available in:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md (SEC-13, 15, 16, 19)
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3/handoff.md (SEC-17, 18)

Key Status:
- Milestone 1 (SEC-11, SEC-12, SEC-14) is ALREADY IMPLEMENTED in code.
- Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19) is ready to be dispatched to workers.
- Milestone 3 (SEC-17, SEC-18) is ready to be dispatched to workers.
- Milestone 4: Comprehensive test verification (php artisan test), frontend build (npm run build), adversarial checks, and updating tasks-security.md.

Execute the milestones, monitor subagents, track progress in progress.md, ensure all tests pass with zero regressions, and notify me with your full completion handoff when done.


## 2026-10-08T00:45:47Z
[Message] sender=80b5b368-09ca-4bfb-b8e2-4d6154e89c27 priority=MESSAGE_PRIORITY_HIGH
Sentinel liveness check: Your progress.md has not been updated since 07:40:20Z. Please provide a status update on Milestone 3 (SEC-17, SEC-18) and Milestone 4 (test suite, build, tasks-security.md).
