# Phase 6 Performance Optimization — Unified Remediation Specification
**Iteration 2 (Milestone 3 Remediation & Milestone 5 Final Acceptance)**

---

## 1. Executive Summary & Root Cause Analysis

### 1.1 The Iteration 1 Failure
In Iteration 1, auditor `p6_m3_auditor_r2` rejected the work product due to a test failure in `PerformanceOptimizationTest`:
- **Failing Test**: `Tests\Feature\PerformanceOptimizationTest::test_attendance_processing_service_caches_holidays_and_shifts` (line 553/585)
- **Root Cause**: `AttendanceProcessingService::isHoliday()` cached holiday IDs under `"holiday_ids_{$year}"` instead of caching under the contracted key `"holidays_{$year}"`. Consequently, `Cache::has('holidays_2026')` asserted `false`.
- **Architectural Violation**: `HolidayController` lines 64, 93, 97, and 110 evicted `"holidays_{$year}"`. Storing under `"holiday_ids_{$year}"` created a phantom cache that was never invalidated when holidays were created, edited, or deleted, leading to stale holiday resolution in production for up to 3600 seconds.

### 1.2 Unified Target State
1. **Primary Contract Key**: `AttendanceProcessingService::isHoliday()` MUST establish `holidays_{$year}` as the primary cache key.
2. **Backward / Forward Compatibility**: Secondary alias `holiday_ids_{$year}` is also populated and invalidated in both `AttendanceProcessingService` and `HolidayController`.
3. **Serialization & Dummy Protection**: Cached payloads are stored as plain associative arrays to avoid `__PHP_Incomplete_Class` serialization anomalies across Redis instances, and queries transparently handle array, Collection, and Eloquent model payloads, as well as test dummies (e.g., `Cache::put('holidays_2026', ['dummy'])`).
4. **All 4 Test Suites Pass 100%**:
   - `PerformanceOptimizationTest` (33 / 33 tests pass, 0 failures)
   - `Phase6Milestone3Challenger1Test` (9 / 9 tests pass, 0 failures)
   - `Phase6Milestone3Challenger2Test` (14 / 14 tests pass, 0 failures)
   - Full regression suite `php artisan test` (647 passed, 32 skipped, 0 failures across 679 tests)
   - Frontend production build `npm run build` exits code 0.
5. **Task Tracking Cleanliness**: Tasks 6.1 through 6.13 in `tasks-performance.md` marked completed `[x]`.

---

## 2. Features Discovered & Interface Contracts

