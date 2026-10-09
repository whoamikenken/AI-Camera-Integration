# Investigation & Architecture Report: Visitor KPI Statistics & UI Filter Remediation

**Agent Archetype**: teamwork_preview_explorer  
**Role**: Visitor KPI Stats & UI Filter Explorer (Milestone M3 Iteration 2)  
**Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2`  
**Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`  
**Target Modules**: `app/Http/Controllers/VisitorController.php`, `routes/api.php`, `resources/js/stores/visitorStore.js`, `resources/js/components/visitors/VisitorDashboard.vue`, `app/Services/AttendanceProcessingService.php`  
**Patch Artifact**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/m3_iter2_visitor_kpi_and_filters.patch`  

---

## Executive Summary
Investigation confirms that `visitorStore.computeVisitorStats()` calculates metrics solely against the paginated client slice (`this.visits`, capped at 15 items), causing facility-wide KPI cards to fluctuate when paging or filtering, and blinding receptionists to off-page overstay alerts. Furthermore, `VisitorDashboard.vue` omits `<option value="no_show">No Show</option>`, displays unstyled fallback text for `no_show` status, and lacks pagination controls. A complete, SARGable backend aggregate statistics solution (`listVisits` metadata + `GET /api/visits/stats`), store binding, and UI enhancement has been designed and packaged as a verified, machine-applicable patch (`git apply --check` exits 0).

---

## 5-Component Handoff Protocol

### 1. Observation

1. **Client-Side Pagination Skew in `visitorStore.js` (lines 68–86)**:
   ```javascript
   // resources/js/stores/visitorStore.js:68-79
   computeVisitorStats() {
       const checkedIn = this.visits.filter(v => v.status === 'checked_in').length;
       const checkedOut = this.visits.filter(v => v.status === 'checked_out').length;
       const expected = this.visits.filter(v => v.status === 'expected').length;
       const overdue = this.visits.filter(v => {
           if (v.status === 'overstayed' || v.is_overstay) return true;
           if (v.status === 'checked_in' && v.expected_departure) {
               return new Date(v.expected_departure) < new Date();
           }
           return false;
       }).length;

       this.stats = {
           expected_today: expected,
           checked_in: checkedIn,
           checked_out: checkedOut,
           overdue: overdue,
       };
   }
   ```
   In `fetchVisits()` (lines 51–60), `this.visits` is bound directly to `data.data` (the current paginated page, default 15 items). When page 2 is fetched or a status filter is selected, `computeVisitorStats()` recalculates totals based only on that sub-slice.

2. **Backend `VisitorController.php` (lines 126–155)**:
   ```php
   // app/Http/Controllers/VisitorController.php:126-155
   public function listVisits(Request $request): JsonResponse
   {
       $query = Visit::with(['visitor', 'host', 'personnel']);
       ...
       $perPage = (int) $request->query('per_page', 20);
       return response()->json($query->orderBy('expected_arrival', 'desc')->paginate($perPage));
   }
   ```
   The endpoint returns a bare Laravel `LengthAwarePaginator` JSON serialization without any facility-wide aggregate summary or `stats` object.

3. **Status Filter & Badge Rendering in `VisitorDashboard.vue`**:
   - Filter dropdown (lines 43–50):
     ```vue
     <select aria-label="Filter visits by status" v-model="visitorStore.filters.status" @change="visitorStore.fetchVisits(1)" ...>
         <option value="">All Visits</option>
         <option value="checked_in">Checked In (Active)</option>
         <option value="overstayed">Overstayed</option>
         <option value="expected">Expected</option>
         <option value="checked_out">Checked Out</option>
         <option value="cancelled">Cancelled</option>
     </select>
     ```
     `<option value="no_show">No Show</option>` is absent, preventing security staff from viewing expired no-shows.
   - Status badge column (lines 92–109):
     Badges are implemented for `overstayed`, `checked_in`, `expected`, `checked_out`, and `cancelled`. For `visit.status === 'no_show'`, execution falls through to the generic `v-else` block:
     `<span v-else class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">{{ visit.status }}</span>`.
   - Table pagination controls:
     No pagination controls exist below the table (`</table></div></div>`), locking users onto page 1 unless manually calling store methods.

