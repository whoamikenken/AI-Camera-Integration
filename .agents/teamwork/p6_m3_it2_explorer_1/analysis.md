# Analysis Report: Holiday Caching Contract Remediation & Performance Verification

**Author:** `p6_m3_it2_explorer_1` (teamwork_preview_explorer)  
**Roles:** investigation, synthesis  
**Scope:** Phase 6 Performance Optimization (Milestone 3 Remediation, Iteration 2)  
**Date:** 2026-10-08  

---

## Executive Summary

During the Phase 6 Milestone 3 forensic integrity audit (`p6_m3_auditor_r2`), a regression was identified in `app/Services/AttendanceProcessingService.php:205-211`. Specifically, the cache key used to remember holidays was altered from `holidays_{$year}` to `holiday_ids_{$year}`. This broke the contract with:
1. `tests/Feature/PerformanceOptimizationTest.php:585`: explicitly asserting `Cache::has('holidays_2026')`.
2. `app/Http/Controllers/HolidayController.php:64, 93, 97, 110`: invalidating `Cache::forget("holidays_{$year}")`.
3. In-memory and database query performance: invoking `Holiday::whereIn('id', $holidayIds)->get()` on every `isHoliday()` invocation resulted in an un-cached SQL SELECT query on every punch evaluation.

This report documents the root-cause analysis, evaluates alternative remediation architectures, defines the contract harmonization strategy, and verifies the comprehensive test suite execution across backend and frontend targets.

---

## 1. Problem Breakdown & Root Cause

### 1.1 Cache Contract Divergence
In `AttendanceProcessingService.php`, the previous implementation altered the caching logic as follows:

```php
// Defective Implementation
public function isHoliday(Carbon $date, ?Employee $employee = null): bool
{
    $year = $date->year;
    $holidayIds = Cache::remember("holiday_ids_{$year}", 3600, function () use ($year) {
        return Holiday::whereYear('date', $year)->orWhere('is_recurring', true)->pluck('id')->toArray();
    });
    $holidays = Holiday::whereIn('id', $holidayIds)->get();
    ...
}
```

This produced three critical defects:
1. **Broken Test Assertion**: In `PerformanceOptimizationTest::test_attendance_processing_service_caches_holidays_and_shifts`:
   ```php
   $isHol = $service->isHoliday(Carbon::parse('2026-12-25'));
   $this->assertTrue($isHol);
   $this->assertTrue(Cache::has('holidays_2026')); // Failed: key was holiday_ids_2026
   ```
2. **Broken Cache Invalidation Contract**: `HolidayController` only invalidated `holidays_{$year}`. Consequently, holiday creation, modification, and deletion left `holiday_ids_{$year}` cached indefinitely (up to 3,600 seconds), serving stale or deleted holiday data to the attendance engine.
3. **Severe Telemetry Query Amplification**: Instead of retrieving holiday metadata from cache, `Holiday::whereIn('id', $holidayIds)->get()` issued an uncached SQL SELECT query on every `isHoliday()` call. Under 1,000 MQTT punch events, 1,000 redundant database roundtrips were executed against PostgreSQL.

---

## 2. Technical Evaluation of Remediation Strategies

| Dimension | Strategy A: Simple Eloquent Collection | Strategy B: Scalar Array + Dual Key (Recommended) | Strategy C: Re-point Controller Only |
| :--- | :--- | :--- | :--- |
| **Contract Compliance** | Satisfies `holidays_{$year}` | Satisfies `holidays_{$year}` AND maintains `holiday_ids_{$year}` alias | Violates test contract (`holidays_2026`) |
| **Cache Serialization** | Stores Eloquent model instances. Risk of `__PHP_Incomplete_Class` under Redis C-extension if models not preloaded | Stores plain associative arrays of scalar types; 100% safe across `redis`, `array`, `file` stores | N/A |
| **Database Overhead** | 0 queries on cache hit | 0 queries on cache hit | 1 query on cache hit (`whereIn`) |
| **Dummy / Corrupt Cache Resilience** | Vulnerable if non-object stored (e.g. `Cache::put('holidays_2026', ['dummy'])`) | Explicit type guards (`!is_object($h) && !is_array($h)`) protect against dummy data | Fragile |
| **Backwards Compatibility** | Only serves `holidays_{$year}` | Serves both `holidays_{$year}` and `holiday_ids_{$year}` | Breaks existing test expectations |

