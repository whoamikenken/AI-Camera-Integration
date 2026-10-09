# Specification & Test Coverage Analysis: Phase 6 Milestone 3 & Milestone 5

## 1. Executive Summary

This document provides the authoritative technical specification and test gap analysis for **Milestone 3** (Tasks 6.8, 6.9, 6.10, 6.11) and **Milestone 5** (Final Acceptance & Test Suite Coverage in `tests/Feature/PerformanceOptimizationTest.php`) of **Phase 6 Performance Optimization** for the Intelligent AI Camera Hub.

---

## 2. Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Alert Caching | `device_alert_stats` eviction in `updateStatus` | Evicts cached KPI metrics when a single device alert status transitions | `DeviceAlert $deviceAlert`, HTTP PUT payload `['status' => '...']` | JSON response with updated alert | 422 Unprocessable if status invalid; 404 if alert not found | `app/Http/Controllers/DeviceAlertController.php:83-105` |
| 2 | Alert Caching | `dashboard_telemetry_stats` eviction in `updateStatus` | Evicts dashboard telemetry aggregates when a device alert is updated | HTTP PUT `status` in `['NEW','ACKNOWLEDGED','RESOLVED','DISMISSED']` | Cache key `dashboard_telemetry_stats` forgotten | 422 if status not permitted | `app/Http/Controllers/DeviceAlertController.php:101-103` |
| 3 | Alert Caching | Bulk Alert Stats Invalidation | Evicts both `device_alert_stats` and `dashboard_telemetry_stats` after atomic SQL bulk update | HTTP POST `['ids' => int[], 'status' => string]` | JSON `['success' => true, 'message' => '...']` | 422 if ids empty or non-existent | `app/Http/Controllers/DeviceAlertController.php:107-146` |
| 4 | Settings Caching | Public Settings 1-Hour Cache | Caches public branding & system configuration under `settings.public` with 3600s TTL | HTTP GET `/api/settings/public` | JSON `['success' => true, 'data' => ['key' => 'casted_value', ...]]` | Falls back to empty dict if no public settings | `app/Http/Controllers/SettingController.php:30-45`, `SCOPE.md:52-54` |
| 5 | Settings Caching | Public Cache Invalidation on `SettingService::set()` | Invalidation of `settings.public` when a global or public setting is saved/updated | `$key`, `$value`, `$organizationId = null`, `$type = null`, `$description = null` | Persisted `Setting` instance; evicts `settings.public` | Throws DB exception on constraint failure | `app/Services/SettingService.php:60-89`, `tasks-performance.md:313` |
| 6 | Settings Caching | Public Cache Invalidation on `SettingController::update()` | Invalidation of `settings.public` when settings are updated in bulk via HTTP | HTTP PUT/POST `/api/settings` with array of setting objects or key-value map | JSON `['success' => true, 'data' => [...]]` | 403 Forbidden without `settings.manage` | `app/Http/Controllers/SettingController.php:50-89` |
| 7 | Settings Caching | Public Cache Invalidation on `SettingService::reset()` | Invalidation of `settings.public` when settings are reset to global defaults | `$group = null`, `$organizationId = null` | Clears organization overrides; evicts `settings.public` | Logs error or ignores missing keys | `app/Services/SettingService.php:121-140` |
| 8 | Shift Caching | Non-Blocking Shift Cache Eviction (Task 6.8) | Replaces blocking $O(N)$ Redis `KEYS` pattern with versioned counter `emp_shift_v:{$empId}` or tracking set | `$employeeIds = []`, `$shift`, `$effectiveFrom` | `emp_shift_v:{$empId}` incremented in $O(1)$; no Redis `KEYS` call | Cache connection failure caught safely | `app/Http/Controllers/ShiftController.php:298-311`, `tasks-performance.md:288-294` |
| 9 | Telemetry Caching | Device Registration Cache (Task 6.9) | Caches active camera registration under `device_registered:{$deviceId}` with 600s TTL | Ingested MQTT topic device ID string (`$deviceId`) | Boolean true in cache; bypasses `Device::where(...)->first()` | If inactive or unregistered, falls back to DB and drops | `app/Console/Commands/MqttListenCommand.php:243,344,431`, `SCOPE.md:43-46` |
| 10 | Telemetry Caching | Biometric Identity Bridge Cache (Task 6.10) | Caches `emp_custom_id:{$customizeId}` -> `['employee_id' => ..., 'personnel_id' => ...]` for 3600s | Biometric numeric ID `customize_id` from `AccessLog` | Resolved cached employee ID / personnel ID | If no match, logs warning and aborts without crash | `app/Jobs/ProcessAttendancePunchJob.php:33-46`, `SCOPE.md:47-50` |
| 11 | Identity Invalidation | Identity Bridge Eviction on Personnel/Employee Mutation | Evicts `emp_custom_id:{$customizeId}` when employee or personnel record changes | Model events: `updated`, `deleting` in `PersonnelObserver` and `EmployeeObserver` | Cache key `emp_custom_id:{$customizeId}` forgotten | Silently ignored if key absent | `app/Observers/PersonnelObserver.php`, `app/Observers/EmployeeObserver.php` |

