# Scope: Security Remediation (SEC-11 through SEC-19)

## Architecture
- Security perimeter hardening across edge ingestion (MQTT, HTTP Webhooks), access control (Attendance Summary BOLA, User-Scoped WebSocket notifications, Signed media streaming), input validation (MIME-type enforcement, SSRF prevention), defense-in-depth headers (Content-Security-Policy), and upstream dependency patching (NPM, Composer).

## Feature Inventory
| # | Feature | Description | Milestone | Source | Status |
|---|---------|-------------|-----------|--------|--------|
| 1 | SEC-11 | Remediate BOLA/IDOR on Attendance Summary Endpoint | M1 | tasks-security.md | DONE |
| 2 | SEC-12 | Isolate Real-Time WebSocket Notification Broadcasting to Per-User Channels | M1 | tasks-security.md | DONE |
| 3 | SEC-14 | Deprecate URL Query-String Token Auth in Favor of Signed Media Routes | M1 | tasks-security.md | DONE |
| 4 | SEC-13 | Disable Unauthenticated WAN MQTT Tunnel & Prevent Rogue Device Ingestion | M2 | tasks-security.md & Survey 2 | DONE |
| 5 | SEC-15 | Restrict Biometric File Upload MIME Types to Disallow SVG / Stored XSS | M2 | tasks-security.md & Survey 2 | DONE |
| 6 | SEC-16 | Enforce Consistent SSRF Protection on `photo_path` in `PersonnelController` | M2 | tasks-security.md & Survey 2 | DONE |
| 7 | SEC-19 | Prevent Reverse-Proxy Loopback IP Authentication Bypass in Webhooks | M2 | tasks-security.md & Survey 2 | DONE |
| 8 | SEC-17 | Harden Content-Security-Policy Directives (`SecurityHeaders.php`) | M3 | tasks-security.md & Survey 3 | DONE |
| 9 | SEC-18 | Update Vulnerable NPM and Composer Upstream Dependencies | M3 | tasks-security.md & Survey 3 | DONE |
| 10| SEC-VERIFY| Comprehensive test suite, frontend build, tasks update | M4 | tasks-security.md & Dispatch | DONE |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| 1 | Milestone 1: Access Control & Authorization Hardening | SEC-11, SEC-12, SEC-14 | none | DONE |
| 2 | Milestone 2: Edge Ingestion & Input Security | SEC-13, SEC-15, SEC-16, SEC-19 | M1 | DONE |
| 3 | Milestone 3: Environment, CSP & Dependencies | SEC-17, SEC-18 | M2 | DONE |
| 4 | Milestone 4: Comprehensive Verification & Task Completion | Full regression suite, npm build, tasks-security.md update | M1, M2, M3 | DONE |

## Code Layout & File Boundaries
- **Milestone 2 Files (Completed)**:
  - `start-dev.sh`
  - `.env.example`
  - `.env`
  - `app/Console/Commands/MqttListenCommand.php`
  - `app/Http/Controllers/PersonnelController.php`
  - `app/Services/ImageStorageService.php`
  - `app/Http/Controllers/HttpWebhookController.php`
  - `bootstrap/app.php`
  - `tests/Feature/TelemetryDeduplicationTest.php`
  - `tests/Feature/SecurityRemediationTest.php`
  - `tests/Feature/AdversarialMilestone2Test.php`
  - `tests/Feature/Milestone2AdversarialStressTest.php`
- **Milestone 3 Files (Completed)**:
  - `app/Http/Middleware/SecurityHeaders.php`
  - `package.json`
  - `package-lock.json`
  - `composer.json`
  - `composer.lock`
  - `tests/Feature/SecurityRemediationTest.php`
  - `tests/Feature/AdversarialMilestone3CspDependencyTest.php`
  - `tests/Feature/AdversarialMilestone3Challenger2Test.php`
- **Milestone 4 Files (Completed)**:
  - `tasks-security.md`
  - Verification runs & audit logs
