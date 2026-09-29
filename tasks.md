# Implementation Tasks & Status

## Intelligent AI Camera Hub → Attendance & Visitor Management System

> **Legend:** ✅ Completed · 🔲 To Do · 🔄 Needs Enhancement
>
> Tasks are organized in implementation phases. Each phase builds on the previous.

---

## Phase 0 — Existing Foundation (Completed)

> Core AI camera integration platform — device management, face enrollment, real-time telemetry, and sync engine.

### 0.1 Database & Models

- [x] PostgreSQL 16 migrations: `devices`, `personnel`, `access_logs`, `stranger_snaps`, `sync_tasks`
- [x] Eloquent Models with UUIDs, casts, relationships, and online status accessors
- [x] `scheme` column on `devices` (HTTP/HTTPS support)
- [x] Extra personnel fields (`native`, `notes`, `mj_card_no`, `mj_card_from`)
- [x] Nullable `personnel_id` on `sync_tasks` for historical preservation

### 0.2 Services, Jobs & Event Broadcasting

- [x] `ImageStorageService`: Base64/upload/URL image processing to public storage
- [x] `CameraHttpService`: Synchronous LAN/WAN HTTP/HTTPS POST client with multi-port probing
- [x] `CameraMqttService`: Async MQTT downlink command dispatcher
- [x] `CameraService`: Facade orchestrating HTTP and MQTT services
- [x] `SyncPersonnelJob`: Queued job on `camera-sync` Redis queue (tries: 3, backoff: 5s)
- [x] `PersonnelObserver`: Auto-dispatch on created/updated/deleted
- [x] Broadcast Events: `AccessLogReceived`, `StrangerSnapReceived`, `DeviceStatusUpdated`

### 0.3 MQTT Ingestion Daemon

- [x] `MqttListenCommand`: Long-running daemon (`mqtt/face/#`)
- [x] `VerifyPush`/`RecPush`, `SnapPush`/`StrSnapPush`, `HeartBeat`, `Online`/`Offline` handling
- [x] Automatic `PushAck` and `Online-Ack` responses

### 0.4 REST API Endpoints

- [x] `DeviceController`: Full device fleet management (CRUD, probe, test, reboot, MQTT sync, time sync, backfill, factory reset, import, audit, search, subscribe, firmware upgrade)
- [x] `PersonnelController`: Face library CRUD with multipart/base64/URL photo upload and manual sync
- [x] `AccessLogController`: Paginated logs with multi-field search and filters
- [x] `StrangerSnapController`: Paginated stranger captures
- [x] `SyncTaskController`: Outbox queue inspection and retry
- [x] `DashboardStatsController`: Aggregated KPI metrics
- [x] `HttpWebhookController`: Camera HTTP webhook endpoints (`/Subscribe/heartbeat`, `/Verify`, `/Snap`)

### 0.5 Frontend SPA (Vue 3 + Vite + Tailwind CSS v4 + Pinia)

- [x] `echo.js`: Laravel Echo Reverb WebSocket connector
- [x] `cameraStore.js`: Pinia state with live buffers, audio alarm, and device tracking
- [x] `LiveTelemetry.vue`: Real-time access verification stream with filters, snapshot modals, similarity bars
- [x] `StrangerSnapsMonitor.vue`: Stranger gallery with one-click enrollment modal
- [x] `PersonnelManager.vue`: Full CRUD directory with photo preview, sync trigger, and schedules
- [x] `DeviceManager.vue`: 5-tab device config modal, live preview, audit, backfill, and hardware operations
- [x] `AccessLogsHistory.vue`: Filterable historical audit table with image inspection
- [x] `SyncTasksMonitor.vue`: Redis outbox queue monitor with retry actions
- [x] `App.vue`: Main shell with 5 KPI cards, 6-tab navigation, WebSocket listeners, and 10s stats polling
- [x] `CameraLivePreviewModal.vue`: WebGL H.264 live stream with HUD telemetry and frame capture
- [x] `DeviceAuditModal.vue`: Face library parity check, backfill triggers, and diagnostics
- [x] `HistoricalBackfillModal.vue`: Multi-device log backfill with date presets
- [x] Utilities: `cameraHqPlayer.js` (WebGL/WASM), `md5.js`, `date.js`, `notify.js`

---

## Phase 1 — Authentication, Authorization & Multi-Tenancy

> Secure the platform with user authentication, role-based access control, and organizational structure.

### 1.1 User Authentication

- [ ] Implement Laravel Sanctum or session-based authentication for SPA
- [ ] Build Login page (`LoginPage.vue`) with email/password form
- [ ] Build Registration page (admin-only user creation, or self-registration with approval)
- [ ] Implement "Forgot Password" flow with email reset link
- [ ] Add authenticated user profile page (name, email, avatar, change password)
- [ ] Protect all `/api/*` routes with `auth:sanctum` middleware
- [ ] Add CSRF and auth token handling in Axios interceptors
- [ ] Persist authenticated user state in Pinia (`authStore.js`)
- [ ] Add logout functionality with token revocation

