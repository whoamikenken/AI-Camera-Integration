# Orchestrator Dispatch Directive — Phase 6 Performance Optimization (Tasks 6.1 – 6.13)

## Objective
Execute all 13 performance optimization tasks in Phase 6 of `tasks-performance.md` for the Intelligent AI Camera Hub (`AI-Camera-Integration`), resolving database query bottlenecks, compute/memory overhead, Redis locking and telemetry caching issues, and frontend real-time telemetry mismatches with full test coverage and zero regressions.

- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration`
- **Orchestrator Workspace**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7`
- **Original Request**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- **Reference Task Matrix**: `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md` (Phase 6: Tasks 6.1 – 6.13)
- **Pre-existing Survey Reports & Scope**:
  - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_6/SCOPE.md`
  - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_1/survey_db_report.md`
  - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_2/survey_compute_cache_report.md`
  - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_3/survey_frontend_test_report.md`
- **Integrity Mode**: demo

---

## Detailed Task Breakdown

### R1. Database & Schema Optimization (Tasks 6.1 – 6.4)
- **Task 6.1: Eliminate Non-SARGable `whereDate()` Expressions Across Attendance & Visitor Engines**
  - Files: `app/Services/AttendanceProcessingService.php:59-63`, `app/Http/Controllers/VisitorController.php:142-144`
  - Replace `AttendancePunch::where('employee_id', $empId)->whereDate('punch_time', $date)->first()` with SARGable range query `whereBetween('punch_time', [$startOfDay, $endOfDay])` to utilize composite index `['employee_id', 'punch_time']`.
  - Replace `Visit::whereDate('expected_arrival', $date)` with `whereBetween('expected_arrival', [$startOfDay, $endOfDay])` to leverage `idx_visits_expected_arrival_status`.
- **Task 6.2: Add Missing Composite & Foreign Key Indexes for Telemetry & Punches**
  - Files: `database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php`
  - Add composite index on `access_logs(device_id, captured_at DESC)` to eliminate filesorts on camera telemetry feeds (`DeviceController::audit` & telemetry endpoints).
  - Add foreign key index on `attendance_punches(device_id)` to accelerate device-specific attendance queries.
  - Add composite index on `notifications(notifiable_type, notifiable_id, created_at DESC)` and `notifications(notifiable_type, notifiable_id, read_at)` to accelerate user notification lookups.
- **Task 6.3: Optimize Unbounded Table Scan on `sync_tasks` in Dashboard Stats**
  - Files: `app/Http/Controllers/DashboardStatsController.php:68-74`
  - Scope `SyncTask::toBase()->selectRaw(...)` with `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])` to allow PostgreSQL to use the `sync_tasks_status_index` instead of sequentially scanning the entire historical table.
- **Task 6.4: Paginate and Column-Constrain Wide Read Endpoints in Leave & Organization Modules**
  - Files: `app/Http/Controllers/LeaveController.php:99-120`, `app/Http/Controllers/OrganizationController.php:123-137, 222-243, 290-309`
  - Paginate `LeaveController::listBalances()` with `paginate($perPage)` and constrain relationships: `with(['employee:id,first_name,last_name,employee_code', 'leaveType:id,name,code'])`.
  - Add pagination and column constraints to `listLocations()`, `listDepartments()`, and `listDesignations()`.

### R2. Application Runtime & Compute Overhaul (Tasks 6.5 – 6.7)
- **Task 6.5: Eliminate $O(N)$ Database Queries in `Employee::isRestDay` Inside Summary Loop**
  - Files: `app/Http/Controllers/EmployeeController.php:263-269`, `app/Models/Employee.php:205-213`
  - Pre-fetch all overlapping `EmployeeShiftAssignment` records for the requested range once into memory and evaluate rest days against the collection.
- **Task 6.6: Eliminate Linear $O(N \times M)$ Collection Scan and Large Outbox Pull in `DeviceController::audit()`**
  - Files: `app/Http/Controllers/DeviceController.php:509-514, 562-564`
  - Key `$localPersonnel` by `customize_id` (`$localPersonnel->keyBy('customize_id')`) to convert `$localPersonnel->contains('customize_id', $cId)` from an $O(N)$ linear scan into an $O(1)$ hash map lookup.
  - Fetch only the most recent sync task per personnel for the device via SQL (`DISTINCT ON (personnel_id)` on PostgreSQL or subquery) instead of loading all historical device sync tasks and grouping in PHP collection memory.