---

## 3. Edge Cases & Boundary Conditions

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | Public Settings Cache | Database has 0 public settings | Cache stores empty array `[]` for 3600s; subsequent calls do not query DB. |
| 2 | Public Settings Cache | Setting value contains JSON/array | `getCastedValueAttribute` decodes JSON string into PHP array; cached structure preserves array formatting. |
| 3 | Public Settings Invalidation | Tenant-specific setting updated (`organization_id !== null`) | Organization cache `settings.org.{$orgId}.{$key}` is evicted; if setting is not public, `settings.public` is optionally retained or safely flushed without breaking tenant isolation. |
| 4 | Alert Stats Invalidation | Status updated to identical current status (no change) | Invalidation fires unconditionally to ensure consistency; does not error. |
| 5 | Bulk Alert Update | Empty `ids` array provided `['ids' => []]` | Laravel validation rejects with `422 Unprocessable Entity`; no cache eviction runs. |
| 6 | Bulk Alert Update | Partial non-existent IDs in array `['ids' => [99999]]` | Validation fails `exists:device_alerts,id`; returns 422; no cache pollution. |
| 7 | Device Registration Cache | Camera deleted or deactivated via admin API | Cache key `device_registered:{$deviceId}` must be evicted in `DeviceObserver` so next packet is dropped. |
| 8 | Identity Bridge Cache | `customize_id` is null (stranger / non-biometric punch) | Cache bypasses lookup immediately; returns early without querying DB or storing null keys. |
| 9 | Shift Version Counter | Counter not yet initialized for employee | `Cache::get("emp_shift_v:{$empId}", 1)` defaults to version 1; increments to version 2 atomically on first assignment. |

---

## 4. Deep Specification: Task 6.11 (Device Alerts & Public Settings)

### 4.1 Device Alert Statistics Invalidation Engine
- **Files**: `app/Http/Controllers/DeviceAlertController.php:96, 120`
- **Current Code Review**:
  In `updateStatus` (lines 101–103):
  ```php
  Cache::forget('device_alert_stats');
  Cache::forget('dashboard_telemetry_stats');
  ```
  In `bulkUpdateStatus` (lines 139–141):
  ```php
  Cache::forget('device_alert_stats');
  Cache::forget('dashboard_telemetry_stats');
  ```
- **Analysis**:
  - The invalidation logic was implemented by Worker M2 during the atomic update refactoring.
  - Both keys (`device_alert_stats` with 5s TTL from `DeviceAlertController::stats` and `dashboard_telemetry_stats` with 5s TTL from `DashboardStatsController::index`) are explicitly evicted.
  - **Gap in Test Suite**: Although implemented, `PerformanceOptimizationTest.php` lacks a dedicated test method verifying this eviction contract for Task 6.11!

### 4.2 Public Branding Settings Caching (`SettingController::publicSettings`)
- **Files**: `app/Http/Controllers/SettingController.php:30-45`
- **Authoritative Specification**:
  - Endpoint: `GET /api/settings/public` (unauthenticated, rate-limited at 60 req/min).
  - Scope: `Setting::whereNull('organization_id')->where('is_public', true)->get()`.
  - Cache Key: `settings.public`
  - Cache TTL: `3600` seconds (1 hour).
  - Specification Requirement:
    ```php
    public function publicSettings(): JsonResponse
    {
        $data = Cache::remember('settings.public', 3600, function () {
            $publicSettings = Setting::whereNull('organization_id')
                ->where('is_public', true)
                ->get();

            $data = [];
            foreach ($publicSettings as $setting) {
                $data[$setting->key] = $setting->casted_value;
            }

            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
    ```

