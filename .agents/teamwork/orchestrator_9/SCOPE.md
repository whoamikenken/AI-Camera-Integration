# Scope: Phase 6 Performance Optimization (Milestones 3 & 5)

## Architecture
- Backend: Laravel 11 / PHP 8.2+
- Redis Cache: In-memory key-value caching, Redis counters/sets for non-blocking invalidation
- Telemetry: MQTT daemon (`MqttListenCommand`) subscribing to `mqtt/face/#`
- Jobs & Workers: `ProcessAttendancePunchJob` resolving identities and computing attendance records
- Settings & Alerts: Cache invalidation engine in `SettingService`, `SettingController`, `DeviceAlertController`
- Test Suite: `tests/Feature/PerformanceOptimizationTest.php` with PHPUnit/Pest

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | SARGable Date Queries | Convert date expression queries in attendance & visitors | M1 (DONE) | tasks-performance 6.1 |
| 2 | Telemetry Composite Indexes | Migration for access_logs, attendance_punches, notifications | M1 (DONE) | tasks-performance 6.2 |
| 3 | Sync Tasks Scope Filter | Scope sync_tasks queries in DashboardStatsController | M1 (DONE) | tasks-performance 6.3 |
| 4 | Pagination & Select Constraints | Leave balances and org units pagination | M1 (DONE) | tasks-performance 6.4 |
| 5 | Shift Rest Day Pre-fetching | Pre-fetch shift assignments in attendanceSummary | M2 (DONE) | tasks-performance 6.5 |
| 6 | Device Audit Hash Map Lookups | O(1) hash maps and SQL outbox deduplication in DeviceController | M2 (DONE) | tasks-performance 6.6 |
| 7 | Atomic Bulk Alert Updates | Atomic SQL bulk update in DeviceAlertController | M2 (DONE) | tasks-performance 6.7 |
| 8 | Non-blocking Redis Shift Cache | Eliminate Redis KEYS in ShiftController (versioned keys) | M3 | tasks-performance 6.8 |
| 9 | Device Registration Cache | Cache pre-enrolled device existence in MqttListenCommand | M3 | tasks-performance 6.9 |
| 10 | Identity Bridge Cache | Cache customize_id to employee in ProcessAttendancePunchJob | M3 | tasks-performance 6.10 |
| 11 | Alert & Settings Cache Invalidation | Explicit cache invalidation for alerts & public settings | M3 | tasks-performance 6.11 |
| 12 | Echo Channel Type Alignment | Private Echo channel in DeviceAlertsCenter.vue | M4 (DONE) | tasks-performance 6.12 |
| 13 | Attendance Store Metric Binding | Bind attendance stats from server summary payload | M4 (DONE) | tasks-performance 6.13 |
| 14 | Final Verification & Test Suite | Dedicated tests in PerformanceOptimizationTest, full test pass, npm build, markdown check | M5 | tasks-performance M5 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Database & Schema Optimization | Tasks 6.1 – 6.4 | none | DONE |
| M2 | Application Runtime & Compute Overhaul | Tasks 6.5 – 6.7 | M1 | DONE |
| M4 | Frontend Runtime & Real-Time Sync | Tasks 6.12 – 6.13 | none | DONE |
| M3 | Caching & Telemetry Pipeline Optimization | Tasks 6.8 – 6.11 | M1, M2 | DONE |
| M5 | Final Acceptance & Regression Verification | PerformanceOptimizationTest, full test suite, build, tasks update | M3, M4 | DONE |

## Interface Contracts
### Shift Cache Invalidation (Task 6.8)
- `ShiftController::assignBulk`: Use employee shift version counter `emp_shift_v:{$employeeId}` or employee-scoped key tracking set `emp_shift_keys:{$employeeId}`.
- Increment counter on shift assignment / updates to instantly invalidate cached views in $O(1)$ without Redis `KEYS`.

### Device Registration Cache (Task 6.9)
- `MqttListenCommand`: Cache registered device existence under `device_registered:{$deviceId}` for 10 minutes (600s).
- Check cache before database query/firstOrCreate.

### Identity Bridge Cache (Task 6.10)
- `ProcessAttendancePunchJob`: Cache biometric bridge `emp_custom_id:{$customizeId}` -> `['employee_id' => ..., 'personnel_id' => ...]` with 1-hour TTL (3600s).
- Invalidate in `EmployeeObserver` and `PersonnelObserver` on update/delete.

### Settings & Alerts Cache Invalidation (Task 6.11)
- `DeviceAlertController`: Invalidate `device_alert_stats` and `dashboard_telemetry_stats` on status mutations.
- `SettingController::publicSettings` / `SettingService`: Cache public settings under `settings.public` for 1 hour; invalidate on `SettingService::set()` or `SettingController::update()`.
