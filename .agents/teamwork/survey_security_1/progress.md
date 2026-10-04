# Progress Tracker — survey_security_1

Last visited: 2026-10-01T06:05:00Z

## Status
All 15 security remediation tasks (SEC-01 through SEC-15) have been thoroughly investigated, with exact file paths, line numbers, current behavior, vulnerabilities, required code modifications, and test verification requirements identified and verified against codebase and tests.

| Task | Scope | Status | Notes |
|---|---|---|---|
| SEC-01 | Camera webhook push security | COMPLETED | Investigated routes/api.php, routes/web.php, HttpWebhookController.php |
| SEC-02 | Camera hardware passwords | COMPLETED | Investigated Device.php, DeviceController.php ($hidden, encrypted cast, index/show exposure) |
| SEC-03 | WAN MQTT TLS & broker authentication | COMPLETED | Investigated MqttListenCommand.php, CameraMqttService.php, start-dev.sh (bore.pub) |
| SEC-04 | Reverb WebSocket channels conversion to PrivateChannel | COMPLETED | Investigated all 11 Event classes, routes/channels.php, resources/js/echo.js, App.vue |
| SEC-05 | SSRF prevention in image ingestion | COMPLETED | Investigated ImageStorageService.php, PersonnelController.php |
| SEC-06 | Permission guards & IDOR on alerts/notifications | COMPLETED | Investigated routes/api.php, DeviceAlertController.php, NotificationController.php |
| SEC-07 | User-to-Employee ownership binding | COMPLETED | Investigated LeaveController.php, RegularizationController.php |
| SEC-08 | Insecure dummy entity auto-creation removal | COMPLETED | Investigated RegularizationController, LeaveController, VisitorController, AttendanceController |
| SEC-09 | TLS certificate verification on camera HTTP egress | COMPLETED | Investigated CameraHttpService.php (withoutVerifying removal, CA bundle) |
| SEC-10 | Sanctum API token expiration & pruning | COMPLETED | Investigated missing config/sanctum.php, routes/console.php schedule |
| SEC-11 | Biometric face photos/snaps storage protection | COMPLETED | Investigated ImageStorageService.php, config/filesystems.php, private disk & streaming |
| SEC-12 | CSV formula injection (DDE) sanitization | COMPLETED | Investigated EmployeeController.php, PayrollExportController.php, ReportController.php |
| SEC-13 | Security headers & API rate limiting | COMPLETED | Investigated bootstrap/app.php, routes/api.php, AppServiceProvider.php |
| SEC-14 | Dependency audit | COMPLETED | Executed npm audit (axios GHSA-vh66-26gq-q6x8 etc.) and composer audit |
| SEC-15 | .env.example APP_KEY sanitization | COMPLETED | Investigated .env.example line 3 hardcoded base64 key |

Preparing complete handoff.md report.