### 4.3 Public Settings Cache Invalidation Engine
- **Files**: `app/Services/SettingService.php:60-89, 121-140` and `app/Http/Controllers/SettingController.php:50-89`
- **Authoritative Specification**:
  - In `SettingService::set()`:
    ```php
    // Invalidate cache
    if ($organizationId) {
        Cache::forget("settings.org.{$organizationId}.{$key}");
    } else {
        Cache::forget("settings.global.{$key}");
    }
    Cache::forget('settings.public');
    ```
  - In `SettingService::reset()`:
    ```php
    if ($organizationId === null) {
        Cache::forget('settings.public');
    }
    ```
  - In `SettingController::update()`:
    Ensures `Cache::forget('settings.public')` is invoked whenever settings are updated.

---

## 5. Audit of Current Test Suite (`PerformanceOptimizationTest.php`)

### 5.1 Existing Tests Inventory

| Test Method Name | Lines | Task Covered | Status in `PerformanceOptimizationTest.php` |
|---|---|---|---|
| `test_performance_and_foreign_key_indexes_exist` | 78–101 | Task 1.1 | Present (Passing) |
| `test_personnel_deletion_preserves_telemetry_access_logs` | 106–149 | Task 1.2 | Present (Passing) |
| `test_employee_deletion_with_preserve_telemetry_flag_preserves_access_logs` | 154–197 | Task 1.2 | Present (Passing) |
| `test_personnel_customize_id_generates_atomically` | 202–211 | Task 1.3 | Present (Passing) |
| `test_monthly_attendance_report_aggregates_via_sql` | 216–267 | Task 2.1 | Present (Passing) |
| `test_payroll_export_json_and_csv_streaming` | 272–318 | Task 2.1 | Present (Passing) |
| `test_personnel_and_employee_indexes_exclude_photo_base64` | 323–356 | Task 2.2 | Present (Passing) |
| `test_import_camera_personnel_returns_202_accepted_and_dispatches_job` | 361–383 | Task 2.3 | Present (Passing) |
| `test_camera_heartbeat_writes_are_throttled_with_redis` | 388–424 | Task 3.1 | Present (Passing) |
| `test_access_log_received_event_implements_should_broadcast_with_redis_and_broadcasts_queue` | 429–456 | Task 3.2 | Present (Passing) |
| `test_sync_personnel_dispatches_parallel_sync_device_personnel_jobs` | 461–496 | Task 3.3 | Present (Passing) |
| `test_dashboard_stats_endpoint_uses_caching_and_consolidates_alerts` | 501–548 | Task 4.1 | Present (Passing) |
| `test_attendance_processing_service_caches_holidays_and_shifts` | 553–592 | Task 4.2 | Present (Passing) |
| `test_deep_composite_indexes_exist` | 597–617 | Task 1.4 | Present (Passing) |
| `test_daily_attendance_finalizer_chunking` | 622–656 | Task 2.4 | Present (Passing) |
| `test_bulk_shift_assignment_batching` | 661–697 | Task 2.5 | Present (Passing) |
| `test_attendance_daily_pagination_and_streaming_exports` | 701–749 | Task 2.6 | Present (Passing) |
| `test_column_specific_eager_loading_excludes_photo_base64` | 753–782 | Task 2.7 | Present (Passing) |
| `test_all_realtime_events_implement_should_broadcast` | 786–812 | Task 3.2 | Present (Passing) |
| `test_device_alert_stats_caching_and_consolidation` | 816–840 | Task 4.2 | Present (Passing) |
| `test_holiday_mutations_invalidate_cache` | 844–863 | Task 4.3 | Present (Passing) |
| `test_device_fleet_counts_caching` | 867–880 | Task 4.4 | Present (Passing) |
| `test_phase6_attendance_and_visitor_queries_use_sargable_ranges` | 886–988 | Task 6.1 | Present (Passing) |
| `test_phase6_composite_and_foreign_key_indexes_exist` | 994–1017 | Task 6.2 | Present (Passing) |
| `test_phase6_sync_tasks_dashboard_stats_query_filters_active_statuses` | 1023–1101 | Task 6.3 | Present (Passing) |
| `test_phase6_wide_read_endpoints_are_paginated_and_column_constrained` | 1107–1243 | Task 6.4 | Present (Passing) |

### 5.2 Phase 6 Test Coverage Gap Analysis

