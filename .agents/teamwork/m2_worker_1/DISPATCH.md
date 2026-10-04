## 2026-09-29T22:19:15Z
[Message] timestamp=2026-09-29T22:19:15Z sender=d4fa06ff-28af-4159-a539-e2654882c73e priority=MESSAGE_PRIORITY_HIGH content=You are m2_worker_1 (teamwork_preview_worker) for Milestone 2: Employee Management & Shift Scheduling (Phases 2 & 3).
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_worker_1/

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

MANDATORY INPUTS & BLUEPRINTS:
Read the following authoritative blueprints produced by the Explorers:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_1/handoff.md (Phase 2: Employee schema, Biometric Bridge, EmployeeController)
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_2/handoff.md (Phase 3: Shifts, Shift Assignments, Holidays, ShiftController, HolidayController)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_3/handoff.md (Frontend UI: Pinia stores, Employee and Schedule views, App.vue integration)
4. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
5. /home/wsk-devops2/AI-Camera-Integration/tasks.md (§ Phase 2 & § Phase 3)
6. /home/wsk-devops2/AI-Camera-Integration/tests/Feature/E2E/Tier1FeatureCoverageTest.php

YOUR TASK:
Implement all backend and frontend components for Milestone 2:

### 1. Database Migrations & Eloquent Models:
- `employees` migration and `Employee` model:
  - All fields: `id`, `personnel_id` (1-to-1 nullable unique FK with nullOnDelete), `user_id`, `organization_id`, `department_id`, `designation_id`, `location_id`, `reporting_manager_id`, `shift_id`, `employee_code` (unique), `first_name`, `last_name`, `employment_type`, `employment_status`, `date_of_joining`, `date_of_leaving`, `work_email`, `personal_email`, `phone`, `avatar`, `emergency_contact_name`, `emergency_contact_phone`, soft-deletes (`deleted_at`), timestamps.
  - Relationships: `personnel()`, `department()`, `designation()`, `organization()`, `location()`, `manager()`, `directReports()`, `user()`, `shift()`, `shiftAssignments()`.
  - M3 Attendance contract methods: `currentShift(Carbon $date)`, `isHoliday(Carbon $date)`, `isRestDay(Carbon $date)`.
- `shifts` migration and `Shift` model:
  - Fields: `id`, `organization_id`, `name`, `code`, `shift_start`, `shift_end`, `grace_period_minutes`, `early_out_threshold_minutes`, `half_day_threshold_hours`, `min_hours_full_day`, `is_overnight`, `break_duration_minutes`, `is_flexible`, `color`, `is_active`, timestamps.
  - Methods & accessors: `durationMinutes()`, `isDayShift()`, `isOvernight()`, `start_time` / `end_time` aliases.
- `employee_shift_assignments` migration and `EmployeeShiftAssignment` model:
  - Fields: `id`, `employee_id`, `shift_id`, `effective_from`, `effective_to`, `assigned_days` (JSON), `created_by`, timestamps.
  - Helper `appliesToDay(Carbon $date)` supporting numeric ISO days [1..7] and day names.
- `holidays` migration and `Holiday` model:
  - Fields: `id`, `organization_id`, `name`, `date`, `type` (public, company, optional), `is_recurring`, `applies_to` (JSON or null), timestamps.
- Update related models (`Personnel.php`, `User.php`, `Department.php`, `Organization.php`) with reciprocal relationships.

### 2. Biometric Bridge & Controllers:
- `EmployeeController`:
  - Full CRUD with filters by department, designation, status, organization.
  - Automatic biometric linkage: creating an employee with photo creates/links `Personnel` record, dispatching `SyncPersonnelJob` on queue `camera-sync`.
  - Updating status to suspended/terminated/resigned updates `personnel.person_type = 1` (blacklist) and syncs to camera.
  - Soft-deleting employee de-provisions face template via camera sync while preserving historical logs.
  - `GET /api/employees/{id}/attendance-summary`
  - `POST /api/employees/{id}/assign-shift`
  - `POST /api/employees/import` (CSV bulk onboarding)
  - `GET /api/employees/export` (CSV export)
- `ShiftController`: Full CRUD and `POST /api/shifts/bulk-assign`.
- `HolidayController`: Full CRUD for holidays.
- Seed default shifts (Standard Day, Night Shift, Flexible Shift) in `ShiftSeeder.php` or `DatabaseSeeder.php`.

### 3. Route & RBAC Wiring (`routes/api.php`):
- Wire `/api/employees`, `/api/shifts`, `/api/holidays` in the guarded route group with `permission:employees.view,employees.manage`, `permission:employees.delete`, `permission:schedules.view,schedules.manage`.

### 4. Frontend Suite:
- Pinia stores: `resources/js/stores/employeeStore.js` and `resources/js/stores/scheduleStore.js`.
- Vue components in `resources/js/components/employees/`:
  - `EmployeeDirectory.vue`, `EmployeeProfileModal.vue`, `EmployeeFormModal.vue`.
- Vue components in `resources/js/components/schedules/`:
  - `ShiftManager.vue`, `ShiftAssignment.vue`, `HolidayCalendar.vue`, `ScheduleHub.vue`.
- App navigation in `resources/js/App.vue`:
  - Integrate `👤 Employees` and `🕐 Schedules` tabs with role-based visibility.

### 5. Verification:
- Run `php artisan migrate`
- Run `php artisan db:seed`
- Run `php artisan test --filter=Tier1FeatureCoverageTest`
- Run `php artisan test --filter=EmployeeAndShiftManagementTest`
- Run full `php artisan test`
- Run `npm run build`