### 1.2 Role-Based Access Control (RBAC)

- [ ] Create `roles` migration & model (`id`, `name`, `slug`, `description`)
- [ ] Create `permissions` migration & model (`id`, `name`, `slug`, `group`)
- [ ] Create `role_permission` pivot migration
- [ ] Create `user_role` pivot migration (users can have multiple roles)
- [ ] Seed default roles:
    - `super-admin` — Full system access
    - `admin` — Organization-level management
    - `hr-manager` — Attendance, leaves, employee management
    - `security` — Device management, live monitoring, stranger alerts
    - `receptionist` — Visitor management, check-in/check-out
    - `manager` — Department-level attendance oversight and approvals
    - `employee` — Self-service (own attendance, leave requests)
- [ ] Seed default permissions per module (e.g., `attendance.view`, `attendance.manage`, `visitors.checkin`, `devices.manage`, `personnel.create`, etc.)
- [ ] Create `CheckPermission` middleware for route-level authorization
- [ ] Create `@can` / `v-if` permission-aware UI rendering in Vue components
- [ ] Build Roles & Permissions management UI (admin only)

### 1.3 Organization Structure

- [ ] Create `organizations` migration & model (`id`, `name`, `code`, `logo`, `address`, `timezone`, `settings` JSON)
- [ ] Create `locations` / `sites` migration & model (`id`, `organization_id`, `name`, `address`, `timezone`, `coordinates`)
- [ ] Create `departments` migration & model (`id`, `organization_id`, `name`, `code`, `parent_id` for hierarchy, `head_id` FK to employees)
- [ ] Create `designations` / `job_titles` migration & model (`id`, `organization_id`, `name`, `level`)
- [ ] Add `organization_id`, `location_id` foreign keys to `devices` table
- [ ] Build Department Management CRUD UI
- [ ] Build Designation/Job Title Management CRUD UI
- [ ] Build Location/Site Management CRUD UI

---

## Phase 2 — Employee Management (Extending Personnel)

> Transform the generic "personnel" entity into a full employee management module with organizational context.

### 2.1 Employee Data Model

- [ ] Create `employees` migration extending/replacing `personnel`:
    - `id`, `personnel_id` (FK to personnel for face data linkage)
    - `employee_code` (unique badge/employee number)
    - `organization_id`, `department_id`, `designation_id`, `location_id`
    - `reporting_manager_id` (self-referencing FK)
    - `user_id` (FK to users — optional, for self-service login)
    - `employment_type` (full-time, part-time, contract, intern)
    - `employment_status` (active, on-leave, suspended, terminated, resigned)
    - `date_of_joining`, `date_of_leaving`
    - `work_email`, `personal_email`
    - `emergency_contact_name`, `emergency_contact_phone`
    - `shift_id` (FK to shifts — default assigned shift)
    - `created_at`, `updated_at`
- [ ] Create `Employee` Eloquent model with relationships to `Personnel`, `Department`, `Designation`, `User`, `Shift`, and `AttendanceRecord`
- [ ] Ensure backward compatibility: existing `personnel` table remains as the biometric face record, `employees` links to it via `personnel_id`

### 2.2 Employee Management API

- [ ] `EmployeeController`: Full CRUD with department/designation/status filters and search
- [ ] `GET /api/employees` — Paginated employee list with eager-loaded relationships
- [ ] `POST /api/employees` — Create employee (optionally creates linked `personnel` record and triggers camera sync)
- [ ] `PUT /api/employees/{id}` — Update employee details
- [ ] `DELETE /api/employees/{id}` — Soft-delete / terminate employee
- [ ] `GET /api/employees/{id}/attendance-summary` — Attendance overview for a specific employee
- [ ] `POST /api/employees/{id}/assign-shift` — Assign or change shift schedule
- [ ] Employee import from CSV/Excel (bulk onboarding)
- [ ] Employee export to CSV/Excel

### 2.3 Employee Management UI

- [ ] Build `EmployeeDirectory.vue` — Searchable employee list with department/status filters, grid and table views
- [ ] Build `EmployeeProfileModal.vue` — Full employee profile view with tabs:
    - Personal Info, Employment Details, Attendance Summary, Leave Balances, Access History
- [ ] Build `EmployeeFormModal.vue` — Create/edit employee form with department/designation dropdowns and photo upload
- [ ] Add employee import/export actions to the UI
- [ ] Add `👤 Employees` tab to main navigation in `App.vue`

---

## Phase 3 — Shift & Schedule Management

