# Scope: Security Remediation, High-Scale Performance & WCAG 2.1 AA Accessibility

## Architecture

### High-Level Engineering Invariants
1. **Security Invariant**: Camera HTTP push endpoints (`/Subscribe/*`) require authentication (shared secret/token), strict rate limiting (`throttle:60,1`), and rejection of untrusted device auto-creation. Device hardware passwords are encrypted at rest via Eloquent attribute encryption (`encrypted`) and hidden from serialization (`$hidden`). Reverb WebSocket events broadcast exclusively on authenticated `PrivateChannel` instances with strict RBAC permission callbacks in `routes/channels.php`. All external image fetching validates target IPs against SSRF (forbidding private/reserved IPs and cloud metadata `169.254.169.254`). All self-service requests (leaves, regularizations) enforce strict user-to-employee ownership binding. Dummy entity mock auto-creation in production controllers is eliminated.
2. **Performance & Scale Invariant**: Database foreign keys and composite queries are fully indexed via migration `2026_10_01_000001_add_performance_and_foreign_key_indexes.php`. Telemetry audit logs are decoupled from personnel/employee deletion in observers, preserving immutable compliance records. Concurrency race conditions in `Personnel::customize_id` are eliminated via a dedicated PostgreSQL sequence `personnel_customize_id_seq`. High-frequency camera heartbeats are throttled in Redis (60-second TTL), and `AccessLogReceived` broadcasts asynchronously via Redis queue. Heavy `photo_base64` attributes are hidden from serialization and excluded from index queries. Monthly attendance and payroll reports use SQL `GROUP BY` and conditional `SUM(...)` aggregations streamed via cursor. Camera personnel imports run asynchronously via queued `ImportCameraPersonnelJob` returning HTTP 202 Accepted.
3. **Frontend Accessibility (WCAG 2.1 AA) Invariant**: All interactive modals (`LiveTelemetry`, `DeviceManager`, `EmployeeFormModal`, `CameraLivePreviewModal`) implement ARIA dialog semantics (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`), focus traps, and Escape key dismissal. All icon-only buttons, form filters, and controls have descriptive `aria-label` attributes. All `<label>` tags are bound to inputs via `for` and `id`. Spinners in live telemetry and personnel tables are replaced with responsive skeleton loaders to eliminate Cumulative Layout Shift (CLS). Action grids and player controls on small viewports (<640px) ensure minimum 44x44px touch targets.

---

## Feature Inventory

Every requirement from `tasks-security.md`, `tasks-performance.md`, and `tasks-optimization.md` is inventoried and mapped to its authoritative milestone.

### Security Remediation Features (MS-SEC)
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| S1 | Webhook Authentication & Rate Limiting | Enforce API token/secret verification, rate limiting, and block untrusted auto-creation on `/Subscribe/*` | MS-SEC | tasks-security.md SEC-01 |
| S2 | Hardware Password Protection | Add 'password' to `$hidden` and cast as `'encrypted'` in `Device.php`, remove cleartext exposure in `DeviceController` | MS-SEC | tasks-security.md SEC-02 |
| S3 | WAN MQTT Transport Security | Support TLS on MQTT connections and eliminate unauthenticated public tunnels | MS-SEC | tasks-security.md SEC-03 |
| S4 | Private Reverb Channels | Convert public Reverb WebSocket channels to authenticated `PrivateChannel` with permission callbacks and `echo.private()` | MS-SEC | tasks-security.md SEC-04 |
| S5 | SSRF Ingestion Mitigation | Validate external image URLs against private/reserved IPs and protocols in `ImageStorageService` and `PersonnelController` | MS-SEC | tasks-security.md SEC-05 |
| S6 | Alert & Notification Authorization | Attach permission middleware to `device-alerts` and enforce ownership validation in `NotificationController::markAsRead` | MS-SEC | tasks-security.md SEC-06 |
| S7 | Ownership Binding on Self-Service | Bind `employee_id` to authenticated user in `LeaveController` and `RegularizationController`, forbid self-approval | MS-SEC | tasks-security.md SEC-07 |
| S8 | Eliminate Dummy Entity Auto-Creation | Remove mock fallback `Model::create(['id' => $id])` from controllers and use standard `findOrFail` | MS-SEC | tasks-security.md SEC-08 |
| S9 | Camera HTTP TLS Verification | Remove `withoutVerifying()` in `CameraHttpService` and support custom CA bundle options | MS-SEC | tasks-security.md SEC-09 |
| S10 | Sanctum Token Expiration | Publish `config/sanctum.php` with token expiration and schedule token pruning in `routes/console.php` | MS-SEC | tasks-security.md SEC-10 |
| S11 | Biometric Facial Media Protection | Restrict biometric face images and surveillance snaps from public disk or serve via authenticated endpoints | MS-SEC | tasks-security.md SEC-11 |
| S12 | CSV Formula Injection Sanitization | Prepend single quote or escape formulas starting with `=`, `+`, `-`, `@` across all CSV export streams | MS-SEC | tasks-security.md SEC-12 |
| S13 | Security Headers & API Throttling | Configure HSTS, CSP, X-Frame-Options, nosniff in `bootstrap/app.php` and apply `throttle:api` | MS-SEC | tasks-security.md SEC-13 |
| S14 | Dependency Vulnerability Remediation | Update vulnerable packages (`axios`, etc.) in `package.json` and `composer.json` | MS-SEC | tasks-security.md SEC-14 |
| S15 | Clean Environment Example | Blank out hardcoded `APP_KEY` in `.env.example` | MS-SEC | tasks-security.md SEC-15 |

### High-Scale Performance Features (MS-PERF)
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| P1 | Performance & FK Index Migration | Create composite and foreign key database indexes via new migration on alerts, visits, employees, leaves, access logs | MS-PERF | tasks-performance.md Task 1.1 |
| P2 | Decouple Telemetry Deletion in Observers | Remove synchronous `AccessLog::delete()` in `PersonnelObserver` and `EmployeeObserver` to preserve audit records | MS-PERF | tasks-performance.md Task 1.2 |
| P3 | Atomic Sequence for Personnel ID | Replace concurrency race `static::max('customize_id') + 1` with PostgreSQL sequence `personnel_customize_id_seq` | MS-PERF | tasks-performance.md Task 1.3 |
| P4 | SQL Aggregation for Reports & Payroll | Refactor monthly attendance/payroll queries to SQL `GROUP BY` and conditional `SUM(...)` with chunked/cursor CSV streaming | MS-PERF | tasks-performance.md Task 2.1 |
| P5 | Strip Heavy Base64 from Serialization | Add `photo_base64` to `$hidden` on `Personnel` and use column selections on directory index endpoints | MS-PERF | tasks-performance.md Task 2.2 |
| P6 | Asynchronous Camera Personnel Import | Offload camera personnel import to `ImportCameraPersonnelJob` queued on `camera-sync` with 202 Accepted response | MS-PERF | tasks-performance.md Task 2.3 |
| P7 | Throttle Camera Heartbeat Writes | Cache camera heartbeats in Redis with 60-second TTL to eliminate redundant database writes | MS-PERF | tasks-performance.md Task 3.1 |
| P8 | Asynchronous AccessLogReceived Broadcast | Make `AccessLogReceived` broadcast via Redis queue using `ShouldBroadcast` instead of `ShouldBroadcastNow` | MS-PERF | tasks-performance.md Task 3.2 |
| P9 | MQTT Connection Reuse & Parallel Dispatch | Reuse persistent MQTT connection in `CameraMqttService` and decompose multi-device sync into parallel sub-jobs | MS-PERF | tasks-performance.md Task 3.3 |
| P10 | Dashboard KPI Caching | Cache `DashboardStatsController::index` in Redis (5-10s TTL) and consolidate alert queries into single conditional aggregate | MS-PERF | tasks-performance.md Task 4.1 |
| P11 | Holiday & Shift Lookup Caching | Cache yearly holidays in Redis/memory in `AttendanceProcessingService` and eliminate non-SARGable `EXTRACT` queries | MS-PERF | tasks-performance.md Task 4.2 |
| P12 | Frontend Code Splitting & Echo Cleanup | Code-split heavy views via `defineAsyncComponent` and deduplicate WebSocket Echo listeners in `App.vue` | MS-PERF | tasks-performance.md Task 5.1, 5.2 |

### Frontend WCAG 2.1 AA & UX Optimization Features (MS-A11Y)
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| A1 | App Shell Navigation & Profile Menu | Add ARIA attributes to mobile drawer, accessible roles/focus on Profile Menu, pulse skeletons for KPIs, reduced-motion override | MS-A11Y | tasks-optimization.md APP-01..05 |
| A2 | Accessible Login Experience | Add `role="alert"` & `aria-live="assertive"` on errors, password toggle `aria-label`/`aria-pressed`, input validation feedback | MS-A11Y | tasks-optimization.md AUTH-01..04 |
| A3 | Live Telemetry Stream Accessibility & Skeletons | Replace clickable `<div>` with button, 2-column skeleton grid to eliminate CLS, dialog semantics/focus trap on inspection modal | MS-A11Y | tasks-optimization.md TEL-01..05 |
| A4 | Device Manager Accessibility & Mobile Grid | Form label `for`/`id` bindings, camera-specific `aria-label`s on actions, responsive grid with min 44x44px touch targets, ARIA tabs | MS-A11Y | tasks-optimization.md DEV-01..05 |
| A5 | Personnel Manager Skeletons & Accessibility | Accessible search/filter inputs, `scope="col"` on table headers, 5 animated skeleton rows replacing text cell, form label bindings | MS-A11Y | tasks-optimization.md PERS-01..05 |
| A6 | Employee Directory & Form Modal Accessibility | Table/Grid toggle `role="group"` & `aria-pressed`, accessible action buttons, input `for`/`id` bindings, Enter submission, dialog focus trap | MS-A11Y | tasks-optimization.md EMP-01..05 |
| A7 | Visitor Wizard Accessibility & Validation | Step progress `aria-current="step"`, required field step validation, label `for`/`id` bindings, accessible async spinner | MS-A11Y | tasks-optimization.md VIS-01..04 |
| A8 | Video Preview Modal Mobile Responsive & ARIA | Responsive header layout for viewports <640px, fullscreen/close `aria-label`s, quality switcher `role="group"`, focus trap/Escape | MS-A11Y | tasks-optimization.md CAM-01..04 |
| A9 | Attendance Stream Live Region & Dialogs | `aria-live="polite"` on live clock-in stream, accessible confirmation dialog replacing `window.confirm()`, label `for`/`id` bindings | MS-A11Y | tasks-optimization.md ATT-01..03 |
| A10 | Payroll Export Modal Skeletons & Semantics | Async loading spinner and disabled state during export, `<fieldset>` & `<legend>` on radio groups, `for`/`id` on Month/Year selects | MS-A11Y | tasks-optimization.md REP-01..03 |

---

## Milestones

| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| MS-SEC | Backend Security Remediation | Features S1–S15 (SEC-01 through SEC-15) | none | IN_PROGRESS |
| MS-A11Y | Frontend UI/UX & WCAG 2.1 AA Accessibility | Features A1–A10 (APP-01 through REP-03) | none | IN_PROGRESS |
| MS-PERF | High-Scale Performance & Telemetry Architecture | Features P1–P12 (Tasks 1.1 through 5.2) | MS-SEC | PLANNED |
| MS-VERIFY | Comprehensive E2E Suite & Adversarial Hardening | Full regression pass (`php artisan test`, `npm run build`), adversarial verifications | MS-SEC, MS-A11Y, MS-PERF | PLANNED |

---

## Interface Contracts & Write Boundaries

### MS-SEC Write Boundaries (Exclusive to MS-SEC Worker)
- `routes/api.php`, `routes/web.php`, `routes/channels.php`
- `app/Http/Controllers/HttpWebhookController.php` (Security auth & validation)
- `app/Models/Device.php` ($hidden, encrypted cast)
- `app/Http/Controllers/DeviceController.php` (Password hiding)
- `app/Console/Commands/MqttListenCommand.php` (TLS & auth)
- `app/Services/CameraMqttService.php` (TLS & auth)
- `app/Events/*.php` (Converting to PrivateChannel)
- `app/Services/ImageStorageService.php` (SSRF validation, private disk)
- `app/Http/Controllers/PersonnelController.php` (SSRF check)
- `app/Http/Controllers/DeviceAlertController.php` (Permission middleware & guards)
- `app/Http/Controllers/NotificationController.php` (Ownership validation on markAsRead)
- `app/Http/Controllers/LeaveController.php` (Ownership binding & self-approval block)
- `app/Http/Controllers/RegularizationController.php` (Ownership binding & dummy mock removal)
- `app/Http/Controllers/VisitorController.php` (Dummy mock removal)
- `app/Http/Controllers/AttendanceController.php` (Dummy mock removal)
- `app/Services/CameraHttpService.php` (TLS verification)
- `config/sanctum.php`, `routes/console.php` (Token expiration & pruning)
- `bootstrap/app.php` (Security headers)
- `.env.example` (APP_KEY blanked)

### MS-A11Y Write Boundaries (Exclusive to MS-A11Y Worker)
- `resources/js/App.vue` (Drawer ARIA, User Profile menu, KPI pulse skeletons, async component code-splitting, private echo channels)
- `resources/js/components/notifications/NotificationBell.vue`
- `resources/js/views/LoginPage.vue`
- `resources/js/views/LiveTelemetry.vue`
- `resources/js/views/DeviceManager.vue`
- `resources/js/views/PersonnelManager.vue`
- `resources/js/components/employees/EmployeeDirectory.vue`
- `resources/js/components/employees/EmployeeFormModal.vue`
- `resources/js/components/employees/EmployeeProfileModal.vue`
- `resources/js/components/visitors/VisitorCheckInWizard.vue`
- `resources/js/components/CameraLivePreviewModal.vue`
- `resources/js/components/attendance/AttendanceDashboard.vue`
- `resources/js/components/attendance/ManualAttendanceEntry.vue`
- `resources/js/components/reports/PayrollExportModal.vue`
- `resources/js/echo.js` (Private channel authorization configuration)

### MS-PERF Write Boundaries (Executes after MS-SEC passes gate)
- `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php`
- `database/migrations/2026_10_01_000002_create_personnel_customize_id_seq.php`
- `app/Observers/PersonnelObserver.php`, `app/Observers/EmployeeObserver.php`
- `app/Models/Personnel.php` (Sequence hook & $hidden photo_base64)
- `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/PayrollExportController.php` (SQL aggregates & CSV streaming)
- `app/Jobs/ImportCameraPersonnelJob.php`, `app/Jobs/SyncDevicePersonnelJob.php`
- `app/Http/Controllers/DashboardStatsController.php`
- `app/Services/AttendanceProcessingService.php`
- Incremental performance optimizations on `HttpWebhookController.php`, `MqttListenCommand.php`, `DeviceController.php`, and `PersonnelController.php`.
