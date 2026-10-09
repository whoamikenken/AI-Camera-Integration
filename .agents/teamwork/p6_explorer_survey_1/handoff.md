# Handoff Report: Phase 6 Database & Schema Optimization Survey (Tasks 6.1 – 6.4)

## 1. Observation
1. **Task 6.1**:
   - `app/Services/AttendanceProcessingService.php:59-63`:
     ```php
     $lastPunchToday = AttendancePunch::where('employee_id', $employee->id)
         ->whereDate('punch_time', $punchTime->toDateString())
         ->orderBy('punch_time', 'desc')
         ->first();
     ```
   - `app/Http/Controllers/VisitorController.php:141-144`:
     ```php
     if ($request->filled('date')) {
         $query->whereDate('expected_arrival', $request->query('date'));
     }
     ```
   - Running EXPLAIN on PostgreSQL for `expected_arrival::date = '2026-10-07'` returns:
     `Seq Scan on visits (cost=0.00..12.40 rows=1 width=479)`
     `Filter: ((expected_arrival)::date = '2026-10-07'::date)`
   - Running EXPLAIN on PostgreSQL for `expected_arrival BETWEEN '2026-10-07 00:00:00' AND '2026-10-07 23:59:59'` returns:
     `Index Scan using idx_visits_expected_arrival_status on visits (cost=0.14..8.16 rows=1 width=479)`
     `Index Cond: ((expected_arrival >= ...) AND (expected_arrival <= ...))`
   - `attendance_punches` has composite index `attendance_punches_employee_id_punch_time_index` on `(employee_id, punch_time)`.

2. **Task 6.2**:
   - PostgreSQL `pg_indexes` check confirms:
     - `access_logs` has indexes on `(device_id)` and `(captured_at)`, but lacks composite index on `(device_id, captured_at)`.
     - `EXPLAIN SELECT * FROM access_logs WHERE device_id = '1299517' ORDER BY captured_at DESC LIMIT 50` outputs:
       `-> Sort (cost=8.17..8.17 rows=1 width=1440)`
       `Sort Key: captured_at DESC`
       `-> Index Scan using access_logs_device_id_index on access_logs`
     - `attendance_punches` has NO index on `device_id`. `EXPLAIN SELECT * FROM attendance_punches WHERE device_id = '1299517'` outputs:
       `Seq Scan on attendance_punches (cost=0.00..12.38 rows=1 width=390)`
       `Filter: ((device_id)::text = '1299517'::text)`
     - `notifications` has index on `(notifiable_type, notifiable_id)` but lacks composite indexes with `created_at` or `read_at`.
   - The latest migration file in `database/migrations/` is `2026_10_04_000001_add_deep_performance_indexes.php`. The next sequenced timestamp prefix is `2026_10_07_000001`.

3. **Task 6.3**:
   - `app/Http/Controllers/DashboardStatsController.php:68-74`:
     ```php
     $syncTaskStats = SyncTask::toBase()
         ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as pending', ['PENDING', 'PROCESSING'])
         ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', ['FAILED'])
         ->first();
     ```
   - Running EXPLAIN on PostgreSQL without `WHERE` outputs:
     `-> Seq Scan on sync_tasks (cost=0.00..12.10 rows=210 width=58)`
   - Running EXPLAIN on PostgreSQL with `WHERE status IN ('PENDING', 'PROCESSING', 'FAILED')` outputs:
     `-> Bitmap Heap Scan on sync_tasks`
     `-> Bitmap Index Scan on sync_tasks_status_index`
     `Index Cond: ((status)::text = ANY ('{PENDING,PROCESSING,FAILED}'::text[]))`

4. **Task 6.4**:
   - `app/Http/Controllers/LeaveController.php:99-120` (`listBalances`) executes `LeaveBalance::with(['employee', 'leaveType'])` and returns unbounded `$query->get()`.
   - `app/Http/Controllers/OrganizationController.php` (`listLocations`, `listDepartments`, `listDesignations`) executes unbounded `$query->get()` without column constraints.
   - Frontend store `resources/js/stores/leaveStore.js:51`:
     `this.leaveBalances = res.data.data || res.data || [];`
   - Frontend store `resources/js/stores/employeeStore.js:85-92` and component `resources/js/components/settings/DepartmentManager.vue:367, 379, 391`:
     `departments.value = res.data.data || res.data || [];`
     `designations.value = res.data.data || res.data || [];`
     `locations.value = res.data.data || res.data || [];`
   - In Axios, returning standard Laravel paginators `return response()->json($paginator)` results in `res.data` containing the paginator JSON, where `res.data.data` is the array of records.