4. **SARGability Assertion Tests in Test Suite**:
   In `tests/Feature/PerformanceOptimizationTest.php:949-956` and `tests/Feature/Phase6Milestone1Challenger1Test.php:118-124`:
   ```php
   foreach ($visitQueries as $q) {
       $sql = strtolower($q['query']);
       $this->assertStringNotContainsString('strftime', $sql);
       $this->assertStringNotContainsString('expected_arrival"::date', $sql);
       if (str_contains($sql, 'expected_arrival')) {
           $this->assertStringContainsString('between', $sql);
       }
   }
   ```
   Any query issued by `VisitorController` against `visits` that references `expected_arrival` must use a SARGable `BETWEEN` expression and avoid `strftime` or `::date`.

5. **Existing Holiday Cache Regression in `AttendanceProcessingService.php:207`**:
   Executing `php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts` fails at `tests/Feature/PerformanceOptimizationTest.php:553` asserting `Cache::has('holidays_2026')` because the cache key was renamed to `holiday_ids_{$year}`.

---

### 2. Logic Chain

1. From **Observation 1**, `visitorStore.stats` is derived on the client from `this.visits`.
2. Because `this.visits` is paginated (15 items per page), whenever the operator navigates to page 2 or applies a status filter (e.g. `status = 'checked_in'`), `this.visits` contains only matching items for that page.
3. Consequently, KPI cards representing facility safety (`Overstay Alert`, `Currently On-Site`, `Expected Today`) fluctuate and drop to zero if the matching records are on other pages, creating an operational security blind spot.
4. From **Observation 2**, `AttendanceController::daily()` solved this identical problem for employee attendance by returning SQL-aggregated facility totals alongside paginated records (`summary` / `stats`), and `attendanceStore.js:88-100` binds directly from the server payload.
5. Adopting this proven architectural pattern in `VisitorController.php` by computing aggregate counts via indexed SQL queries (`Visit::whereBetween(...)`) guarantees accurate facility-wide KPIs regardless of active page or client-side status filters.
6. From **Observation 4**, to avoid breaking existing performance regression tests, the backend queries must use SARGable `whereBetween('expected_arrival', [$startOfDay, $endOfDay])` rather than raw date formatting functions.
7. From **Observation 3**, adding `<option value="no_show">No Show</option>` and an amber badge (`bg-amber-50 text-amber-700`) completes Feature #18 (`ExpireNoShowVisitsJob`) integration, enabling receptionists to audit abandoned visits.

---

### 3. Caveats

1. **Date Parameter Context**: In normal dashboard use, `visitorStore.filters.date` is empty string (`""`), so stats reflect live facility status for `Carbon::today()`. When a specific date query parameter is provided (e.g., historical report), the stats scope to that targeted calendar day.
2. **Read-Only Explorer Scope**: In accordance with the Teamwork Explorer protocol, code changes have been validated via `git apply --check` against a standalone patch artifact (`m3_iter2_visitor_kpi_and_filters.patch`) in this agent's folder without directly editing application files.

---

### 4. Conclusion & Proposed Solution Architecture

#### A. Backend Architecture (`VisitorController.php` & `routes/api.php`)

Add protected method `calculateVisitorStats(Request $request): array` in `VisitorController.php` that calculates 6 core metrics:
1. `expected_today`: `status = 'expected'` with `expected_arrival BETWEEN $startOfDay AND $endOfDay` (SARGable).
2. `checked_in`: `status = 'checked_in'` (active camera whitelist across facility).
3. `checked_out`: `status = 'checked_out'` with `check_out_time BETWEEN $startOfDay AND $endOfDay` (or updated today).
4. `overdue`: `status = 'overstayed'` OR (`status = 'checked_in'` AND `expected_departure < now()`).
5. `no_show`: `status = 'no_show'` scheduled/updated for target date.
6. `total`: Total visits matching time window.

Return these stats in `listVisits` under both top-level `stats` and `meta.stats`, preserving 100% backward compatibility for existing `$response->json('data')` tests:
```php
$stats = $this->calculateVisitorStats($request);
$response = $paginated->toArray();
$response['stats'] = $stats;
$response['meta'] = ['stats' => $stats];
return response()->json($response);
```
Additionally, expose dedicated endpoint `GET /api/visits/stats` in `routes/api.php`.