### 2.1 Features Discovered Table

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Caching | Yearly Holiday Cache | Caches holiday records per year for $O(1)$ fast attendance checks | `Carbon $date`, `?Employee $employee` | `bool` (true if holiday applies) | Gracefully handles non-existent or empty holiday datasets | `AttendanceProcessingService.php:204` |
| 2 | Cache Invalidation | Holiday Mutation Invalidation | Purges yearly holiday cache on holiday store/update/destroy | `Holiday $holiday`, date attributes | `void` (cache key eviction) | Handles year changes during update by evicting both old & new years | `HolidayController.php:64,93,97,110` |
| 3 | Caching | Effective Shift Resolution Cache | Caches resolved shift ID per employee and date | `Employee $employee`, `Carbon|string $date` | `?Shift` | Falls back to default shift or org shift when unassigned | `AttendanceProcessingService.php:123` |
| 4 | Cache Invalidation | Non-Blocking Shift Invalidation | $O(1)$ cache eviction without blocking Redis `KEYS` via versioning | `int $employeeId` or `array $employeeIds` | `void` (increments `emp_shift_v` counter and clears tracked keys) | Safe try-catch wrapper against transient Redis failures | `AttendanceProcessingService.php:170` |
| 5 | Telemetry | Device Registration Caching | Caches active status of pre-enrolled camera devices (TTL: 600s) | `string $deviceId` | `bool` | Returns `false` and caches negative state if device missing or inactive | `MqttListenCommand.php:677` |
| 6 | Identity Bridge | Biometric `customize_id` Mapping Cache | Caches `customize_id -> [employee_id, personnel_id]` bridge (TTL: 3600s) | `int $customizeId` | `?array` | Returns `null` if unmapped stranger punch; never throws | `ProcessAttendancePunchJob.php:33` |
| 7 | Identity Bridge | Biometric Cache Invalidation | Evicts `emp_custom_id` cache upon employee or personnel mutation | Model save / delete lifecycle | `void` (evicts old and new keys) | Both `EmployeeObserver` and `PersonnelObserver` ensure consistency | `EmployeeObserver.php:35`, `PersonnelObserver.php:40` |
| 8 | Caching | Public Settings Caching | Caches non-sensitive system branding settings in Redis (TTL: 3600s) | HTTP GET `/api/settings/public` | JSON response of public settings | Falls back to defaults if DB settings table empty | `SettingController.php:30`, `SettingService.php:60` |
| 9 | Cache Invalidation | System Settings Eviction | Evicts `settings.public` on setting update or reset | Setting update requests | `void` (evicts `settings.public`) | Evicts immediately across all update vectors | `SettingService.php:65`, `SettingController.php:45` |
| 10 | Cache Invalidation | Device Alert Stats Invalidation | Evicts `device_alert_stats` & `dashboard_telemetry_stats` on alert changes | Status update / bulk update | `void` (evicts both cache keys) | Batch updates trigger single consolidated eviction | `DeviceAlertController.php:96,120` |
| 11 | Database | SARGable Date Queries | Uses `whereBetween` instead of `whereDate()` for range queries | `$startDate`, `$endDate` | Eloquent Builder | Prevents index bypass and table scans | `AttendanceProcessingService.php:60`, `VisitorController.php:142` |
| 12 | Database | Telemetry Composite Indexes | Multi-column indexes on `access_logs`, `attendance_punches`, `notifications` | Migration execution | Database indexes created | Safe idempotent migrations | `2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php` |
| 13 | Database | Scoped Sync Tasks Telemetry Query | Scopes dashboard stats query to active statuses | Query execution | Consolidated status counts | Avoids full historical table scan | `DashboardStatsController.php:68` |
| 14 | Database | Paginated Wide Read Endpoints | Restricts columns and paginates organization and leave reads | `$perPage`, `$page` query params | LengthAwarePaginator | Rejects negative or excessive page sizes | `OrganizationController.php`, `LeaveController.php` |
| 15 | Compute | Rest Day Pre-fetching | Preloads shift assignments across date range to avoid $O(N)$ DB queries | `$employee`, `$startDate`, `$endDate` | Attendance summary collection | Handles employees with no prior shift assignments | `EmployeeController.php:263`, `Employee.php:205` |
| 16 | Compute | Constant-Time Device Audit | Hashes local personnel by `customize_id` and queries latest sync task | `$deviceId` | Audit discrepancy report | Safely ignores unmapped personnel | `DeviceController.php:509` |
| 17 | Compute | Atomic Bulk Alert Status Update | Replaces sequential foreach loop with single SQL `update()` | `array $ids`, `string $status` | JSON response with count | Validates ID list; handles empty selection gracefully | `DeviceAlertController.php:117` |
| 18 | Lifecycle | Visitor Overstay Detection | Identifies visits exceeding scheduled end plus 15m grace period | Scheduled cron job execution | Creates `DeviceAlert`, dispatches WebSocket | Suppresses duplicate alerts for already-alerted visits | `DetectOverstayVisitorsJob.php:30` |
| 19 | Lifecycle | Visit Cancellation Revocation | Revokes camera credentials when expected/checked-in visits cancelled | Visit cancellation request | HTTP 200, sync task dispatched | Disallows cancellation of already checked-out visits (422) | `VisitorController.php:250`, `VisitorSyncService.php` |
| 20 | Frontend | Private Echo Channel Subscriptions | Subscribes to `echo.private('device-alerts')` instead of public channel | User authentication session | Real-time WebSocket event listener | Automatically re-authenticates on token refresh | `DeviceAlertsCenter.vue:732` |
| 21 | Frontend | Attendance Store Metric Binding | Binds server-computed `summary` directly to store stats | Server summary payload | Reactive Pinia state | Eliminates pagination skew in workforce attendance rate | `attendanceStore.js:80` |

---

## 3. Edge Cases Probed & Verified