| Task Code | Description | Tested in `PerformanceOptimizationTest.php`? | Tested Elsewhere? | Action Required for Milestone 5 |
|---|---|---|---|---|
| **Task 6.1** | SARGable range queries for visits & punches | **YES** (`test_phase6_attendance_and_visitor_queries_use_sargable_ranges`) | `Phase6Milestone1Challenger1Test.php` | None |
| **Task 6.2** | Composite indexes on access_logs, punches, notifications | **YES** (`test_phase6_composite_and_foreign_key_indexes_exist`) | `Phase6Milestone1Challenger1Test.php` | None |
| **Task 6.3** | Sync tasks scope filter in dashboard stats | **YES** (`test_phase6_sync_tasks_dashboard_stats_query_filters_active_statuses`) | - | None |
| **Task 6.4** | Pagination & column constraints for leave & org units | **YES** (`test_phase6_wide_read_endpoints_are_paginated_and_column_constrained`) | - | None |
| **Task 6.5** | Pre-fetching shift assignments in attendanceSummary | **NO** | `Phase6Milestone2EmpiricalChallengeTest.php` | **Add dedicated test method to PerformanceOptimizationTest** |
| **Task 6.6** | O(1) hash map & SQL deduplication in Device audit | **NO** | `Phase6Milestone2EmpiricalChallengeTest.php` | **Add dedicated test method to PerformanceOptimizationTest** |
| **Task 6.7** | Atomic bulk update in DeviceAlertController | **NO** | `Phase6Milestone2EmpiricalChallengeTest.php` | **Add dedicated test method to PerformanceOptimizationTest** |
| **Task 6.8** | Non-blocking Redis shift cache invalidation (no `KEYS`) | **NO** | None | **Implement feature & add dedicated test method** |
| **Task 6.9** | Cache pre-enrolled device existence in MQTT listener | **NO** | None | **Implement feature & add dedicated test method** |
| **Task 6.10** | Cache biometric `customize_id` to employee bridge | **NO** | None | **Implement feature & add dedicated test method** |
| **Task 6.11** | Invalidation engine for alerts & public settings | **NO** | Partial in M2 test | **Implement feature & add dedicated test method** |
| **Task 6.12** | Private Echo channel for device-alerts in frontend | Frontend view | Client code & `DeviceAlertReceived` test | Verified via `npm run build` |
| **Task 6.13** | Bind attendanceStore stats from summary payload | Frontend store | Client code & Line 729 backend contract | Verified via `npm run build` |

---

## 6. Milestone 5 Requirements: Exact Test Method Specifications

To achieve 100% Phase 6 test coverage in `tests/Feature/PerformanceOptimizationTest.php`, the following dedicated test methods must be added:

### 6.1 Task 6.5: `test_phase6_employee_attendance_summary_preloads_shift_assignments`
- **Method Signature**: `public function test_phase6_employee_attendance_summary_preloads_shift_assignments(): void`
- **Exact Test Plan & Assertions**:
  1. Create employee, department, and active shift.
  2. Create `EmployeeShiftAssignment` covering a 31-day window (e.g. `2026-10-01` to `2026-10-31`).
  3. Enable query log (`DB::enableQueryLog()`).
  4. Call `GET /api/employees/{$employee->id}/attendance-summary?from=2026-10-01&to=2026-10-31`.
  5. Assert response status is 200 OK.
  6. Extract all SQL queries containing `shift_assignments`.
  7. **Assertion**: `assertCount(1, $shiftAssignmentQueries)` — confirms exactly 1 database query was issued instead of 31 individual day queries ($O(1)$ query count).
  8. **Assertion**: Assert `total_working_days` calculates correctly (22 days for October 2026 standard work week).

### 6.2 Task 6.6: `test_phase6_device_audit_uses_hash_map_and_latest_sync_task_query`
- **Method Signature**: `public function test_phase6_device_audit_uses_hash_map_and_latest_sync_task_query(): void`
- **Exact Test Plan & Assertions**:
  1. Create a `Device` (`CAM-AUDIT-P6`).
  2. Create 3 `Personnel` records.
  3. Create multiple historical `SyncTask` records for each personnel (e.g. 5 tasks per person, total 15 tasks).
  4. Enable query log (`DB::enableQueryLog()`).
  5. Call `POST /api/devices/{$device->id}/audit` with mock edge personnel list.
  6. Assert response status is 200 OK.
  7. Extract queries containing `sync_tasks`.
  8. **Assertion**: Query on `sync_tasks` uses a grouping or `MAX(id)` subquery (`selectRaw('MAX(id)')` / `whereIn('id', ...)`), ensuring only latest tasks are hydrated.
  9. **Assertion**: Personnel matching checks successfully match edge records in $O(1)$ hash map lookup.