- **Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`**
  - Files: `app/Http/Controllers/DeviceAlertController.php:117-122`
  - Replace the `foreach ($alerts as $alert) { $alert->update(...); }` loop with a single bulk query: `DeviceAlert::whereIn('id', $validated['ids'])->update($updateData)`.
  - Invalidate cached alert stats and dispatch notifications efficiently.

### R3. Caching & Telemetry Pipeline Optimization (Tasks 6.8 – 6.11)
- **Task 6.8: Eliminate Blocking Redis `KEYS` Command in Bulk Shift Assignment**
  - Files: `app/Http/Controllers/ShiftController.php:298-311`
  - Remove `$redis->keys($prefix . $cachePattern)` inside the `foreach ($employeeIds)` loop. Implement versioned cache keys (`emp_shift_v:{$employeeId}` counter where eviction is an $O(1)$ `INCR`), or track active keys in a Redis set per employee.
- **Task 6.9: Cache Pre-Enrolled Device Existence in High-Frequency MQTT Telemetry Stream**
  - Files: `app/Console/Commands/MqttListenCommand.php:243-246, 333-336, 410-413`
  - Cache known active `device_id` values in memory or Redis for 10 minutes (`device_registered:{$deviceId}`) to eliminate redundant database SELECT queries during 100+ events/sec telemetry bursts.
- **Task 6.10: Cache Biometric `customize_id` to Employee Mapping in Punch Ingestion**
  - Files: `app/Jobs/ProcessAttendancePunchJob.php:33-46`
  - Pre-cache the bidirectional identity bridge (`emp_custom_id:{$customizeId} => $employeeId`) in Redis with 1-hour TTL, evicting on `EmployeeObserver` and `PersonnelObserver` changes.
- **Task 6.11: Cache Invalidation Engine for Device Alerts & Public Settings**
  - Files: `app/Http/Controllers/DeviceAlertController.php:96, 120`, `app/Http/Controllers/SettingController.php:30-45`, `app/Services/SettingService.php:60-89`
  - Invalidate `Cache::forget('device_alert_stats')` and `Cache::forget('dashboard_telemetry_stats')` upon alert status changes (`updateStatus` and `bulkUpdateStatus`).
  - Cache public branding settings (`SettingController::publicSettings`) in Redis with 1-hour TTL (`settings.public`) and invalidate upon setting updates.

### R4. Frontend Real-Time & Attendance Sync (Tasks 6.12 – 6.13)
- **Task 6.12: Fix Echo Channel Type Mismatch in `DeviceAlertsCenter.vue`**
  - Files: `resources/js/views/DeviceAlertsCenter.vue:732-736`
  - Ensure subscription uses `echo.private('device-alerts')` matching backend `PrivateChannel('device-alerts')`.
- **Task 6.13: Correct Metric Binding in `attendanceStore` from Server Summary**
  - Files: `resources/js/stores/attendanceStore.js:80-82, 91-100`
  - Map `data.summary` directly into `this.stats` so aggregate KPIs reflect entire workforce correctly across paginated views.

---

## Verification & Acceptance Criteria
1. Dedicated test methods added in `tests/Feature/PerformanceOptimizationTest.php` for each Phase 6 task (Tasks 6.1 – 6.11).
2. Primary test suite passes: `php artisan test --filter=PerformanceOptimizationTest`
3. Full test suite passes: `php artisan test`
4. Frontend builds cleanly: `npm run build`
5. Task statuses in `tasks-performance.md` updated to completed `[x]`.


## 2026-10-07T06:09:16Z
[Message] timestamp=2026-10-07T06:09:16Z sender=134c890d-874a-4b63-865a-617bc0672006 priority=MESSAGE_PRIORITY_HIGH content=You are the Project Orchestrator for Phase 6 of tasks-performance.md in AI-Camera-Integration.
Reference task matrix: tasks-performance.md (Phase 6: Tasks 6.1 through 6.13)
Pre-existing Survey Reports & Scope:
- .agents/teamwork/orchestrator_6/SCOPE.md
- .agents/teamwork/p6_explorer_survey_1/survey_db_report.md
- .agents/teamwork/p6_explorer_survey_2/survey_compute_cache_report.md
- .agents/teamwork/p6_explorer_survey_3/survey_frontend_test_report.md
Mission: Execute all 13 performance optimization tasks in Phase 6 with full test coverage and zero regressions.