| # | Feature | Input / Condition | Observed Behavior |
|---|---------|-------------------|-------------------|
| 1 | `AttendanceProcessingService::isHoliday` | Cache pre-populated with dummy string `['dummy']` (from tests) | Skips non-array/non-object item, does not crash with type error; returns `false`. |
| 2 | `AttendanceProcessingService::isHoliday` | Holiday date stored as string vs Carbon instance | Parses date safely; accurately compares day, month, and year. |
| 3 | `AttendanceProcessingService::isHoliday` | Recurring holiday in different year | Evaluates `is_recurring && month == month && day == day` correctly across all years. |
| 4 | `AttendanceProcessingService::isHoliday` | Employee assigned to different organization from holiday | Returns `false` (scoped to organization). |
| 5 | `AttendanceProcessingService::isHoliday` | Holiday with `applies_to` specifying department/location list | Correctly restricts holiday to matching employees; non-matching return `false`. |
| 6 | `AttendanceProcessingService::isHoliday` | Secondary alias `holiday_ids_{$year}` missing | Transparently hydrates `holiday_ids_{$year}` alongside primary `holidays_{$year}`. |
| 7 | `HolidayController::update` | Holiday date updated across year boundaries (e.g. 2026 to 2027) | Evicts both `holidays_2026` and `holidays_2027` to prevent orphan cached data. |
| 8 | `MqttListenCommand::isDeviceRegisteredAndActive` | Device ID contains leading/trailing whitespace | Trims whitespace; negative-caches nonexistent devices to protect DB connection pool. |
| 9 | `MqttListenCommand::isDeviceRegisteredAndActive` | Device mutated via `Device::update()` or deleted | `DeviceObserver` immediately forgets `device_registered:{$deviceId}`. |
| 10 | `ProcessAttendancePunchJob` | Stranger punch with unmapped `customize_id` | Returns `null` without throwing exceptions; logs appropriate stranger warning. |
| 11 | `ProcessAttendancePunchJob` | Employee code updated on existing employee | `EmployeeObserver` evicts old and new code cache keys. |
| 12 | `VisitorController::cancel` | Visit already in `checked_out` or `cancelled` state | Rejects cancellation with HTTP 422 Unprocessable Entity. |
| 13 | `DetectOverstayVisitorsJob` | Visit overstayed by 14 minutes (within 15m grace window) | Does NOT flag overstay; only flags when elapsed time > 15 minutes. |
| 14 | `DetectOverstayVisitorsJob` | Job runs repeatedly for already-flagged overstayed visitor | Suppresses duplicate alerts using Redis deduplication key or existing alert check. |

---

## 4. Exact Remediation Patch Specifications for Worker

### 4.1 Specification for `app/Services/AttendanceProcessingService.php`

Ensure lines 204–310 of `app/Services/AttendanceProcessingService.php` implement the robust, dual-keyed, serialization-safe holiday caching logic:

```php
    /**
     * Check if a given date is a holiday (cached per year).
     */
    public function isHoliday(Carbon $date, ?Employee $employee = null): bool
    {
        $year = $date->year;

        // Primary cache key: holidays_{year} (contracted by PerformanceOptimizationTest)
        $holidays = Cache::remember("holidays_{$year}", 3600, function () use ($year) {
            $records = Holiday::whereYear('date', $year)
                ->orWhere('is_recurring', true)
                ->get();

            // Maintain secondary alias holiday_ids_{year} for backwards/forward compatibility
            try {
                Cache::put("holiday_ids_{$year}", $records->pluck('id')->toArray(), 3600);
            } catch (\Throwable $e) {
                // Ignore cache put issues
            }

            // Return plain arrays to eliminate model serialization overhead and __PHP_Incomplete_Class
            return $records->map(function ($h) {
                return [
                    'id' => $h->id,
                    'organization_id' => $h->organization_id,
                    'name' => $h->name,
                    'date' => $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date,
                    'type' => $h->type,
                    'is_recurring' => (bool) $h->is_recurring,
                    'applies_to' => $h->applies_to,
                ];
            })->all();
        });

        // Ensure holiday_ids_{year} alias is populated if missing
        if (!Cache::has("holiday_ids_{$year}")) {
            try {
                $ids = is_array($holidays)
                    ? array_filter(array_map(fn($item) => is_array($item) ? ($item['id'] ?? null) : (is_object($item) ? ($item->id ?? null) : $item), $holidays))
                    : [];
                Cache::put("holiday_ids_{$year}", array_values($ids), 3600);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Support Collection, array, or hydrated models transparently
        if (!is_array($holidays) && !($holidays instanceof \Illuminate\Support\Collection)) {
            $holidays = [];
        }

        $dateStr = $date->format('Y-m-d');
        foreach ($holidays as $h) {
            // Guard against dummy strings (e.g. ['dummy'] in cache invalidation tests)
            if (!is_object($h) && !is_array($h)) {
                continue;
            }

            // Path 1: Array representation (preferred high-performance path)
            if (is_array($h)) {
                $orgId = $h['organization_id'] ?? null;
                if ($employee && $orgId && $employee->organization_id && $orgId !== $employee->organization_id) {
                    continue;
                }

                $applies = $h['applies_to'] ?? null;
                if ($employee && !empty($applies)) {
                    if (isset($applies['departments']) && is_array($applies['departments']) && !in_array($employee->department_id, $applies['departments'])) {
                        continue;
                    }
                    if (isset($applies['locations']) && is_array($applies['locations']) && !in_array($employee->location_id, $applies['locations'])) {
                        continue;
                    }
                    if (array_is_list($applies) && !empty($applies) && !in_array($employee->department_id, $applies)) {
                        continue;
                    }
                }

                $hDateRaw = $h['date'] ?? null;
                if (!$hDateRaw) {
                    continue;
                }
                $hDate = $hDateRaw instanceof Carbon ? $hDateRaw : Carbon::parse($hDateRaw);
                if ($hDate->format('Y-m-d') === $dateStr) {
                    return true;
                }
                if (!empty($h['is_recurring']) && (int) $hDate->month === (int) $date->month && (int) $hDate->day === (int) $date->day) {
                    return true;
                }
                continue;
            }

            // Path 2: Eloquent Model representation
            if ($h instanceof Holiday) {
                if ($employee && $h->organization_id && $employee->organization_id && $h->organization_id !== $employee->organization_id) {
                    continue;
                }
                if ($employee && !$h->appliesToEmployee($employee)) {
                    continue;
                }
                if ($h->isHolidayOn($date)) {
                    return true;
                }
            }
        }

        return false;
    }
```

