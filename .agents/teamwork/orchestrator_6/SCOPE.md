# Scope: Phase 6 Performance Optimization (Tasks 6.1 – 6.13)

## Architecture
Phase 6 optimizes high-frequency paths in the Intelligent AI Camera Hub across:
1. PostgreSQL Database & Query Engine (SARGable queries, indexes, scoped scans, paginated relationships)
2. Application Runtime & Compute (pre-fetching shift assignments, O(1) hash maps, SQL bulk updates)
3. Caching & Telemetry Pipeline (Redis versioned keys, device caching, customize_id bridge caching, alert cache invalidation)
4. Frontend Real-Time Telemetry & Store Sync (authenticated private Echo channel, KPI summary binding)
5. Test Coverage & Zero Regressions (dedicated test methods for Tasks 6.1–6.11, full test suite pass, clean Vite build)

## Feature Inventory
| # | Task | Description | Milestone | Source |
|---|------|-------------|-----------|--------|
| 1 | 6.1  | SARGable time-window queries in AttendanceProcessingService & VisitorController | M1 | Survey 1 Report |
| 2 | 6.2  | Migration for composite & foreign key indexes (access_logs, attendance_punches, notifications) | M1 | Survey 1 Report |
| 3 | 6.3  | Scoped sync_tasks queries in DashboardStatsController | M1 | Survey 1 Report |
| 4 | 6.4  | Paginated & column-constrained endpoints in LeaveController & OrganizationController | M1 | Survey 1 Report |
| 5 | 6.5  | Pre-fetch shift assignments in EmployeeController::attendanceSummary | M2 | Survey 2 Report |
| 6 | 6.6  | O(1) hash lookups and SQL-deduplicated sync tasks in DeviceController::audit | M2 | Survey 2 Report |
| 7 | 6.7  | Atomic bulk SQL update in DeviceAlertController::bulkUpdateStatus | M2 | Survey 2 Report |
| 8 | 6.8  | Non-blocking Redis cache keys in ShiftController (versioned keys / non-blocking eviction) | M3 | Survey 2 Report |
| 9 | 6.9  | Cache device registration in MqttListenCommand | M3 | Survey 2 Report |
| 10| 6.10 | Cache customize_id to employee identity in ProcessAttendancePunchJob | M3 | Survey 2 Report |
| 11| 6.11 | Invalidate alert & dashboard stats cache; cache public settings in SettingController | M3 | Survey 2 Report |
| 12| 6.12 | Fix private Echo channel in DeviceAlertsCenter.vue | M4 | Survey 3 Report |
| 13| 6.13 | Bind attendanceStore.stats from backend summary payload | M4 | Survey 3 Report |
| 14| 6.V  | Verification & regression tests in PerformanceOptimizationTest.php, full test pass, npm run build | M5 | Survey 3 Report |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| 0 | Survey | Map codebase, current implementations, migration state, existing tests | none | DONE |
| 1 | M1: Database & Schema Optimization | Tasks 6.1, 6.2, 6.3, 6.4 | Survey | IN_PROGRESS |
| 2 | M2: Application Runtime & Compute Overhaul | Tasks 6.5, 6.6, 6.7 | M1 | PLANNED |
| 3 | M3: Caching & Telemetry Pipeline Optimization | Tasks 6.8, 6.9, 6.10, 6.11 | M2 | PLANNED |
| 4 | M4: Frontend Real-Time & Attendance Sync | Tasks 6.12, 6.13 | Survey | PLANNED |
| 5 | M5: Regression Verification & Acceptance | Comprehensive tests in PerformanceOptimizationTest, php artisan test, npm run build | M1, M2, M3, M4 | PLANNED |

## Code Layout & Ownership
- M1 Owner: `app/Services/AttendanceProcessingService.php`, `app/Http/Controllers/VisitorController.php`, `database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php`, `app/Http/Controllers/DashboardStatsController.php`, `app/Http/Controllers/LeaveController.php`, `app/Http/Controllers/OrganizationController.php`.
- M2 Owner: `app/Http/Controllers/EmployeeController.php`, `app/Models/Employee.php`, `app/Http/Controllers/DeviceController.php`, `app/Http/Controllers/DeviceAlertController.php`.
- M3 Owner: `app/Http/Controllers/ShiftController.php`, `app/Console/Commands/MqttListenCommand.php`, `app/Jobs/ProcessAttendancePunchJob.php`, `app/Observers/EmployeeObserver.php`, `app/Observers/PersonnelObserver.php`, `app/Http/Controllers/SettingController.php`, `app/Services/SettingService.php`, `app/Http/Controllers/DeviceAlertController.php`.
- M4 Owner: `resources/js/views/DeviceAlertsCenter.vue`, `resources/js/stores/attendanceStore.js`.
- M5 Owner: `tests/Feature/PerformanceOptimizationTest.php`, `tasks-performance.md`.
