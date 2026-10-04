# Original User Request

## Initial Request — 2026-09-29T15:46:56Z

Execute the complete end-to-end transformation of the Intelligent AI Camera Hub into a production-grade Attendance and Visitor Management System across all phases outlined in `tasks.md`.

Working directory: `/home/wsk-devops2/AI-Camera-Integration`
Integrity mode: development

## Requirements

### R1. Authentication, Role-Based Access Control & Organization Hierarchy (Phases 1 & 10)
Implement secure user authentication (Laravel Sanctum/session) with RBAC (roles: super-admin, admin, hr-manager, security, receptionist, manager, employee) and permissions. Support organizational hierarchy including organizations, locations/sites, departments, and job designations.

### R2. Employee Directory, Shifts & Scheduling (Phases 2 & 3)
Create the Employee domain linking to existing biometric personnel records, with reporting managers, employment statuses, and department allocations. Build comprehensive shift definitions (start/end times, grace periods, early out, overtime, night/overnight shifts, breaks) and shift assignment calendars with holiday management.

### R3. Biometric Attendance Processing Engine (Phases 4 & 11)
Build the real-time event pipeline that consumes camera access telemetry (`access_logs` / `VerifyPush`), pairs entry and exit punches based on camera role/direction (`entry`, `exit`, `bidirectional`), and calculates daily attendance records (present, absent, late minutes, early out, overtime, half-day). Implement daily finalizer and scheduled reconciliation jobs.

### R4. Comprehensive Visitor Management Lifecycle (Phase 6)
Build end-to-end visitor workflows: visitor profile directory, pre-registration by employee hosts, receptionist check-in wizard with badge generation and host notifications, automated temporary face sync to camera hardware with validity periods, and clean checkout/badge return with face revocation.

### R5. Leave Management, Self-Service, Reports & Exports (Phases 5, 7, 8, 9, 12)
Implement leave types, balance allocation, request-approval workflows updating attendance, employee/manager self-service portals, reporting dashboards with CSV/PDF/Excel exports (daily, monthly, payroll export formats), and in-app/WebSocket notifications.

## Acceptance Criteria

### Schema & Database Integrity
- [ ] All PostgreSQL migrations execute cleanly (`php artisan migrate`) without data corruption or breaking existing camera tables (`devices`, `personnel`, `access_logs`, `stranger_snaps`, `sync_tasks`).
- [ ] Database relationships and cascading soft-deletes/constraints are enforced.

### Automated Test Suite
- [ ] Complete automated test suite passes via `php artisan test`.
- [ ] Unit & Feature tests verify:
  - User authentication and role permission middleware
  - Employee creation and backward-compatible association to `personnel`
  - Punch pairing algorithm and attendance state calculations (on-time, late, early exit, overtime)
  - Shift resolution with grace period and holiday precedence
  - Visitor check-in, temporary camera credential provisioning, and checkout revocation
  - Leave deduction and balance validation

### Frontend Build & Interface
- [ ] Vue 3 SPA builds cleanly with no syntax or packaging errors (`npm run build`).
- [ ] New UI modules (Employees, Shifts, Attendance Dashboard, Leaves, Visitors, Reports, Settings) are integrated into `App.vue` navigation.
- [ ] Real-time updates via Laravel Reverb reflect live attendance clock-ins and visitor check-ins.

### Hardware & Daemon Compatibility
- [ ] MQTT listener (`php artisan mqtt:listen`) and HTTP webhooks continue operating without regression for live camera telemetry.
- [ ] Sync tasks (`camera-sync` queue worker) correctly handle employee and visitor face provisioning.

## Follow-up — 2026-09-29T16:55:53Z

Implement end-to-end enterprise Attendance & Visitor Management capabilities (Phases 1 through 12 in `tasks.md`) on top of the Intelligent AI Camera Hub platform.

Working directory: /home/wsk-devops2/AI-Camera-Integration
Integrity mode: development

## Requirements

### R1. Authentication, Authorization (RBAC) & Multi-Tenancy (Phase 1)
- Implement user authentication with Laravel Sanctum token/session handling, login/registration, password reset, and user profile management.
- Implement Role-Based Access Control (RBAC) with granular permissions, role assignment (`super-admin`, `admin`, `hr-manager`, `security`, `receptionist`, `manager`, `employee`), and permission middleware/UI guards.
- Implement organizational hierarchy: organizations, locations/sites, departments (with hierarchy and head), and designations/job titles.