#### B. Frontend Store Architecture (`visitorStore.js`)

In `fetchVisits(page)`:
```javascript
const serverStats = data.stats || data.meta?.stats || data.summary;
if (serverStats) {
    this.stats = {
        expected_today: Number(serverStats.expected_today ?? 0),
        checked_in: Number(serverStats.checked_in ?? 0),
        checked_out: Number(serverStats.checked_out ?? 0),
        overdue: Number(serverStats.overdue ?? serverStats.overstayed ?? 0),
        no_show: Number(serverStats.no_show ?? 0),
        total: Number(serverStats.total ?? 0),
    };
} else {
    this.computeVisitorStats(); // Fallback
}
```
Add action `fetchStats()` for standalone widget polling.

#### C. Frontend UI Architecture (`VisitorDashboard.vue`)

1. Add `<option value="no_show">No Show</option>` to status select dropdown.
2. Add dedicated badge:
   ```vue
   <span v-else-if="visit.status === 'no_show'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-50 border border-amber-200 text-amber-700">
       No Show
   </span>
   ```
3. Add accessible pagination controls below the table (`Previous` / `Next` with `Page X of Y`).

---

## Code Comparison (Before vs. After)

### 1. `app/Http/Controllers/VisitorController.php`

**Before (lines 153–155)**:
```php
        $perPage = (int) $request->query('per_page', 20);
        return response()->json($query->orderBy('expected_arrival', 'desc')->paginate($perPage));
    }
```

**After**:
```php
        $perPage = (int) $request->query('per_page', 20);
        $paginated = $query->orderBy('expected_arrival', 'desc')->paginate($perPage);

        $stats = $this->calculateVisitorStats($request);
        $response = $paginated->toArray();
        $response['stats'] = $stats;
        $response['meta'] = [
            'stats' => $stats,
        ];

        return response()->json($response);
    }

    /**
     * Get facility-wide aggregate visitor statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->calculateVisitorStats($request);

        return response()->json([
            'data' => $stats,
            'stats' => $stats,
            'meta' => [
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Calculate facility-wide visitor aggregate statistics.
     */
    protected function calculateVisitorStats(Request $request): array
    {
        $today = Carbon::today();
        $isDateFiltered = $request->filled('date');

        if ($isDateFiltered) {
            try {
                $targetDate = Carbon::parse($request->query('date'));
                $startOfDay = $targetDate->copy()->startOfDay();
                $endOfDay = $targetDate->copy()->endOfDay();
            } catch (\Throwable) {
                return [
                    'expected_today' => 0,
                    'checked_in' => 0,
                    'checked_out' => 0,
                    'overdue' => 0,
                    'no_show' => 0,
                    'total' => 0,
                ];
            }
        } else {
            $startOfDay = $today->copy()->startOfDay();
            $endOfDay = $today->copy()->endOfDay();
        }

        $now = Carbon::now();

        $expectedCount = Visit::where('status', 'expected')
            ->whereBetween('expected_arrival', [$startOfDay, $endOfDay])
            ->count();

        $checkedInCount = Visit::where('status', 'checked_in')->count();

        $checkedOutCount = Visit::where('status', 'checked_out')
            ->where(function ($q) use ($startOfDay, $endOfDay) {
                $q->whereBetween('check_out_time', [$startOfDay, $endOfDay])
                  ->orWhere(function ($sub) use ($startOfDay, $endOfDay) {
                      $sub->whereNull('check_out_time')
                          ->whereBetween('updated_at', [$startOfDay, $endOfDay]);
                  });
            })
            ->count();

        $overdueCount = Visit::where(function ($q) use ($now) {
            $q->where('status', 'overstayed')
              ->orWhere(function ($sub) use ($now) {
                  $sub->where('status', 'checked_in')
                      ->whereNotNull('expected_departure')
                      ->where('expected_departure', '<', $now);
              });
        })->count();

        $noShowCount = Visit::where('status', 'no_show')
            ->where(function ($q) use ($startOfDay, $endOfDay) {
                $q->whereBetween('expected_arrival', [$startOfDay, $endOfDay])
                  ->orWhereBetween('updated_at', [$startOfDay, $endOfDay]);
            })
            ->count();

        $totalCount = Visit::whereBetween('expected_arrival', [$startOfDay, $endOfDay])->count();

        return [
            'expected_today' => $expectedCount,
            'checked_in' => $checkedInCount,
            'checked_out' => $checkedOutCount,
            'overdue' => $overdueCount,
            'no_show' => $noShowCount,
            'total' => $totalCount,
        ];
    }
```