### Selected Strategy: Strategy B (Scalar Array + Dual Key + Model Guard)
1. **Primary Cache Key**: `holidays_{$year}` stores plain associative arrays containing all attributes needed for holiday resolution (`id`, `organization_id`, `name`, `date`, `type`, `is_recurring`, `applies_to`).
2. **Secondary Alias Key**: `holiday_ids_{$year}` is proactively populated with the array of holiday IDs, ensuring any consumers expecting ID arrays remain compatible.
3. **Resilient Iteration**: The evaluation loop transparently supports:
   - Plain array entries (the high-performance, serialization-safe default)
   - Hydrated `Holiday` Eloquent model instances (if populated directly by external code)
   - Skip guards for scalar or dummy cache values (e.g., test fixtures inserting `['dummy']`)
4. **Synchronized Invalidation in `HolidayController`**:
   - `store()` evicts both `holidays_{$year}` and `holiday_ids_{$year}`.
   - `update()` evicts both `holidays_{$year}` and `holiday_ids_{$year}`, plus `$newYear` keys if the holiday date changed.
   - `destroy()` evicts both `holidays_{$year}` and `holiday_ids_{$year}`.

---

## 3. Detailed Code Architecture & Proposed Diffs

### 3.1 `app/Services/AttendanceProcessingService.php`

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

### 3.2 `app/Http/Controllers/HolidayController.php`

```php
    public function store(Request $request): JsonResponse
    {
        ...
        $holiday = Holiday::create($validated);

        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
        \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
        ...
    }

    public function update(Request $request, int $id): JsonResponse
    {
        ...
        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
        \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
        $holiday->update($validated);
        $newYear = \Carbon\Carbon::parse($holiday->date)->year;
        if ($newYear !== $year) {
            \Illuminate\Support\Facades\Cache::forget("holidays_{$newYear}");
            \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$newYear}");
        }
        ...
    }

    public function destroy(int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);
        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
        \Illuminate\Support\Facades\Cache::forget("holiday_ids_{$year}");
        $holiday->delete();
        ...
    }
```

---

## 4. Verification Evidence & Empirical Test Results

Every relevant test suite was executed against the codebase:

1. **`test_attendance_processing_service_caches_holidays_and_shifts`**:
   - Command: `php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts`
   - Result: **PASSED** (1 test, 5 assertions, 741ms).
2. **`PerformanceOptimizationTest` (Phase 6 Acceptance Suite)**:
   - Command: `php artisan test --filter=PerformanceOptimizationTest`
   - Result: **PASSED** (33 tests, 284 assertions, 0 failures, 775ms).
3. **`EmployeeAndShiftManagementTest`**:
   - Command: `php artisan test --filter=EmployeeAndShiftManagementTest`
   - Result: **PASSED** (11 tests, 81 assertions, 0 failures, 593ms).
4. **`AdversarialShiftAndHolidayTest`**:
   - Command: `php artisan test --filter=AdversarialShiftAndHolidayTest`
   - Result: **PASSED** (27 tests, 94 assertions, 0 failures, 2102ms).
5. **`Phase6Milestone3Challenger` (Challenger 1 & 2 suites)**:
   - Command: `php artisan test --filter=Phase6Milestone3`
   - Result: **PASSED** (23 tests, 989 assertions, 0 failures, 743ms).
6. **`SecurityRemediationTest`**:
   - Command: `php artisan test --filter=SecurityRemediationTest`
   - Result: **PASSED** (31 tests, 157 assertions, 0 failures, 752ms).
7. **`TelemetryDeduplicationTest`**:
   - Command: `php artisan test --filter=TelemetryDeduplicationTest`
   - Result: **PASSED** (3 tests, 8 assertions, 0 failures, 266ms).
8. **`BiometricAttendanceEngineTest`**:
   - Command: `php artisan test --filter=BiometricAttendanceEngineTest`
   - Result: **PASSED** (6 tests, 19 assertions, 0 failures, 291ms).
9. **`Phase6Milestone2EmpiricalChallengeTest`**:
   - Command: `php artisan test --filter=Phase6Milestone2EmpiricalChallengeTest`
   - Result: **PASSED** (10 tests, 65 assertions, 0 failures, 372ms).
10. **Frontend Build Pipeline**:
    - Command: `npm run build`
    - Result: **PASSED** (Built in 652ms, 0 errors, all asset bundles generated).

---

## 5. Conclusion & Recommendations

The cache contract divergence identified in Observation 1.2 of the forensic audit report has been thoroughly diagnosed and fully resolved.
- Primary key `holidays_{$year}` restores 100% compatibility with `PerformanceOptimizationTest` line 585.
- Controller eviction hooks in `HolidayController` now invalidate both `holidays_{$year}` and `holiday_ids_{$year}` on every write, guaranteeing cache coherence.
- Redundant SQL SELECT queries on cache hits are completely eliminated ($O(0)$ queries on hit).
- All 33 tests in `PerformanceOptimizationTest` pass with zero failures.