### R2. Employee Management & Biometric Linkage (Phase 2)
- Implement employee data model linked with the existing biometric `personnel` library (`personnel_id`), employee code, department, shift assignment, and emergency contacts.
- Implement Employee REST APIs and Vue UI directory, profile modals, and CSV bulk import/export.

### R3. Shift & Schedule Management (Phase 3)
- Implement configurable shift definitions (start/end times, grace periods, early out thresholds, half-day thresholds, break durations, overnight shifts).
- Implement shift assignment, department shift rotation, and holiday calendars.

### R4. Attendance Processing Engine & Telemetry Pipeline (Phase 4)
- Implement attendance punches and attendance records models with automatic computation (first clock-in, last clock-out, total work hours, overtime, tardiness, early outs, and status classification).
- Implement attendance processing service hooked into MQTT telemetry and HTTP webhooks (`ProcessAttendancePunchJob`, daily finalizer, monthly summary).
- Implement real-time attendance broadcasting via Reverb and Attendance Dashboard / Roster / Calendar UI.

### R5. Leave Management (Phase 5)
- Implement leave types, leave balances, and leave request lifecycle (submission, approvals, auto-deduction, attendance integration).
- Implement Leave Management UI (forms, approval queues, balance widgets, and team leave calendar).

### R6. Visitor Management System (Phase 6)
- Implement visitor identities, visits lifecycle (pre-registration, walk-in, photo capture, temporary badge, check-in, check-out, host notification).
- Implement visitor-camera synchronization (provisioning temporary biometric access on entry, de-provisioning on exit/expiration).
- Implement Visitor Dashboard, Check-in wizard, visitor badges, and watchlist/blocklist management.

### R7. Notifications & Alerts Engine (Phase 7)
- Implement centralized notifications (in-app database, email, real-time WebSocket) for attendance events, leave approvals, visitor arrival, and stranger/security alerts.

### R8. Reporting, Analytics & Payroll Export (Phase 8 & Phase 12.1)
- Implement comprehensive reporting and analytics: daily/monthly attendance summaries, department trends, visitor logs, security audits.
- Implement export engine (CSV, Excel, PDF) and payroll export endpoints.

### R9. Employee & Manager Self-Service Portal (Phase 9)
- Implement self-service views for attendance records, punch logs, regularization requests with manager approvals, and leave applications.

### R10. System Administration, Audit Trail & Device Enhancements (Phase 10 & Phase 11)
- Implement system configuration settings, polymorphic audit trail logging, and device roles (`entry`, `exit`, `bidirectional`, `visitor_kiosk`) linked to physical locations.

### R11. API Documentation & Mobile/PWA Optimization (Phase 12.2 & Phase 12.3)
- Implement interactive API documentation and ensure responsive layout across desktop, tablet, and mobile views.

## Acceptance Criteria

### Automated Backend Verification
- [ ] Database migrations execute cleanly (`php artisan migrate --force`).
- [ ] Automated feature and unit tests pass (`php artisan test`).
- [ ] Queued jobs (`camera-sync`, attendance processing) and scheduled tasks execute reliably.

### Biometric & Telemetry Integration
- [ ] Real-time MQTT verification events (`RecPush`/`VerifyPush`) automatically parse into punches and update daily attendance records.
- [ ] Visitor check-in/check-out triggers camera whitelist synchronization and timely revocation.

### Frontend Quality & Usability
- [ ] Vue 3 SPA compiles without errors (`npm run build`).
- [ ] All new navigation tabs (Employees, Schedules, Attendance, Leave, Visitors, Reports, Settings, Notifications) render smoothly with live WebSocket updates.


## Follow-up — 2026-10-01T05:46:48Z

Execute the tasks defined in `tasks-security.md`, `tasks-performance.md`, and `tasks-optimization.md` in parallel across specialized sub-teams, ensuring all security vulnerabilities are remediated, database/telemetry bottlenecks are eliminated, and frontend WCAG 2.1 AA accessibility issues are resolved without breaking existing features.

Working directory: /home/wsk-devops2/AI-Camera-Integration
Integrity mode: development

## Requirements