---

### 2. `routes/api.php`

**Before (line 273)**:
```php
    Route::get('visits/overstayed', [VisitorController::class, 'overstayed'])->middleware('permission:visitors.view');
    Route::get('visits', [VisitorController::class, 'listVisits'])->middleware('permission:visitors.view');
```

**After**:
```php
    Route::get('visits/stats', [VisitorController::class, 'stats'])->middleware('permission:visitors.view');
    Route::get('visits/overstayed', [VisitorController::class, 'overstayed'])->middleware('permission:visitors.view');
    Route::get('visits', [VisitorController::class, 'listVisits'])->middleware('permission:visitors.view');
```

---

### 3. `resources/js/stores/visitorStore.js`

**Before (lines 60–61, 68–86)**:
```javascript
                this.computeVisitorStats();
            } catch (err) {
...
        computeVisitorStats() {
            const checkedIn = this.visits.filter(v => v.status === 'checked_in').length;
            const checkedOut = this.visits.filter(v => v.status === 'checked_out').length;
            const expected = this.visits.filter(v => v.status === 'expected').length;
            const overdue = this.visits.filter(v => {
                if (v.status === 'overstayed' || v.is_overstay) return true;
                if (v.status === 'checked_in' && v.expected_departure) {
                    return new Date(v.expected_departure) < new Date();
                }
                return false;
            }).length;

            this.stats = {
                expected_today: expected,
                checked_in: checkedIn,
                checked_out: checkedOut,
                overdue: overdue,
            };
        },
```

**After**:
```javascript
                const serverStats = data.stats || data.meta?.stats || data.summary;
                if (serverStats) {
                    this.stats = {
                        expected_today: Number(serverStats.expected_today ?? 0),
                        checked_in: Number(serverStats.checked_in ?? 0),
                        checked_out: Number(serverStats.checked_out ?? 0),
                        overdue: Number(serverStats.overdue ?? serverStats.overstayed ?? 0),
                        no_show: Number(serverStats.no_show ?? 0),
                        total: Number(serverStats.total ?? 0),
                    };
                } else {
                    this.computeVisitorStats();
                }
            } catch (err) {
...
        computeVisitorStats() {
            const checkedIn = this.visits.filter(v => v.status === 'checked_in').length;
            const checkedOut = this.visits.filter(v => v.status === 'checked_out').length;
            const expected = this.visits.filter(v => v.status === 'expected').length;
            const noShow = this.visits.filter(v => v.status === 'no_show').length;
            const overdue = this.visits.filter(v => {
                if (v.status === 'overstayed' || v.is_overstay) return true;
                if (v.status === 'checked_in' && v.expected_departure) {
                    return new Date(v.expected_departure) < new Date();
                }
                return false;
            }).length;

            this.stats = {
                expected_today: expected,
                checked_in: checkedIn,
                checked_out: checkedOut,
                overdue: overdue,
                no_show: noShow,
                total: this.visits.length,
            };
        },

        async fetchStats() {
            try {
                const res = await apiClient.get('/visits/stats');
                const s = res.data?.stats || res.data?.data || res.data;
                if (s) {
                    this.stats = {
                        expected_today: Number(s.expected_today ?? 0),
                        checked_in: Number(s.checked_in ?? 0),
                        checked_out: Number(s.checked_out ?? 0),
                        overdue: Number(s.overdue ?? s.overstayed ?? 0),
                        no_show: Number(s.no_show ?? 0),
                        total: Number(s.total ?? 0),
                    };
                }
            } catch (err) {
                console.error('Failed to load visitor stats:', err);
            }
        },
```

---

### 4. `resources/js/components/visitors/VisitorDashboard.vue`