> Define work schedules, shifts, and calendar rules that drive attendance calculations.

### 3.1 Shift Definitions

- [ ] Create `shifts` migration & model:
    - `id`, `organization_id`, `name`, `code`
    - `shift_start` (TIME), `shift_end` (TIME)
    - `grace_period_minutes` (late tolerance, e.g., 15)
    - `early_out_threshold_minutes` (e.g., 30 — leaving X min early counts as early-out)
    - `half_day_threshold_hours` (e.g., 4 — less than X hours = half-day)
    - `min_hours_full_day` (e.g., 8)
    - `is_overnight` (BOOLEAN — for night shifts crossing midnight)
    - `break_duration_minutes` (e.g., 60 — deducted from total hours)
    - `is_flexible` (BOOLEAN — for flex-time / no fixed start-end)
    - `color` (for UI calendar display)
    - `is_active`, `created_at`, `updated_at`
- [ ] Seed default shifts: "Day Shift (9 AM – 6 PM)", "Night Shift (10 PM – 7 AM)", "Flexible"

### 3.2 Shift Assignment & Rotation

- [ ] Create `employee_shift_assignments` migration:
    - `id`, `employee_id`, `shift_id`
    - `effective_from` (DATE), `effective_to` (DATE, nullable for indefinite)
    - `assigned_days` (JSON array, e.g., `[1,2,3,4,5]` for Mon–Fri)
    - `created_by`, `created_at`, `updated_at`
- [ ] Support shift rotation: employees can have future-dated shift changes
- [ ] `ShiftController`: CRUD for shift definitions
- [ ] `GET /api/shifts` — List all shifts
- [ ] `POST /api/employees/{id}/shift-assignments` — Assign shift to employee
- [ ] Bulk shift assignment for entire departments

### 3.3 Holiday Calendar

- [ ] Create `holidays` migration & model:
    - `id`, `organization_id`, `name`
    - `date` (DATE), `type` (public, company, optional)
    - `is_recurring` (BOOLEAN — annual recurrence)
    - `applies_to` (JSON — specific departments/locations or `null` for all)
    - `created_at`, `updated_at`
- [ ] `HolidayController`: CRUD for holidays
- [ ] Build Holiday Calendar UI with month/year view

### 3.4 Shift & Schedule UI

- [ ] Build `ShiftManager.vue` — CRUD for shift definitions with time pickers and color selectors
- [ ] Build `ShiftAssignment.vue` — Drag-and-drop or bulk shift assignment calendar per department
- [ ] Build `HolidayCalendar.vue` — Visual calendar with holiday markers and CRUD modal
- [ ] Add `🕐 Schedules` tab to main navigation

---

## Phase 4 — Attendance Processing Engine

> The core business logic — pairing raw access logs into attendance records and computing metrics.

### 4.1 Attendance Records

- [ ] Create `attendance_records` migration & model:
    - `id`, `employee_id`, `date` (DATE)
    - `shift_id` (the applied shift for this day)
    - `first_clock_in` (TIMESTAMP — earliest entry event)
    - `last_clock_out` (TIMESTAMP — latest exit event)
    - `total_work_hours` (DECIMAL — computed hours minus break)
    - `overtime_hours` (DECIMAL — hours beyond shift requirement)
    - `status` ENUM: `present`, `absent`, `late`, `early_out`, `late_and_early_out`, `half_day`, `on_leave`, `holiday`, `weekend`, `rest_day`
    - `is_late` (BOOLEAN), `late_minutes` (INT)
    - `is_early_out` (BOOLEAN), `early_out_minutes` (INT)
    - `source` ENUM: `auto` (from camera), `manual` (HR override), `regularized` (employee request approved)
    - `remarks` (TEXT — HR notes)
    - `created_at`, `updated_at`
    - Unique composite index on (`employee_id`, `date`)

### 4.2 Attendance Punch Log (Raw Clock Events)

- [ ] Create `attendance_punches` migration & model:
    - `id`, `employee_id`, `access_log_id` (FK, nullable — links to camera `access_logs`)
    - `device_id`, `punch_time` (TIMESTAMP)
    - `direction` ENUM: `in`, `out`, `unknown` (based on device configuration or alternating logic)
    - `source` ENUM: `camera_auto` (from MQTT/webhook), `manual` (HR entry), `kiosk`, `mobile`
    - `location_id`
    - `created_at`
- [ ] Configure devices with `direction` attribute (entry camera vs. exit camera) or use alternating in/out logic

### 4.3 Attendance Processing Service

