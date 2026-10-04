## 2026-10-01T05:59:23Z
You are worker_sec_1, a Security Implementation Specialist subagent.
Your working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_sec_1
Project root: /home/wsk-devops2/AI-Camera-Integration

MANDATORY FIRST STEP:
Read the authoritative user request at /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md.
Also read:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_security_1/handoff.md (Contains exhaustive code blueprints, exact line numbers, and implementation details for SEC-01 through SEC-15)
- /home/wsk-devops2/AI-Camera-Integration/tasks-security.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_2/SCOPE.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your exclusive write boundaries:
You own backend security, controller, model, route, configuration, and event files:
- `routes/api.php`, `routes/web.php`, `routes/channels.php`
- `app/Http/Controllers/HttpWebhookController.php`
- `app/Models/Device.php`
- `app/Http/Controllers/DeviceController.php`
- `app/Console/Commands/MqttListenCommand.php`
- `app/Services/CameraMqttService.php`
- `app/Events/AccessLogReceived.php`, `app/Events/AttendancePunchReceived.php`, `app/Events/DeviceAlertReceived.php`, `app/Events/DeviceAlertUpdated.php`, `app/Events/DeviceStatusUpdated.php`, `app/Events/NotificationCreated.php`, `app/Events/PersonnelUpdated.php`, `app/Events/StrangerSnapReceived.php`, `app/Events/SyncTaskUpdated.php`, `app/Events/VisitorCheckedIn.php`, `app/Events/VisitorCheckedOut.php`
- `app/Services/ImageStorageService.php`
- `app/Http/Controllers/PersonnelController.php`
- `app/Http/Controllers/DeviceAlertController.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/LeaveController.php`
- `app/Http/Controllers/RegularizationController.php`
- `app/Http/Controllers/VisitorController.php`
- `app/Http/Controllers/AttendanceController.php`
- `app/Services/CameraHttpService.php`
- `config/sanctum.php` (create from template in survey)
- `routes/console.php` (schedule sanctum:prune-expired)
- `app/Http/Controllers/EmployeeController.php`, `app/Http/Controllers/PayrollExportController.php`, `app/Http/Controllers/ReportController.php` (CSV formula sanitization)
- `bootstrap/app.php` (Security headers and API rate limiting)
- `.env.example` (Blank out APP_KEY)
- `start-dev.sh` (Remove or secure bore.pub insecure tunnel)
- You may also write or update PHPUnit feature/security tests in `tests/Feature/` to verify security fixes.

Do NOT modify any files in `resources/js/` (those are owned exclusively by worker_a11y_1).

Your mission:
Implement all 15 security remediation tasks (SEC-01 through SEC-15) following the blueprints in `survey_security_1/handoff.md`:
1. SEC-01: Protect `/Subscribe/*` webhooks in `HttpWebhookController` and routes. Enforce API token/secret verification (e.g. `X-Camera-Secret` or camera credential matching), apply `throttle:60,1`, block untrusted device auto-creation (`firstOrCreate` -> pre-enrolled camera check), validate base64 size/dimensions.
2. SEC-02: Protect camera hardware passwords: add `'password'` to `$hidden` and cast as `'encrypted'` in `Device.php`. Remove cleartext exposure in `DeviceController::index()` and `show()`.
3. SEC-03: WAN MQTT transport security: support TLS in `MqttListenCommand` and `CameraMqttService` via configurable TLS options and broker auth. Clean `start-dev.sh`.
4. SEC-04: Convert all 11 Reverb event classes in `app/Events/` from `new Channel(...)` to `new PrivateChannel(...)`. Add authorization callbacks in `routes/channels.php` for `access-logs`, `device-alerts`, `stranger-snaps`, `attendance`, `visitors`, etc. checking RBAC permissions.
5. SEC-05: Remediate SSRF in `ImageStorageService::storeFromUrlOrPath` and `PersonnelController`: validate scheme (http/https), resolve host to IPs, reject private/reserved IPs (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.169.254`, `::1`).
6. SEC-06: Attach permission middleware to `device-alerts` in `routes/api.php` (`permission:devices.view,devices.manage`). Add ownership validation in `NotificationController::markAsRead` checking `notifiable_id`.
7. SEC-07: In `LeaveController::storeRequest()` and `RegularizationController::store()`, bind `employee_id` to authenticated user if not manager, and forbid self-approval.
8. SEC-08: Remove mock fallback `Model::create(['id' => $id, ...])` auto-creation in controllers; use standard `findOrFail($id)`.
9. SEC-09: Remove `withoutVerifying()` from `CameraHttpService.php` and support custom CA bundle verification.
10. SEC-10: Add `config/sanctum.php` with 480/1440 min expiration and schedule `sanctum:prune-expired` in `routes/console.php`.
11. SEC-11: Protect biometric media in `ImageStorageService` and ensure secure retrieval.
12. SEC-12: Sanitize CSV export streams against formula injection (prepending `'` to `=`, `+`, `-`, `@`, `\t`, `\r`) in Employee, PayrollExport, and Report controllers.
13. SEC-13: Add security headers middleware (X-Frame-Options, CSP, nosniff, HSTS) in `bootstrap/app.php` and apply `throttle:api` on API routes.
14. SEC-14 & 15: Clean `.env.example` APP_KEY.
