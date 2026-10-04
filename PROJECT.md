# Project: Intelligent AI Camera Hub → Attendance & Visitor Management System

## Architecture

### High-Level Topology & Event Flow (Pure WAN MQTT)
```
[Edge AI Cameras (X40Y / IPC - WAN / Cellular / Ethernet)]
      │
      ├── Downlink MQTT Topics: mqtt/face/{DeviceID} (EditPerson, DelPerson, Reboot, Time)
      │
      └── Uplink MQTT Topic Streams: mqtt/face/{DeviceID}/Rec (VerifyPush), Snap (StrSnapPush), heartbeat
             │
             ▼
      [MQTT Broker (Mosquitto/EMQX :1883)]
             │
             ▼
      [MQTT Telemetry Daemon (php artisan mqtt:listen)]
             │
             ├── 1. Ingest into access_logs
             ├── 2. Emit AccessLogReceived event
             └── 3. Send PushAck to camera
                    │
                    ▼
      [Attendance Processing Pipeline]
             │
             ├── Async Listener on AccessLogReceived -> ProcessAttendancePunchJob
             ├── Resolve customize_id -> Employee & Shift
             ├── Ingest into attendance_punches (direction, timestamp, device)
             ├── Upsert attendance_records (first_in, last_out, hours, status, overtime)
             └── Broadcast AttendancePunchReceived over Laravel Reverb
                    │
                    ▼
      [Vue 3 SPA (Vite + Pinia + Tailwind v4)]
             ├── Real-time Attendance & Visitor Dashboards
             ├── Management Consoles (Employees, Shifts, Leaves, Visitors, Reports)
             └── Reverb WebSockets Stream
```

### Bridge & Layering Design
1. **Biometric Face Bridge**: The existing `personnel` table acts as the physical biometric face repository for edge cameras. The new `employees` table links 1-to-1 to `personnel.id`. Creating an employee with facial data creates/updates `personnel`, automatically triggering `PersonnelObserver` and `SyncPersonnelJob` without altering camera communication code.
2. **Visitor Temporary Access Bridge**: Temporary visitors on check-in receive a temporary `personnel` record (`temp_valid = 1`, `valid_begin`, `valid_end`) pushing face templates to cameras. On checkout, the temporary record is deleted, dispatching `SyncPersonnelJob('DELETE')` to revoke camera access.
3. **Decoupled Real-Time Attendance**: `MqttListenCommand` remains ultra-lightweight and non-blocking. Attendance processing executes asynchronously via `AccessLogReceived` events and `ProcessAttendancePunchJob`.

---

## Feature Inventory

Every feature across all phases 1 through 12 is inventoried and assigned to an authoritative milestone.

| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Sanctum / Session Auth API | Secure user login, token issuance, logout, user profile endpoints | M1 | tasks.md §1.1 |
| 2 | Role-Based Access Control (RBAC) | Roles (super-admin, admin, hr-manager, security, receptionist, manager, employee), permissions, pivots, and CheckPermission middleware | M1 | tasks.md §1.2 |
| 3 | Centralized Frontend API Client | `resources/js/api/client.js` with Bearer auth headers, CSRF token handling, and 401/403/422 interceptors | M1 | survey_frontend |
| 4 | Authentication Pinia Store & UI | `authStore.js`, `LoginPage.vue`, and authentication gate in `App.vue` | M1 | tasks.md §1.1 |
| 5 | Organization Hierarchy | Organizations, Locations/Sites, Departments (tree hierarchy with head_id), and Designations/Job Titles | M1 | tasks.md §1.3 |
| 6 | Organization & Department Admin UI | Department, Designation, and Location CRUD management views | M1 | tasks.md §1.3 |
| 7 | Global System Settings | Key-value settings table with type casting (attendance defaults, visitor rules, notifications) and `SystemSettings.vue` | M1 | tasks.md §10.1 |
| 8 | Comprehensive Audit Trail | Polymorphic `audit_logs` tracking model mutations and actions with `AuditLogViewer.vue` | M1 | tasks.md §10.2 |
| 9 | Employee Data Model & Bridge | `employees` table with 1-to-1 linkage to `personnel`, employee codes, manager hierarchy, and status | M2 | tasks.md §2.1 |
| 10 | Employee Management API | `EmployeeController` CRUD, department/status filters, shift assignments, and CSV/Excel import/export | M2 | tasks.md §2.2 |
| 11 | Employee Directory UI | `EmployeeDirectory.vue`, `EmployeeProfileModal.vue`, `EmployeeFormModal.vue` with photo upload/webcam capture | M2 | tasks.md §2.3 |
| 12 | Shift Definitions & Rules | `shifts` table supporting day/night/overnight/flex shifts, grace periods, early out, breaks, half day thresholds | M2 | tasks.md §3.1 |
| 13 | Shift Assignment & Scheduling | `employee_shift_assignments` table, effective dates, assigned days of week, and bulk assignment API | M2 | tasks.md §3.2 |
| 14 | Holiday Calendar | `holidays` table with public/company/optional types, recurrence, department scoping, and `HolidayController` | M2 | tasks.md §3.3 |
| 15 | Shift & Schedule UI | `ShiftManager.vue`, `ShiftAssignment.vue`, `HolidayCalendar.vue` with month view | M2 | tasks.md §3.4 |
| 16 | Device Roles & Direction Setup | Add `device_role` (`entry`, `exit`, `bidirectional`, `visitor_kiosk`), `organization_id`, and `location_id` to `devices` | M3 | tasks.md §11.1 |
| 17 | Attendance Punches Data Model | `attendance_punches` table storing raw clock events, direction, device link, and punch source | M3 | tasks.md §4.2 |
| 18 | Daily Attendance Records Schema | `attendance_records` table with unique (`employee_id`, `date`), work hours, overtime, late/early minutes, and status | M3 | tasks.md §4.1 |
| 19 | Attendance Processing Engine | `AttendanceProcessingService` for punch pairing, debouncing, shift resolution, late/early/overtime math, and night shifts | M3 | tasks.md §4.3 |
| 20 | Real-Time Attendance Pipeline | Listener on `AccessLogReceived` queuing `ProcessAttendancePunchJob` | M3 | tasks.md §4.4 |
| 21 | Attendance Scheduled Jobs | `DailyAttendanceFinalizerJob` (marking absences, finalizing open days) and monthly aggregations | M3 | tasks.md §4.4 |
| 22 | Attendance Management APIs | Daily attendance roster, employee monthly views, HR manual punch entry, and status override | M3 | tasks.md §4.5 |
| 23 | Real-Time Attendance Broadcasting | `AttendancePunchReceived` event broadcast over Reverb, `attendanceStore.js`, and toast alerts | M3 | tasks.md §4.7 |
| 24 | Attendance UI Suite | `AttendanceDashboard.vue` (KPIs, Who's In/Out), `DailyAttendanceRoster.vue`, `EmployeeAttendanceCalendar.vue`, `ManualAttendanceEntry.vue` | M3 | tasks.md §4.6 |
| 25 | Leave Types & Balances Model | `leave_types` and `leave_balances` with annual quotas, carry-forward rules, and year tracking | M4 | tasks.md §5.1 |
| 26 | Leave Request & Approval Workflow | `leave_requests` table, half-day support, attachment support, manager approval/rejection endpoints | M4 | tasks.md §5.1, §5.2 |
| 27 | Leave Processing Engine | `LeaveService`: balance validation, auto-deduct on approval, auto-restore on cancel, and marking `attendance_records` as `on_leave` | M4 | tasks.md §5.3 |
| 28 | Employee & Manager Self-Service | My Attendance calendar, My Punches, Regularization requests (`regularization_requests`), My Leaves, and Manager Approval Queue | M4 | tasks.md §9.1, §9.2 |
| 29 | Leave & Self-Service UI | `LeaveRequestForm.vue`, `LeaveApprovalQueue.vue`, `LeaveBalanceWidget.vue`, `LeaveCalendarView.vue`, `SelfServicePortal.vue` | M4 | tasks.md §5.4, §9 |
| 30 | Visitor Data Model | `visitors` (identity, ID documents, photo, blocklist) and `visits` (host, purpose, badge, NDA, vehicle, check-in/out timestamps) | M5 | tasks.md §6.1 |
| 31 | Visitor Pre-Registration & Invitation | Pre-register expected visits, list today's expected visitors, cancellation endpoints | M5 | tasks.md §6.2 |
| 32 | Visitor Check-In / Check-Out Lifecycle | Visitor registration, check-in, checkout, badge assignment, and host notification trigger | M5 | tasks.md §6.3 |
| 33 | Visitor Camera Biometric Provisioning | `VisitorSyncService`: enroll temporary face to cameras on check-in (`temp_valid: 1`), revoke face on check-out (`DELETE` sync), and `ExpireVisitorAccessJob` | M5 | tasks.md §6.4 |
| 34 | Visitor UI Suite & Kiosk Mode | `VisitorDashboard.vue`, `VisitorCheckInWizard.vue`, `VisitorCheckOutModal.vue`, `VisitorDirectory.vue`, `VisitorBadge.vue`, `WatchlistManager.vue`, Kiosk mode | M5 | tasks.md §6.5, §11.2 |
| 35 | Centralized Notification System | Database & WebSocket notifications, user channel preferences (`notification_preferences`), `NotificationBell.vue`, `NotificationsPage.vue` | M6 | tasks.md §7.1 |
| 36 | Attendance & Security Alerts | Late arrival notifications, manager morning summaries, stranger snap routing, and blocked visitor detection alerts | M6 | tasks.md §7.2, §7.3 |
| 37 | Reporting & Analytics Engine | Daily/monthly attendance reports, department trends, tardiness, overtime, visitor frequency, access logs, and `ReportsPage.vue` | M6 | tasks.md §8.1-8.3, §8.5 |
| 38 | Report Exporting Suite | CSV, Excel (XLSX), and PDF exports for attendance and visitor reports | M6 | tasks.md §8.4 |
| 39 | Payroll Export Integration | `PayrollExportController` generating payroll-ready attendance metrics (days present, absent, late, overtime, total hours) | M6 | tasks.md §12.1 |
| 40 | Interactive API Documentation | API route documentation with request/response schema specifications at `/docs/api` | M6 | tasks.md §12.2 |
| 41 | E2E Testing Suite Verification | 100% pass of requirement-driven opaque-box E2E test suite (Tiers 1-4) across all features | M7 | tasks.md Acceptance |
| 42 | Adversarial Coverage Hardening | White-box stress testing, edge-case probing, and boundary hardening (Tier 5) | M7 | Project Pattern |

---

## Milestones

| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Security Foundation, RBAC & Multi-Tenant Settings | Features 1–8: Sanctum auth, roles, permissions, check-permission middleware, organizations, sites, departments, designations, system settings, audit trail, auth UI | none | PLANNED |
| M2 | Employee Directory, Shifts & Scheduling | Features 9–15: Employee domain linked to Personnel, employee CRUD/import/export, shift definitions, shift assignments, holiday calendars, UI views | M1 | PLANNED |
| M3 | Biometric Attendance Processing Engine | Features 16–24: Device roles/direction, punch log, attendance records, attendance processing engine, event pipeline, scheduled jobs, attendance UI & Reverb broadcast | M2 | PLANNED |
| M4 | Leave Management & Self-Service Portal | Features 25–29: Leave types, balances, requests, approval engine, attendance integration, employee/manager self-service, regularization requests, UI | M3 | PLANNED |
| M5 | Comprehensive Visitor Management Lifecycle | Features 30–34: Visitor directory, visits, check-in wizard, temporary camera face sync & auto-revocation, badge issuance, watchlist, UI | M2 | PLANNED |
| M6 | Notifications, Reporting, Payroll & API Documentation | Features 35–40: In-app/email alerts, security routing, analytics reports, CSV/XLSX/PDF exports, payroll export, API docs | M3, M4, M5 | PLANNED |
| M7 | E2E Test Pass (Tiers 1-4) & Adversarial Hardening (Tier 5) | Features 41–42: 100% verification against E2E test suite from Testing Track, followed by white-box adversarial stress testing | M1–M6, TEST_READY.md | PLANNED |

---

## Interface Contracts

### M1 ↔ M2 (Auth/Org ↔ Employees)
- `users` table provides `user_id` FK (nullable) for self-service login.
- `organizations`, `departments`, `designations`, `locations` provide foreign keys for `employees`.
- Soft-delete on departments or designations must restrict deletion if active employees exist (`RESTRICT` or application validation).

### M2 ↔ M3 (Employees/Shifts ↔ Attendance Engine)
- `Employee` model must expose:
  - `personnel()` relationship (1-to-1 via `personnel_id`).
  - `currentShift(Carbon $date)` returning assigned `Shift` or default organization shift.
  - `isHoliday(Carbon $date)` returning boolean based on `holidays` table and department/organization scope.
  - `isRestDay(Carbon $date)` returning boolean based on assigned days of week.
- `Shift` model must expose:
  - `start_time`, `end_time`, `grace_period_minutes`, `early_out_threshold_minutes`, `min_hours_full_day`, `half_day_threshold_hours`, `break_duration_minutes`, `is_overnight`.

### M3 ↔ M4 (Attendance ↔ Leave Management)
- When a `leave_request` is approved for an employee on a date range:
  - `LeaveService` calls `AttendanceProcessingService::setLeaveStatus($employee, $date, $leaveType)`.
  - An `attendance_record` is created or updated with `status = 'on_leave'`, `remarks = $leaveType->name`.
- When an attendance regularization request is approved:
  - `AttendanceProcessingService::processDay($employee, $date)` recomputes hours and status.

### M2 / M3 ↔ M5 (Employees / Devices ↔ Visitors)
- `Visit` references `host_employee_id` (FK to `employees.id`).
- On visit check-in:
  - `VisitorSyncService::enrollVisitorToCamera(Visit $visit)` creates a temporary `Personnel` record (`temp_valid = 1`, `valid_begin = now()`, `valid_end = $visit->expected_departure ?? today()->endOfDay()`).
  - `PersonnelObserver` dispatches `SyncPersonnelJob` on queue `camera-sync`.
- On visit check-out:
  - `VisitorSyncService::removeVisitorFromCamera(Visit $visit)` deletes the temporary `Personnel` record, which triggers `SyncPersonnelJob` with action `'DELETE'`.

### Telemetry & Camera Invariant (M3 & M5 ↔ Camera Hardware)
- `AccessLogReceived` broadcast event payload: `{ accessLog: AccessLog }`.
- `access_logs.customize_id` is matched against `personnel.customize_id` -> `employees.personnel_id`.
- If match not found in `employees`, check `visits.personnel_id` for visitor identification.

---

## Code Layout

### Backend (`app/`)
```
app/
├── Console/Commands/
│   ├── MqttListenCommand.php             # Preserved: Ingests MQTT camera streams
│   └── DailyAttendanceFinalizer.php      # Daily 11:59PM attendance finalizer
├── Events/
│   ├── AccessLogReceived.php             # Preserved
│   ├── AttendancePunchReceived.php       # Real-time attendance broadcast
│   ├── VisitorCheckedIn.php              # Real-time visitor arrival broadcast
│   └── VisitorCheckedOut.php             # Real-time visitor checkout broadcast
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php            # Login, logout, user profile, tokens
│   │   ├── OrganizationController.php    # Organizations, locations, departments, designations
│   │   ├── EmployeeController.php        # Employee directory, import/export
│   │   ├── ShiftController.php           # Shifts, assignments, holidays
│   │   ├── AttendanceController.php      # Daily roster, calendar, manual punch, override
│   │   ├── LeaveController.php           # Leave types, balances, requests, approvals
│   │   ├── VisitorController.php         # Visitors directory, check-in wizard, checkout, badges
│   │   ├── ReportController.php          # Attendance & visitor reports, CSV/PDF/XLSX export
│   │   ├── PayrollExportController.php   # Payroll export endpoint
│   │   ├── SettingController.php         # Global system settings & audit logs
│   │   ├── DeviceController.php          # Preserved
│   │   └── HttpWebhookController.php     # Preserved
│   └── Middleware/
│       └── CheckPermission.php           # RBAC permission guard
├── Jobs/
│   ├── SyncPersonnelJob.php              # Preserved: Edge camera face sync
│   ├── ProcessAttendancePunchJob.php     # Ingest access log into attendance punch & record
│   ├── DailyAttendanceFinalizerJob.php   # Nightly absence and overtime finalizer
│   └── ExpireVisitorAccessJob.php        # Auto-expire visitor face credentials
├── Listeners/
│   └── ProcessAccessLogForAttendance.php # Event listener for AccessLogReceived
├── Models/
│   ├── User.php                          # Extended with roles and employee link
│   ├── Role.php, Permission.php          # RBAC models
│   ├── Organization.php, Location.php, Department.php, Designation.php
│   ├── Device.php                        # Preserved, extended with role/location
│   ├── Personnel.php                     # Preserved: Edge camera face entity
│   ├── Employee.php                      # Linked 1-to-1 to Personnel
│   ├── Shift.php, EmployeeShiftAssignment.php, Holiday.php
│   ├── AttendancePunch.php, AttendanceRecord.php, RegularizationRequest.php
│   ├── LeaveType.php, LeaveBalance.php, LeaveRequest.php
│   ├── Visitor.php, Visit.php
│   └── Setting.php, AuditLog.php
└── Services/
    ├── CameraService.php, CameraHttpService.php, CameraMqttService.php, ImageStorageService.php # Preserved
    ├── AttendanceProcessingService.php   # Punch pairing, shift resolution, hours math
    ├── LeaveService.php                  # Balance deduction, approval hooks
    ├── VisitorSyncService.php            # Temporary camera face provisioning & revocation
    └── ReportExportService.php           # CSV, Excel, PDF generation
```

### Frontend (`resources/js/`)
```
resources/js/
├── api/
│   └── client.js                         # Central Axios instance with Bearer token & interceptors
├── components/
│   ├── AppHeader.vue                     # Categorized tabs, user profile dropdown, notification bell
│   ├── AppKpiCards.vue                   # Contextual KPI cards based on active category
│   ├── NotificationBell.vue              # Real-time notification panel
│   ├── employees/                        # EmployeeDirectory, ProfileModal, FormModal
│   ├── schedules/                        # ShiftManager, ShiftAssignment, HolidayCalendar
│   ├── attendance/                       # AttendanceDashboard, DailyAttendanceRoster, Calendar, ManualPunch
│   ├── leaves/                           # LeaveRequestForm, ApprovalQueue, BalanceWidget, CalendarView
│   ├── visitors/                         # VisitorDashboard, CheckInWizard, CheckOutModal, Directory, Badge, Watchlist
│   ├── reports/                          # ReportsPage, Analytics charts, Export modal
│   ├── settings/                         # SystemSettings, AuditLogViewer, DepartmentManager
│   └── selfservice/                      # MyAttendance, MyPunches, MyLeaves, RegularizationModal
├── stores/
│   ├── authStore.js                      # Authenticated user, roles, permissions, token
│   ├── cameraStore.js                    # Preserved
│   ├── attendanceStore.js                # Live punches, today's roster, real-time KPI counts
│   ├── visitorStore.js                   # Active visits, expected visitors, real-time check-ins
│   └── notificationStore.js              # Real-time alert feed
└── views/
    └── LoginPage.vue                     # Modern auth login page
```