- [ ] Create `AttendanceProcessingService`:
    - `processAccessLog(AccessLog $log)` — Called from `MqttListenCommand` and `HttpWebhookController` after every verification event:
        1. Resolve `access_log` → `employee` via `customize_id` lookup
        2. Determine punch direction (`in`/`out`) based on device direction config or alternating logic
        3. Create `attendance_punch` record
        4. Upsert `attendance_record` for the date: update `first_clock_in`, `last_clock_out`
        5. Recompute derived fields: `total_work_hours`, `is_late`, `late_minutes`, `is_early_out`, `early_out_minutes`, `status`, `overtime_hours`
    - `processDay(Employee $employee, Carbon $date)` — Full recomputation for a specific employee-day (used for manual corrections, shift changes, regularization)
    - `processBulkDay(Carbon $date)` — End-of-day batch job to finalize all attendance records, mark absences, and handle edge cases
    - `resolveShift(Employee $employee, Carbon $date)` — Determine which shift applies to an employee on a given date (considers assignments, holidays, weekends)
    - `calculateStatus(AttendanceRecord $record, Shift $shift)` — Compute attendance status based on shift rules and thresholds
    - `isHoliday(Carbon $date, Employee $employee)` — Check if date is a holiday for this employee's org/dept/location
    - `isRestDay(Carbon $date, Employee $employee)` — Check if date falls on assigned rest days

### 4.4 Attendance Processing Jobs & Scheduler

