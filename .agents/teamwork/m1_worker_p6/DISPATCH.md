## 2026-10-07T06:13:02Z
You are Worker M1 (Database & Schema Engineer) for Phase 6 Performance Optimization in AI-Camera-Integration.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_worker_p6

Read these documents first before beginning:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_worker_p6/DISPATCH.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_1/survey_db_report.md

Exclusive Write Ownership (DO NOT modify files outside this list):
- app/Services/AttendanceProcessingService.php
- app/Http/Controllers/VisitorController.php
- database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php
- app/Http/Controllers/DashboardStatsController.php
- app/Http/Controllers/LeaveController.php
- app/Http/Controllers/OrganizationController.php

Your Tasks:
1. Task 6.1: Eliminate Non-SARGable whereDate() expressions
   - In `app/Services/AttendanceProcessingService.php` (around lines 59-63): replace whereDate on punch_time with SARGable `whereBetween('punch_time', [$startOfDay, $endOfDay])`. Note: use `$punchTime->copy()->startOfDay()` and `$punchTime->copy()->endOfDay()` to prevent mutating Carbon instances.
   - In `app/Http/Controllers/VisitorController.php` (around lines 141-144): replace `whereDate('expected_arrival', ...)` with `whereBetween('expected_arrival', [$startOfDay, $endOfDay])`.
2. Task 6.2: Add Missing Composite & Foreign Key Indexes for Telemetry & Punches
   - Create migration: `database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php`
   - Add indexes:
     - `access_logs`: composite index `idx_access_logs_device_id_captured_at` on `['device_id', 'captured_at']`
     - `attendance_punches`: foreign key index `idx_attendance_punches_device_id` on `'device_id'`
     - `notifications`: composite index `idx_notifications_notifiable_created_at` on `['notifiable_type', 'notifiable_id', 'created_at']`
     - `notifications`: composite index `idx_notifications_notifiable_read_at` on `['notifiable_type', 'notifiable_id', 'read_at']`
   - Add proper `down()` method dropping the added indexes. Use `Schema::hasTable()` defensive checks.
   - Execute migration using `php artisan migrate`.
3. Task 6.3: Optimize Unbounded Table Scan on sync_tasks in Dashboard Stats
   - In `app/Http/Controllers/DashboardStatsController.php` (around lines 68-74): scope the query with `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])` so PostgreSQL and SQLite use `sync_tasks_status_index` rather than a full table scan.
4. Task 6.4: Paginate and Column-Constrain Wide Read Endpoints
   - In `app/Http/Controllers/LeaveController.php` (`listBalances`, lines 99-120): paginate `$query->paginate($perPage)` (default perPage = 50) and constrain eager loaded relations `with(['employee:id,first_name,last_name,employee_code', 'leaveType:id,name,code'])` and select necessary columns.
   - In `app/Http/Controllers/OrganizationController.php`:
     - `listLocations`: select required columns, constrain `organization:id,name,code`, paginate `$query->orderBy('name')->paginate($perPage)`.
     - `listDepartments`: select required columns, constrain `parent:id,name,code` and `children:id,name,code,parent_id`, paginate `$query->orderBy('name')->paginate($perPage)`.
     - `listDesignations`: select required columns, constrain `organization:id,name,code`, paginate `$query->orderBy('level')->orderBy('name')->paginate($perPage)`.
   - Ensure response format remains compatible with Vue stores (`res.data.data || res.data || []`).