### R1. Security Remediation (`tasks-security.md`)
- Remediate camera HTTP webhook push vulnerabilities by enforcing API token/secret verification or credential matching, rate limiting on `/Subscribe/*`, and blocking untrusted device auto-creation.
- Protect camera hardware passwords: add `'password'` to `$hidden` and cast as `'encrypted'` in `Device.php`, and remove cleartext password exposure in `DeviceController`.
- Secure WAN transport: support TLS on MQTT connections and restrict insecure public tunnels.
- Convert public Reverb WebSocket channels for vision telemetry and attendance events to authenticated `PrivateChannel` instances with permission callbacks in `routes/channels.php` and matching frontend `echo.private()` listeners.
- Mitigate SSRF vulnerabilities in `ImageStorageService` and `PersonnelController` by validating image URLs against private/reserved IP blocks and schemes.
- Enforce strict authorization and user-to-employee ownership guards on alerts, notifications, and leave/regularization requests.

### R2. High-Scale Performance & Telemetry Architecture (`tasks-performance.md`)
- Add composite and foreign key database indexes via a new migration (`database_alerts`, `visits`, `employees`, `leave_requests`, `access_logs`).
- Decouple telemetry audit logs from personnel deletion in `PersonnelObserver` to prevent table locking and preserve immutable compliance records.
- Replace concurrency race condition `static::max('customize_id') + 1` in `Personnel` with a dedicated PostgreSQL sequence (`personnel_customize_id_seq`).
- Refactor monthly attendance/payroll queries to SQL aggregates (`GROUP BY`, conditional sums) and stream export CSVs using chunking/cursor pagination.
- Strip heavy `photo_base64` from model serialization by hiding the attribute and using targeted column selection in index queries.
- Throttle camera heartbeat database writes using Redis cache (60s throttle) and make `AccessLogReceived` asynchronous via `ShouldBroadcast` on Redis.
- Offload camera personnel import to an asynchronous queued background job (`ImportCameraPersonnelJob`) with immediate `202 Accepted` response.

### R3. Frontend UI/UX & WCAG 2.1 AA Accessibility (`tasks-optimization.md`)
- Implement accessible ARIA attributes, semantic roles (`role="dialog"`, `role="tablist"`, `role="tab"`), focus traps, and Escape key listeners across all modals (`LiveTelemetry`, `DeviceManager`, `EmployeeFormModal`, `CameraLivePreviewModal`).
- Add accessible names and `aria-label`s to all icon-only buttons, form inputs, filters, and mobile drawer triggers.
- Associate all `<label>` tags with their corresponding input controls (`for` and `id` bindings).
- Replace layout-shifting spinners with responsive skeleton card and table loaders to eliminate Cumulative Layout Shift (CLS).
- Redesign cramped action grids and mobile player controls to provide minimum 44x44px touch targets on viewports <640px.

## Acceptance Criteria

### Security Criteria
- [ ] Unauthenticated requests to camera webhook endpoints are rejected with `401 Unauthorized` or `403 Forbidden`.
- [ ] Device model hides and encrypts camera passwords; passwords never appear in API responses or plaintext DB records.
- [ ] Vision telemetry and attendance broadcast channels are private and require authenticated sessions.
- [ ] SSRF validation prevents fetching URLs pointing to `localhost`, `127.0.0.1`, RFC 1918 private IPs, or cloud metadata endpoints (`169.254.169.254`).
- [ ] All existing adversarial security and RBAC tests (`php artisan test`) pass without failure.

### Performance Criteria
- [ ] Index migration executes and rolls back cleanly (`php artisan migrate`).
- [ ] Personnel deletion succeeds without deleting or locking `access_logs`.
- [ ] `Personnel::create()` generates incremental `customize_id` values atomically via database sequence without concurrency exceptions.
- [ ] Device index and personnel directory endpoints return payloads without `photo_base64`.
- [ ] Repeated rapid camera heartbeats write to database at most once per 60 seconds per device.

### Frontend Criteria
- [ ] `npm run build` succeeds with zero errors.
- [ ] Image inspection modal, camera live preview modal, and employee form modal trap keyboard focus and close on `Escape`.
- [ ] All icon-only buttons in `DeviceManager.vue`, `LiveTelemetry.vue`, and `EmployeeDirectory.vue` have descriptive `aria-label`s.
- [ ] Live telemetry and personnel manager tables render skeleton loaders during data fetch states without layout shift.

## Verification Resources
- Run PHPUnit tests: `php artisan test`
- Check route & channel configurations: `php artisan route:list`, `php artisan channel:list`
- Verify migrations: `php artisan migrate:status`
- Verify frontend asset compilation: `npm run build`