- [ ] Create `ProcessAttendancePunchJob` — Queued job dispatched after each camera verification event
- [ ] Create `DailyAttendanceFinalizerJob` — Scheduled job (e.g., 11:59 PM daily) that:
    - Marks all employees without any punch as `absent` (unless on leave or holiday)
    - Finalizes open attendance records (employees who clocked in but didn't clock out)
    - Calculates overtime for completed days
- [ ] Create `MonthlyAttendanceSummaryJob` — End-of-month aggregation for payroll export
- [ ] Register jobs in Laravel scheduler (`app/Console/Kernel.php` or `routes/console.php`)

### 4.5 Attendance API Endpoints

- [ ] `AttendanceController`:
    - `GET /api/attendance/daily?date=&department_id=&status=` — Daily attendance roster
    - `GET /api/attendance/employee/{id}?month=&year=` — Monthly attendance view for an employee
    - `GET /api/attendance/summary?from=&to=&department_id=` — Aggregated attendance statistics
    - `POST /api/attendance/manual-entry` — HR manual clock-in/out entry
    - `PUT /api/attendance/{id}/override` — HR override attendance status with remarks
    - `GET /api/attendance/punches?employee_id=&date=` — Raw punch log for a specific day
- [ ] `AttendanceReportController`:
    - `GET /api/reports/attendance/daily` — Daily attendance report (present/absent/late counts)
    - `GET /api/reports/attendance/monthly` — Monthly attendance summary per employee
    - `GET /api/reports/attendance/department` — Department-wise attendance analytics
    - `GET /api/reports/attendance/export?format=csv|xlsx|pdf` — Exportable attendance reports

### 4.6 Attendance UI

- [ ] Build `AttendanceDashboard.vue` — Today's attendance overview:
    - Real-time counters: Total Employees, Present, Absent, Late, On Leave
    - Live "Who's In / Who's Out" panel with employee photos and clock-in time
    - Late arrivals ticker
    - Department-wise attendance percentage bars
- [ ] Build `DailyAttendanceRoster.vue` — Tabular view of all employees for a selected date:
    - Columns: Photo, Name, Department, Shift, Clock In, Clock Out, Total Hours, Status Badge, Actions
    - Filters: Date picker, department, status, search
    - Actions: View punches, override status, add manual entry
- [ ] Build `EmployeeAttendanceCalendar.vue` — Monthly calendar view for a single employee:
    - Color-coded day cells (green=present, red=absent, yellow=late, blue=leave, gray=holiday/weekend)
    - Click on day to view punch details
    - Monthly summary stats (total present/absent/late/leave days, avg hours)
- [ ] Build `ManualAttendanceEntry.vue` — Modal for HR to add manual clock-in/out
- [ ] Build `AttendanceReports.vue` — Report generation page with date range, department filter, and export buttons (CSV, PDF)
- [ ] Add `📊 Attendance` tab to main navigation in `App.vue`
- [ ] Update Dashboard KPI cards in `App.vue`:
    - Add: "Present Today", "Absent Today", "Late Arrivals", "On Leave"

### 4.7 Real-Time Attendance Broadcasting

- [ ] Create `AttendancePunchReceived` broadcast event (channel: `attendance`)
- [ ] Update `cameraStore.js` or create `attendanceStore.js` Pinia store for live attendance state
- [ ] Subscribe to `attendance` WebSocket channel in `App.vue` for real-time "clock-in" notifications on the dashboard
- [ ] Display real-time toast notifications when employees clock in/out

---

## Phase 5 — Leave Management

> Employee leave requests, approvals, balances, and integration with attendance.

### 5.1 Leave Data Model

- [ ] Create `leave_types` migration & model:
    - `id`, `organization_id`, `name` (e.g., Vacation, Sick, Personal, Maternity, Paternity, Emergency)
    - `code`, `max_days_per_year` (INT), `is_paid` (BOOLEAN)
    - `is_carry_forward` (BOOLEAN), `max_carry_forward_days`
    - `requires_attachment` (BOOLEAN — e.g., medical certificate for sick leave > 2 days)
    - `color` (for calendar display)
    - `is_active`, `created_at`, `updated_at`
- [ ] Create `leave_balances` migration & model:
    - `id`, `employee_id`, `leave_type_id`, `year` (INT)
    - `allocated` (DECIMAL), `used` (DECIMAL), `carried_forward` (DECIMAL)
    - `remaining` (computed or stored)
    - Unique composite index on (`employee_id`, `leave_type_id`, `year`)
- [ ] Create `leave_requests` migration & model:
    - `id`, `employee_id`, `leave_type_id`
    - `start_date`, `end_date`, `total_days` (DECIMAL, supports half-days)
    - `is_half_day` (BOOLEAN), `half_day_period` (ENUM: `first_half`, `second_half`)
    - `reason` (TEXT)
    - `attachment_path` (for medical certificates, etc.)
    - `status` ENUM: `pending`, `approved`, `rejected`, `cancelled`
    - `approved_by` (FK to users), `approved_at`, `rejection_reason`
    - `created_at`, `updated_at`
- [ ] Seed default leave types (Vacation Leave, Sick Leave, Personal Leave, etc.)

### 5.2 Leave API Endpoints

- [ ] `LeaveTypeController`: CRUD for leave type definitions (admin)
- [ ] `LeaveRequestController`:
    - `POST /api/leave-requests` — Employee submits leave request
    - `GET /api/leave-requests?status=&employee_id=&department_id=` — List with filters
    - `PUT /api/leave-requests/{id}/approve` — Manager/HR approves
    - `PUT /api/leave-requests/{id}/reject` — Manager/HR rejects with reason
    - `PUT /api/leave-requests/{id}/cancel` — Employee cancels own pending request
- [ ] `LeaveBalanceController`:
    - `GET /api/leave-balances?employee_id=&year=` — View balances
    - `POST /api/leave-balances/allocate` — HR bulk-allocate leave balances for a year
    - `PUT /api/leave-balances/{id}/adjust` — HR manual adjustment with reason

### 5.3 Leave Processing Service

- [ ] Create `LeaveService`:
    - Validate leave requests against balances, overlapping requests, and blackout dates
    - Auto-deduct leave balance on approval
    - Restore balance on cancellation or rejection
    - Mark attendance records as `on_leave` for approved leave dates
    - Trigger notification to manager on new request
    - Trigger notification to employee on approval/rejection
    - Annual leave balance reset and carry-forward computation

### 5.4 Leave UI

- [ ] Build `LeaveRequestForm.vue` — Employee leave request form with leave type, date range, half-day option, reason, and attachment upload
- [ ] Build `LeaveApprovalQueue.vue` — Manager view of pending leave requests with approve/reject actions
- [ ] Build `LeaveBalanceWidget.vue` — Employee's leave balance summary card (per leave type: allocated, used, remaining)
- [ ] Build `LeaveCalendarView.vue` — Team/department calendar showing who is on leave on each day
- [ ] Add `🏖️ Leave` tab or sub-tab under Attendance in main navigation

---

## Phase 6 — Visitor Management System

> Complete visitor lifecycle — pre-registration, check-in, badge provisioning, host notification, and check-out.

### 6.1 Visitor Data Model

- [ ] Create `visitors` migration & model:
    - `id`, `organization_id`, `location_id`
    - `first_name`, `last_name`, `email`, `phone`, `company` (visitor's organization)
    - `id_type` (national ID, passport, driver's license, company ID)
    - `id_number`
    - `photo_path`, `photo_base64`
    - `is_blocked` (BOOLEAN — watchlist/blocklist)
    - `block_reason`
    - `notes`
    - `created_at`, `updated_at`
    - (Stores the visitor's identity — can have many visits)
- [ ] Create `visits` migration & model:
    - `id`, `visitor_id`, `organization_id`, `location_id`
    - `host_employee_id` (FK to employees — who they are visiting)
    - `purpose` ENUM: `meeting`, `interview`, `delivery`, `maintenance`, `contractor`, `personal`, `vip`, `other`
    - `purpose_detail` (TEXT)
    - `visitor_type` ENUM: `walk_in`, `pre_registered`, `recurring`, `contractor`, `vip`
    - `badge_number` (temporary badge/pass issued)
    - `expected_arrival` (TIMESTAMP — for pre-registration)
    - `check_in_at` (TIMESTAMP)
    - `check_out_at` (TIMESTAMP)
    - `check_in_device_id` (FK — which camera verified entry)
    - `check_out_device_id`
    - `status` ENUM: `expected`, `checked_in`, `checked_out`, `cancelled`, `no_show`
    - `personnel_id` (FK — if visitor face was enrolled to cameras for access)
    - `nda_signed` (BOOLEAN)
    - `nda_document_path`
    - `escort_required` (BOOLEAN)
    - `items_carried` (TEXT — laptop, tools, etc.)
    - `vehicle_plate` (VARCHAR)
    - `remarks` (TEXT)
    - `created_by` (FK to users — receptionist who created the visit)
    - `created_at`, `updated_at`
- [ ] Create `visitor_access_logs` migration (or extend `access_logs` with `visitor_id` FK):
    - Links camera access events to visitor records

### 6.2 Visitor Pre-Registration & Invitation

- [ ] `VisitorPreRegistrationController`:
    - `POST /api/visits/pre-register` — Employee/host pre-registers an expected visitor
    - `GET /api/visits/expected?date=` — List expected visitors for today
    - `POST /api/visits/{id}/cancel` — Cancel a pre-registered visit
- [ ] Optional: Send visitor invitation email/SMS with QR code or visit reference number
- [ ] Optional: Self-service visitor portal (public page) for pre-registration

### 6.3 Visitor Check-In / Check-Out

- [ ] `VisitorController`:
    - `POST /api/visitors` — Register new visitor identity (first-time)
    - `GET /api/visitors?search=` — Search existing visitors by name, phone, email, ID
    - `GET /api/visitors/{id}` — Visitor profile with visit history
    - `PUT /api/visitors/{id}` — Update visitor info
    - `POST /api/visitors/{id}/block` — Add to watchlist/blocklist
- [ ] `VisitController`:
    - `POST /api/visits` — Create a new visit and check-in (walk-in or match pre-registration)
    - `PUT /api/visits/{id}/check-in` — Check-in a pre-registered visitor
    - `PUT /api/visits/{id}/check-out` — Check-out visitor
    - `GET /api/visits?status=&date=&host_id=` — Visit history with filters
    - `GET /api/visits/active` — Currently checked-in visitors
- [ ] On check-in:
    - Capture visitor photo (webcam or upload)
    - Optionally enroll visitor face to cameras as temporary whitelist personnel (`temp_valid: 1`, `valid_begin`, `valid_end`)
    - Issue badge number
    - Notify host employee (WebSocket push, email, or SMS)
- [ ] On check-out:
    - Record checkout timestamp
    - Optionally remove visitor face from cameras (`DELETE` sync)
    - Collect badge

### 6.4 Visitor-Camera Integration

- [ ] Create `VisitorSyncService`:
    - `enrollVisitorToCamera(Visit $visit)` — Creates temporary `personnel` record linked to visitor, triggers `SyncPersonnelJob` to push face to cameras with time-limited validity
    - `removeVisitorFromCamera(Visit $visit)` — Triggers face deletion from cameras on check-out or visit expiry
- [ ] Create `ExpireVisitorAccessJob` — Scheduled job to auto-expire and remove visitor faces from cameras after visit ends or at end of day
- [ ] Update `MqttListenCommand` to detect visitor verification events and update visit status accordingly

### 6.5 Visitor UI

- [ ] Build `VisitorDashboard.vue` — Visitor management overview:
    - Today's expected visitors list
    - Currently checked-in visitors with host info
    - Recent check-outs
    - KPI cards: Expected Today, Checked In, Checked Out, Overdue
- [ ] Build `VisitorCheckInWizard.vue` — Step-by-step check-in flow:
    1. Search existing visitor or create new (name, phone, email, company, ID)
    2. Capture/upload photo
    3. Select host employee
    4. Choose purpose, add remarks, items carried, vehicle plate
    5. NDA/agreement acknowledgment (optional digital signature)
    6. Issue badge number → Confirm check-in
- [ ] Build `VisitorCheckOutModal.vue` — Quick check-out with badge collection and optional feedback
- [ ] Build `VisitorDirectory.vue` — Searchable visitor history with visit frequency analytics
- [ ] Build `VisitorPreRegisterForm.vue` — Form for employees to pre-register expected visitors
- [ ] Build `VisitorBadge.vue` — Printable visitor badge/pass template (name, photo, host, date, badge #)
- [ ] Build `WatchlistManager.vue` — Manage blocked visitors with photo and reason
- [ ] Add `🏢 Visitors` tab to main navigation in `App.vue`
- [ ] Update Dashboard KPI cards: "Visitors Today", "Currently On-Site"

### 6.6 Visitor Notifications

- [ ] Create `VisitorArrivedNotification` — Notify host employee when their visitor checks in
- [ ] Create `VisitorPreRegisteredNotification` — Confirm pre-registration to host and send invitation to visitor
- [ ] Broadcast visitor events via Reverb for real-time dashboard updates
- [ ] Create `VisitorCheckedIn` and `VisitorCheckedOut` broadcast events

---

## Phase 7 — Notifications & Alerts Engine

> Centralized notification system for attendance, visitor, and security events.

### 7.1 Notification Infrastructure

- [ ] Create `notifications` migration (Laravel built-in `database` notification channel)
- [ ] Create `notification_preferences` migration (per-user channel preferences: in-app, email, SMS)
- [ ] Implement notification channels:
    - In-app (database + WebSocket real-time)
    - Email (SMTP via Laravel Mail)
    - SMS (optional — Twilio, Vonage, or local provider integration)
- [ ] Build `NotificationBell.vue` — Header notification bell with unread count badge and dropdown panel
- [ ] Build `NotificationsPage.vue` — Full notification history with mark-as-read and filters

### 7.2 Attendance Notifications

- [ ] Notify employee on late clock-in
- [ ] Notify manager of team's daily absent employees (morning summary)
- [ ] Notify employee when attendance is manually overridden by HR
- [ ] Notify employee of leave request approval/rejection

### 7.3 Security & Stranger Alerts

- [ ] 🔄 Enhance existing stranger snap alerts with configurable notification routing (email/SMS to security team)
- [ ] Notify security team when a blocked/watchlisted person is detected by camera
- [ ] Daily security summary email (total stranger detections, denied entries)

---

## Phase 8 — Reporting & Analytics

> Comprehensive reporting for HR, management, and security teams.

### 8.1 Attendance Reports

- [ ] Daily Attendance Report: Present, absent, late, leave counts per department
- [ ] Monthly Attendance Summary: Per-employee monthly breakdown (days present, absent, late, leave, overtime hours)
- [ ] Department Attendance Trends: Line/bar charts showing attendance percentages over time
- [ ] Tardiness Report: Employees with highest late arrivals over a period
- [ ] Overtime Report: Overtime hours per employee per month
- [ ] Working Hours Report: Average daily hours per employee/department

### 8.2 Visitor Reports

- [ ] Daily Visitor Log: All visitors for a date with check-in/out times, hosts, and purposes
- [ ] Visitor Frequency Report: Most frequent visitors
- [ ] Purpose Breakdown: Pie/bar chart of visit purposes
- [ ] Host Meeting Report: Which employees receive the most visitors
- [ ] Average Visit Duration: By purpose and visitor type

### 8.3 Security & Access Reports

- [ ] 🔄 Enhance Access Audit Log with exportable filtered data
- [ ] Stranger Detection Summary: Frequency by camera/time-of-day
- [ ] Denied Entry Report: All rejected verifications with reason analysis
- [ ] Device Uptime Report: Camera online/offline history

### 8.4 Report Export Engine

- [ ] Implement CSV export for all report types
- [ ] Implement Excel (XLSX) export using `maatwebsite/excel` or `openspout/openspout`
- [ ] Implement PDF export using `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`
- [ ] Scheduled email reports (daily/weekly/monthly digest to HR/management)

### 8.5 Analytics Dashboard UI

- [ ] Build `ReportsPage.vue` — Report selection hub with date range, filters, and format options
- [ ] Build chart components using a lightweight chart library (e.g., `chart.js` or `apexcharts`)
- [ ] Add `📈 Reports` tab to main navigation

---

## Phase 9 — Attendance Self-Service (Employee Portal)

> Allow employees to view their own records, request corrections, and manage leaves.

### 9.1 Employee Self-Service Features

- [ ] My Attendance: Monthly calendar view of own clock-in/out times and status
- [ ] My Punch Log: Daily detail view of all punches with timestamps
- [ ] Attendance Regularization Request: Submit correction for missing/wrong punch with reason and manager approval
    - `regularization_requests` migration: `employee_id`, `date`, `requested_in`, `requested_out`, `reason`, `status`, `approved_by`
- [ ] My Leave Balances: View remaining leave days per type
- [ ] My Leave Requests: Submit and track leave requests
- [ ] My Profile: View/edit personal info, emergency contacts

### 9.2 Manager Self-Service Features

- [ ] Team Attendance: View attendance of direct reports
- [ ] Approval Queue: Pending leave requests and regularization requests from team
- [ ] Team Calendar: Overlay calendar showing team availability

---

## Phase 10 — System Configuration & Administration

> Global system settings, audit trail, and administrative tools.

### 10.1 System Settings

- [ ] Create `settings` migration & model (key-value with type casting, scoped by organization):
    - `attendance.auto_process` (BOOLEAN — auto-process camera events into attendance)
    - `attendance.late_grace_minutes` (INT — global default)
    - `attendance.overtime_threshold_minutes` (INT — min minutes beyond shift to count as OT)
    - `attendance.weekend_days` (JSON — e.g., `[0, 6]` for Sat/Sun)
    - `visitor.require_photo` (BOOLEAN)
    - `visitor.require_nda` (BOOLEAN)
    - `visitor.auto_checkout_time` (TIME — auto-expire at end of day)
    - `visitor.max_visit_duration_hours` (INT)
    - `visitor.enroll_face_to_camera` (BOOLEAN — auto-enroll visitor face)
    - `notification.email_enabled` (BOOLEAN)
    - `notification.sms_enabled` (BOOLEAN)
- [ ] Build `SystemSettings.vue` — Admin settings page with grouped toggles and inputs
- [ ] Add `⚙️ Settings` tab to main navigation (admin only)

### 10.2 Audit Trail

- [ ] Create `audit_logs` migration & model:
    - `id`, `user_id`, `action` (created, updated, deleted, approved, rejected, etc.)
    - `auditable_type`, `auditable_id` (polymorphic — which model was affected)
    - `old_values` (JSON), `new_values` (JSON)
    - `ip_address`, `user_agent`
    - `created_at`
- [ ] Implement audit logging via Eloquent model events or use `spatie/laravel-activitylog`
- [ ] Build `AuditLogViewer.vue` — Filterable audit trail browser (admin only)

### 10.3 Data Maintenance

- [ ] Implement data retention policies (auto-archive access logs older than X months)
- [ ] Database backup reminder/integration
- [ ] System health dashboard (Redis, PostgreSQL, MQTT broker, Reverb status)

---

## Phase 11 — Device Enhancements for Attendance & Visitors

> Extend camera device configuration to support attendance and visitor workflows.

### 11.1 Device Role & Direction Configuration

- [ ] Add `device_role` ENUM to `devices`: `entry`, `exit`, `bidirectional`, `visitor_kiosk`
- [ ] Add `location_id` FK to `devices` — Associate cameras with physical locations/sites
- [ ] Add `department_ids` JSON to `devices` — Optional department scoping (cameras serving specific departments)
- [ ] Update `DeviceManager.vue` to configure role, direction, and location per camera
- [ ] Use device role in attendance processing to determine punch direction (`in` vs `out`)

### 11.2 Visitor Kiosk Mode

- [ ] Implement optional kiosk mode UI for visitor self-check-in at reception terminals
- [ ] Camera auto-detect + face capture workflow for kiosk
- [ ] Print visitor badge directly from kiosk

---

## Phase 12 — Integration & API Documentation

> External system integrations and developer documentation.

### 12.1 Payroll Integration

- [ ] Design payroll export data format (CSV/API) containing:
    - Employee code, name, department, total days present, absent, late, leave, overtime hours, total working hours
- [ ] Build `PayrollExportController`:
    - `GET /api/payroll/export?month=&year=&format=csv|json` — Generate payroll-ready attendance data
- [ ] Support integration hooks for popular payroll systems (configurable field mapping)

### 12.2 API Documentation

- [ ] Install and configure `knuckleswtf/scribe` or `dedoc/scramble` for auto-generated API docs
- [ ] Document all existing and new API endpoints with request/response examples
- [ ] Publish interactive API documentation at `/docs/api`

### 12.3 Mobile Considerations

- [ ] Ensure all Vue 3 UI views are fully responsive for tablet and mobile use
- [ ] Consider PWA (Progressive Web App) manifest for home-screen installation
- [ ] Optional: Mobile API endpoints for future native app (employee self-service)

---

## Implementation Priority & Dependencies

```
Phase 1  (Auth & RBAC)           ──► Required before all other phases
Phase 2  (Employees)             ──► Required for Phase 3, 4, 5, 6
Phase 3  (Shifts & Schedules)    ──► Required for Phase 4
Phase 4  (Attendance Engine)     ──► Core deliverable
Phase 5  (Leave Management)      ──► Depends on Phase 4
Phase 6  (Visitor Management)    ──► Independent of Phase 4/5, depends on Phase 1+2
Phase 7  (Notifications)         ──► Can run parallel after Phase 1
Phase 8  (Reports & Analytics)   ──► Depends on Phase 4+5+6 data
Phase 9  (Self-Service Portal)   ──► Depends on Phase 1+4+5
Phase 10 (System Admin)          ──► Can run parallel after Phase 1
Phase 11 (Device Enhancements)   ──► Depends on Phase 4+6
Phase 12 (Integration & Docs)    ──► Final phase, depends on all above
```