## 2. Logic Chain
1. From Observation 1, PostgreSQL wraps columns in `::date` casts when `whereDate()` is invoked, rendering queries non-SARGable. In `visits`, this forces a `Seq Scan` that bypasses `idx_visits_expected_arrival_status`. Replacing this with `whereBetween([$startOfDay, $endOfDay])` produces SARGable datetime comparisons, immediately allowing PostgreSQL to perform an `Index Scan` using `idx_visits_expected_arrival_status`. The same principle applies to `AttendancePunch` on `punch_time` using `attendance_punches_employee_id_punch_time_index`.
2. From Observation 2, `access_logs` queries sorting by `captured_at DESC` execute a dedicated `Sort` node because only single-column indexes exist. At scale, this sort spills to disk (`filesort`). Adding a composite index on `(device_id, captured_at)` allows index scans to return rows already sorted. On `attendance_punches`, `device_id` has no index, forcing a sequential table scan. On `notifications`, user feeds sorting by `created_at DESC` or filtering `read_at IS NULL` require sort or table filter steps. Adding composite indexes `(notifiable_type, notifiable_id, created_at)` and `(notifiable_type, notifiable_id, read_at)` eliminates these bottlenecks.
3. From Observation 3, `SyncTask::toBase()` aggregates with no `WHERE` clause, scanning all historical completed rows. Adding `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])` filters the query to active/failed outbox rows, allowing PostgreSQL's query planner to leverage `sync_tasks_status_index` via a `Bitmap Index Scan`.
4. From Observation 4, `LeaveController::listBalances()` and `OrganizationController` read endpoints return unbounded arrays without column constraints. Constraining relations with targeted column selections (including primary/foreign keys needed for relationship hydration) and paginating with `paginate($perPage)` bounds memory usage. Because `leaveStore.js`, `employeeStore.js`, and `DepartmentManager.vue` parse `res.data.data || res.data || []`, returning standard Laravel paginators directly provides the records array in `res.data.data`, preserving complete frontend compatibility.

## 3. Caveats
- When constraining columns on eager-loaded `belongsTo` or `hasMany` relationships, foreign key and primary key columns must never be omitted (e.g. `employee_id` and `leave_type_id` on `LeaveBalance`, `parent_id` on `children`).
- Carbon date boundary calculations must use `$instance->copy()->startOfDay()` and `$instance->copy()->endOfDay()` so the original Carbon object is not mutated.

## 4. Conclusion
Tasks 6.1 – 6.4 have been thoroughly investigated, validated with empirical PostgreSQL query plans, and mapped to concrete, low-risk code implementations:
- **Task 6.1**: Update `AttendanceProcessingService.php:59-63` and `VisitorController.php:141-144` with `whereBetween` range queries.
- **Task 6.2**: Create migration `2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php` adding the 4 specified indexes.
- **Task 6.3**: Add `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])` to `DashboardStatsController.php:68-74`.
- **Task 6.4**: Apply `with(...)` column constraints and `paginate($perPage)` to `LeaveController::listBalances` and `OrganizationController` (`listLocations`, `listDepartments`, `listDesignations`).

## 5. Verification Method
1. Run migration and verify rollback:
   ```bash
   php artisan migrate
   php artisan migrate:rollback --step=1
   php artisan migrate
   ```
2. Verify existing test suite:
   ```bash
   php artisan test --filter=PerformanceOptimizationTest
   php artisan test
   ```
3. Add dedicated test methods in `tests/Feature/PerformanceOptimizationTest.php`:
   - `test_attendance_and_visitor_queries_use_sargable_date_ranges`
   - `test_telemetry_and_punch_performance_indexes_exist`
   - `test_dashboard_stats_sync_tasks_scoped_to_active_statuses`
   - `test_wide_read_endpoints_are_paginated_and_column_constrained`
4. Verify frontend build:
   ```bash
   npm run build
   ```
