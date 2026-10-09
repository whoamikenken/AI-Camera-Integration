# Orchestrator Dispatch Directive — Phase 6 Performance Optimization (Milestones 3 & 5 Completion)

## Objective
Complete all remaining performance optimization tasks in Phase 6 of `tasks-performance.md` for the Intelligent AI Camera Hub (`AI-Camera-Integration`), focusing on Caching & Telemetry Pipeline Optimization (Tasks 6.8 – 6.11) and Final Regression Verification (Milestone 5) with full test coverage and zero regressions.

- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration`
- **Orchestrator Workspace**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_9`
- **Original Request**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- **Reference Task Matrix**: `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md` (Phase 6: Tasks 6.1 – 6.13)
- **Integrity Mode**: demo

---

## Current Accomplishments (Do NOT Re-implement)
- **Milestone 1 (Tasks 6.1 – 6.4)**: COMPLETED & VERIFIED. SARGable range queries in `AttendanceProcessingService` & `VisitorController`, migration `2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php` executed, `sync_tasks` scoped in `DashboardStatsController`, pagination & column constraints added in `LeaveController` & `OrganizationController`.
- **Milestone 2 (Tasks 6.5 – 6.7)**: COMPLETED & VERIFIED. Shift pre-fetching in `EmployeeController::attendanceSummary`, $O(1)$ hash maps & SQL outbox deduplication in `DeviceController::audit`, atomic bulk updates in `DeviceAlertController::bulkUpdateStatus`.
- **Milestone 4 (Tasks 6.12 – 6.13)**: COMPLETED & VERIFIED. Private Echo channel in `DeviceAlertsCenter.vue`, server summary KPI binding in `attendanceStore.js`.

---

## Remaining Scope to Execute

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
  - Invalidate `Cache::forget('device_alert_stats')` and `Cache::forget('dashboard_telemetry_stats')` upon alert status changes (`updateStatus` and `bulkUpdateStatus`) — already partly in place.
  - Cache public branding settings (`SettingController::publicSettings`) in Redis with 1-hour TTL (`settings.public`) and invalidate upon setting updates.

### R4. Final Regression Verification & Acceptance (Milestone 5)
- Dedicated test methods added in `tests/Feature/PerformanceOptimizationTest.php` for each Phase 6 task (Tasks 6.1 – 6.11).
- Run and verify primary test suite: `php artisan test --filter=PerformanceOptimizationTest`
- Run and verify full test suite: `php artisan test` (0 failures, 0 errors)
- Run and verify frontend build: `npm run build`
- Update task statuses in `tasks-performance.md` by marking all Phase 6 items from `- [ ]` to completed `- [x]`.

---

## Instructions
1. Spawn worker(s) to implement Tasks 6.8 through 6.11 and the remaining dedicated tests in `PerformanceOptimizationTest.php`.
2. Run independent verification (reviewer, challenger, auditor).
3. Ensure full test pass and clean Vite build.
4. Mark all completed items as `[x]` in `tasks-performance.md`.
5. When verified, report completion back to the Sentinel.


## 2026-10-08T05:48:58Z
You are the Project Orchestrator for Phase 6 of tasks-performance.md in AI-Camera-Integration.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_9

Your dispatch directive has been written to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_9/DISPATCH.md

The original user request is recorded in:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

Reference task matrix:
/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md (Phase 6: Tasks 6.1 through 6.13)

Current State:
- Milestone 1 (Tasks 6.1 – 6.4: DB & Schema) is COMPLETED and passed Gate 1.
- Milestone 2 (Tasks 6.5 – 6.7: Application Runtime & Compute) is COMPLETED by p6_m2_worker and reviewed.
- Milestone 4 (Tasks 6.12 – 6.13: Frontend Real-Time) is COMPLETED and verified.
- Scope remaining to execute:
  - Milestone 3 (Tasks 6.8 – 6.11: Caching & Telemetry Pipeline Optimization):
    - Task 6.8: Eliminate blocking Redis KEYS in ShiftController (use versioned cache keys / non-blocking eviction)
    - Task 6.9: Cache device registration in MqttListenCommand
    - Task 6.10: Cache customize_id to employee identity in ProcessAttendancePunchJob
    - Task 6.11: Cache invalidation for device alerts & public settings in SettingController / SettingService
  - Milestone 5 (Verification & Final Acceptance):
    - Dedicated test methods in tests/Feature/PerformanceOptimizationTest.php for all Phase 6 tasks (Tasks 6.1 – 6.11)
    - Verify `php artisan test --filter=PerformanceOptimizationTest` passes
    - Verify `php artisan test` passes with 0 failures
    - Verify `npm run build` succeeds
    - Mark all Phase 6 tasks as completed [x] in tasks-performance.md

Proceed with driving the team to execute Milestone 3 and Milestone 5.
When finished and fully verified, report your completion back to the Sentinel.


## 2026-10-08T12:20:27Z
Sentinel Liveness Nudge: Upstream rate limit pause has cleared. Please check status of your Gate 3 verification team (p6_m3_reviewer_1, p6_m3_reviewer_2, p6_m3_challenger_1, p6_m3_challenger_2, p6_m3_auditor), update your progress.md, and proceed with Milestone 3 Gate Verification and Milestone 5 final acceptance.