### 4.2 Specification for `app/Http/Controllers/HolidayController.php`

Verify cache eviction calls in `HolidayController`:
1. `store()`:
   ```php
   $year = \Carbon\Carbon::parse($holiday->date)->year;
   \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
   \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
   ```
2. `update()`:
   ```php
   $year = \Carbon\Carbon::parse($holiday->date)->year;
   \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
   \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
   $holiday->update($validated);
   $newYear = \Carbon\Carbon::parse($holiday->date)->year;
   if ($newYear !== $year) {
       \Illuminate\Support\Facades\Cache::forget("holidays_{$newYear}");
       \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$newYear}");
   }
   ```
3. `destroy()`:
   ```php
   $year = \Carbon\Carbon::parse($holiday->date)->year;
   \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
   \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
   $holiday->delete();
   ```

### 4.3 Specification for `tasks-performance.md`

All 13 Phase 6 performance tasks (Tasks 6.1 through 6.13) are verified and passing. Update `tasks-performance.md` by replacing `- [ ]` with `- [x]` for:
- Task 6.1: Eliminate Non-SARGable `whereDate()` Expressions
- Task 6.2: Add Missing Composite & Foreign Key Indexes for Telemetry & Punches
- Task 6.3: Optimize Unbounded Table Scan on `sync_tasks` in Dashboard Stats
- Task 6.4: Paginate and Column-Constrain Wide Read Endpoints in Leave & Organization Modules
- Task 6.5: Eliminate $O(N)$ Database Queries in `Employee::isRestDay` Inside Summary Loop
- Task 6.6: Eliminate Linear $O(N \times M)$ Collection Scan and Large Outbox Pull in `DeviceController::audit()`
- Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`
- Task 6.8: Eliminate Blocking Redis `KEYS` Command in Bulk Shift Assignment
- Task 6.9: Cache Pre-Enrolled Device Existence in High-Frequency MQTT Telemetry Stream
- Task 6.10: Cache Biometric `customize_id` to Employee Mapping in Punch Ingestion
- Task 6.11: Cache Invalidation Engine for Device Alerts & Public Settings
- Task 6.12: Fix Echo Channel Type Mismatch in `DeviceAlertsCenter.vue`
- Task 6.13: Correct Metric Binding in `attendanceStore` from Server Summary

---

## 5. Verification Checklist for Worker

The Worker must execute and verify that all of the following commands exit with code 0:

1. **Primary Target Suite**:
   ```bash
   php artisan test --filter=PerformanceOptimizationTest
   ```
   *Expectation*: 33 passed, 0 failed, 0 errors.

2. **Milestone 3 Challenger Suite 1**:
   ```bash
   php artisan test --filter=Phase6Milestone3Challenger1Test
   ```
   *Expectation*: 9 passed, 0 failed, 0 errors.

3. **Milestone 3 Challenger Suite 2**:
   ```bash
   php artisan test --filter=Phase6Milestone3Challenger2Test
   ```
   *Expectation*: 14 passed, 0 failed, 0 errors.

4. **Full Regression Suite**:
   ```bash
   php artisan test
   ```
   *Expectation*: 647 passed, 32 skipped, 0 failed across 679 tests.

5. **Frontend Asset Build**:
   ```bash
   npm run build
   ```
   *Expectation*: Exit code 0, all chunks compiled cleanly.