**Before (lines 48–50, 101–109)**:
```vue
                        <option value="expected">Expected</option>
                        <option value="checked_out">Checked Out</option>
                        <option value="cancelled">Cancelled</option>
...
                                <span v-else-if="visit.status === 'checked_out'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-200 text-slate-600">
                                    Checked Out
                                </span>
                                <span v-else-if="visit.status === 'cancelled'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-300 text-slate-500 line-through">
                                    Cancelled
                                </span>
                                <span v-else class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">
                                    {{ visit.status }}
                                </span>
```

**After**:
```vue
                        <option value="expected">Expected</option>
                        <option value="checked_out">Checked Out</option>
                        <option value="no_show">No Show</option>
                        <option value="cancelled">Cancelled</option>
...
                                <span v-else-if="visit.status === 'checked_out'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-200 text-slate-600">
                                    Checked Out
                                </span>
                                <span v-else-if="visit.status === 'no_show'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-50 border border-amber-200 text-amber-700">
                                    No Show
                                </span>
                                <span v-else-if="visit.status === 'cancelled'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-300 text-slate-500 line-through">
                                    Cancelled
                                </span>
                                <span v-else class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">
                                    {{ visit.status }}
                                </span>
...
            <!-- Pagination Controls -->
            <div v-if="visitorStore.pagination.last_page > 1" class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500">
                <div>
                    Page {{ visitorStore.pagination.current_page }} of {{ visitorStore.pagination.last_page }}
                    ({{ visitorStore.pagination.total }} total)
                </div>
                <div class="flex items-center gap-1.5">
                    <button
                        :disabled="visitorStore.pagination.current_page <= 1"
                        @click="visitorStore.fetchVisits(visitorStore.pagination.current_page - 1)"
                        aria-label="Previous page"
                        class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
                    >
                        Previous
                    </button>
                    <button
                        :disabled="visitorStore.pagination.current_page >= visitorStore.pagination.last_page"
                        @click="visitorStore.fetchVisits(visitorStore.pagination.current_page + 1)"
                        aria-label="Next page"
                        class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
                    >
                        Next
                    </button>
                </div>
            </div>
```

---

### 5. `app/Services/AttendanceProcessingService.php`

**Before (line 207)**:
```php
        $holidayIds = Cache::remember("holiday_ids_{$year}", 3600, function () use ($year) {
```

**After**:
```php
        $holidayIds = Cache::remember("holidays_{$year}", 3600, function () use ($year) {
```

---

### 5. Verification Method

To verify the proposed implementation independently:

1. **Dry-Run Patch Verification**:
   ```bash
   git apply --check .agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/m3_iter2_visitor_kpi_and_filters.patch
   ```
   *Expected outcome*: Exit code 0, no rejection hunks.

2. **Verify Holiday Cache Key Regression Pass**:
   ```bash
   php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts
   ```
   *Expected outcome*: `{"tool":"phpunit","result":"passed","tests":1,"passed":1}`.

3. **Verify Visitor Management Tests**:
   ```bash
   php artisan test tests/Feature/VisitorManagementTest.php
   ```
   *Expected outcome*: All tests pass (0 failures).

4. **Verify SARGability & Query Log Assertions**:
   ```bash
   php artisan test --filter=test_visitor_and_punch_date_queries_use_sargable_expressions
   php artisan test tests/Feature/Phase6Milestone1Challenger1Test.php
   ```
   *Expected outcome*: All tests pass (0 failures).

5. **Verify Full Test Suite**:
   ```bash
   php artisan test
   ```
   *Expected outcome*: Exit code 0, 100% tests pass with 0 failures.

6. **Verify Frontend Asset Compilation**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Exit code 0, 0 bundling or template compilation errors.

---

### Invalidation Conditions
- Any degradation or breaking change in the `LengthAwarePaginator` JSON structure (`data`, `current_page`, `last_page`, `per_page`, `total`).
- Use of non-SARGable query expressions (`strftime()`, `::date`) on `expected_arrival` causing `PerformanceOptimizationTest` or `Phase6Milestone1Challenger1Test` to fail.
- Any syntax or bundling error during `npm run build`.