### 6.3 Task 6.7: `test_phase6_device_alert_bulk_update_status_is_atomic`
- **Method Signature**: `public function test_phase6_device_alert_bulk_update_status_is_atomic(): void`
- **Exact Test Plan & Assertions**:
  1. Create a `Device` and 5 `DeviceAlert` records with status `'NEW'`.
  2. Enable query log (`DB::enableQueryLog()`).
  3. Call `POST /api/device-alerts/bulk-status` with `['ids' => $alertIds, 'status' => 'RESOLVED']`.
  4. Assert response status is 200 OK.
  5. Extract queries containing `update` and `device_alerts`.
  6. **Assertion**: Exactly 1 SQL `UPDATE` statement is executed across the table (`whereIn('id', ...)`), verifying atomic multi-record execution rather than $N$ separate row updates.
  7. **Assertion**: All 5 records in database have `status` = `'RESOLVED'` and `resolved_at` populated.

### 6.4 Task 6.8: `test_phase6_bulk_shift_assignment_uses_non_blocking_cache_invalidation`
- **Method Signature**: `public function test_phase6_bulk_shift_assignment_uses_non_blocking_cache_invalidation(): void`
- **Exact Test Plan & Assertions**:
  1. Create two `Shift` records (Shift A and Shift B), and 3 active `Employee` records assigned to Shift A.
  2. Call `AttendanceProcessingService::resolveEffectiveShift()` for employee 1 on date `2026-11-01` to warm the cache.
  3. Verify cache contains shift resolution for employee 1.
  4. Call `POST /api/shifts/{$shiftB->id}/assign` with `employee_ids` = `[$emp1->id, $emp2->id, $emp3->id]`, `effective_from` = `'2026-11-01'`.
  5. Assert response status is 201 Created.
  6. **Assertion**: If versioned keys are used, assert `emp_shift_v:{$emp1->id}` has incremented (e.g. from 1 to 2).
  7. **Assertion**: Subsequent call to `AttendanceProcessingService::resolveEffectiveShift($emp1, '2026-11-01')` returns Shift B (cache was invalidated without blocking Redis `KEYS`).

### 6.5 Task 6.9: `test_phase6_mqtt_listener_caches_registered_device_existence`
- **Method Signature**: `public function test_phase6_mqtt_listener_caches_registered_device_existence(): void`
- **Exact Test Plan & Assertions**:
  1. Create an active `Device` with `device_id` = `'CAM-MQTT-CACHE-01'`.
  2. Invalidate cache: `Cache::forget('device_registered:CAM-MQTT-CACHE-01')`.
  3. Send an MQTT heartbeat or verification webhook packet for `'CAM-MQTT-CACHE-01'`.
  4. **Assertion**: `Cache::has('device_registered:CAM-MQTT-CACHE-01')` is `true`.
  5. Enable query log (`DB::enableQueryLog()`).
  6. Send a second packet for `'CAM-MQTT-CACHE-01'`.
  7. **Assertion**: Zero queries to `SELECT * FROM devices WHERE device_id = 'CAM-MQTT-CACHE-01'` executed in the second request (resolved entirely from registration cache).
  8. Deactivate device (`$device->update(['is_active' => false])`) and clear registration cache -> next packet is dropped and not cached as active.

### 6.6 Task 6.10: `test_phase6_punch_job_caches_customize_id_to_employee_bridge`
- **Method Signature**: `public function test_phase6_punch_job_caches_customize_id_to_employee_bridge(): void`
- **Exact Test Plan & Assertions**:
  1. Create `Personnel` with `customize_id` = 8801.
  2. Create linked `Employee` with `personnel_id` = `$personnel->id`, `employee_code` = `'EMP-8801'`.
  3. Create an `AccessLog` with `customize_id` = 8801, `verify_status` = 1.
  4. Dispatch `ProcessAttendancePunchJob` with the access log.
  5. **Assertion**: Cache key `emp_custom_id:8801` is populated.
  6. Enable query log (`DB::enableQueryLog()`).
  7. Dispatch a second `ProcessAttendancePunchJob` for another log with `customize_id` = 8801.
  8. **Assertion**: Query on `personnel` where `customize_id = 8801` is skipped because identity was resolved directly from `emp_custom_id:8801`.
  9. Update employee (`$employee->update(['first_name' => 'Renamed'])`) or delete -> assert `emp_custom_id:8801` is evicted from cache.

### 6.7 Task 6.11: `test_phase6_alert_status_mutations_and_public_settings_caching_and_invalidation`
- **Method Signature**: `public function test_phase6_alert_status_mutations_and_public_settings_caching_and_invalidation(): void`
- **Exact Test Plan & Assertions**:
  1. **Device Alert Invalidation Verification**:
     - Pre-populate `Cache::put('device_alert_stats', ['cached' => true], 60)`.
     - Pre-populate `Cache::put('dashboard_telemetry_stats', ['cached' => true], 60)`.
     - Create a `DeviceAlert`.
     - Call `PUT /api/device-alerts/{$alert->id}/status` with `['status' => 'ACKNOWLEDGED']`.
     - Assert 200 OK.
     - **Assertion**: `assertFalse(Cache::has('device_alert_stats'))`.
     - **Assertion**: `assertFalse(Cache::has('dashboard_telemetry_stats'))`.
     - Re-populate both cache keys.
     - Call `POST /api/device-alerts/bulk-status` with `['ids' => [$alert->id], 'status' => 'RESOLVED']`.
     - Assert 200 OK.
     - **Assertion**: `assertFalse(Cache::has('device_alert_stats'))`.
     - **Assertion**: `assertFalse(Cache::has('dashboard_telemetry_stats'))`.
  2. **Public Branding Settings Caching & Invalidation Verification**:
     - Create a public setting: `Setting::create(['key' => 'system.company_name', 'value' => 'Test Hub', 'group' => 'system', 'type' => 'string', 'is_public' => true])`.
     - Call `GET /api/settings/public`.
     - Assert 200 OK and JSON `data.system.company_name` equals `'Test Hub'`.
     - **Assertion**: `assertTrue(Cache::has('settings.public'))`.
     - Call `SettingService::set('system.company_name', 'Brand New Hub')`.
     - **Assertion**: `assertFalse(Cache::has('settings.public'))` (cache was evicted upon setting modification).
     - Call `GET /api/settings/public` again.
     - Assert 200 OK and JSON `data.system.company_name` equals `'Brand New Hub'`.
     - **Assertion**: `assertTrue(Cache::has('settings.public'))` (re-cached with updated value).

---

## 7. Implementation Roadmap & Checklist for Workers

- [ ] **Task 6.8 Worker**: Update `ShiftController::performShiftAssignment` and `AttendanceProcessingService::resolveEffectiveShift` to use versioned keys (`emp_shift_v:{$empId}`) or employee tracking set; remove `Redis::keys()`.
- [ ] **Task 6.9 Worker**: Update `MqttListenCommand` to cache active `device_id` under `device_registered:{$deviceId}` for 600s; update `DeviceObserver` to evict on status/deletion changes.
- [ ] **Task 6.10 Worker**: Update `ProcessAttendancePunchJob` to cache identity bridge under `emp_custom_id:{$customizeId}` for 3600s; update `EmployeeObserver` and `PersonnelObserver` to evict on changes.
- [ ] **Task 6.11 Worker**: Update `SettingController::publicSettings` to cache under `settings.public` with 3600s TTL; update `SettingService::set()` and `SettingController::update()` to evict `settings.public`.
- [ ] **Milestone 5 Test Writer**: Add the 7 dedicated test methods (`test_phase6_employee_attendance_summary_preloads_shift_assignments`, `test_phase6_device_audit_uses_hash_map_and_latest_sync_task_query`, `test_phase6_device_alert_bulk_update_status_is_atomic`, `test_phase6_bulk_shift_assignment_uses_non_blocking_cache_invalidation`, `test_phase6_mqtt_listener_caches_registered_device_existence`, `test_phase6_punch_job_caches_customize_id_to_employee_bridge`, `test_phase6_alert_status_mutations_and_public_settings_caching_and_invalidation`) into `tests/Feature/PerformanceOptimizationTest.php`.
- [ ] **Verification**: Run `php artisan test --filter=PerformanceOptimizationTest` (expect 33 passing tests) and full `php artisan test`.
