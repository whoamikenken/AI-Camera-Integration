# Performance & Optimization Engineering Task Matrix (`tasks-performance.md`)

> **Role:** Principal Performance Engineer & Database Architect  
> **Target:** Intelligent AI Camera Hub & Biometric Attendance System (`AI-Camera-Integration`)  
> **Status Legend:** 🔲 Open · 🔄 In Progress · ✅ Completed  

---

## 📊 Performance Audit Summary & Baseline Metrics

The Intelligent AI Camera Hub manages high-throughput bidirectional edge-to-cloud telemetry, real-time facial recognition verification streams, and enterprise-grade biometric workforce attendance. Under production scale (50+ cameras, 10,000+ employees, millions of telemetry logs), the system encounters algorithmic bottlenecks, database connection saturation, table write locks, and client-side memory leakage if unmitigated.

| Category | Primary Root Cause | Scale Impact | Status |
| :--- | :--- | :--- | :--- |
| **Database & Schema** | Non-SARGable `whereDate()`; missing telemetry composite indexes; unpaginated balances | High (table scans, memory exhaustion) | ✅ Completed |
| **Compute & Memory** | Day loop queries in rest days; linear collection scans; sequential bulk updates | High (connection pool saturation) | ✅ Completed |
| **Caching & Async** | Blocking Redis `KEYS` in loops; redundant `Device::firstOrCreate` per MQTT event | High (Redis lockups, daemon stalls) | ✅ Completed |
| **Client-Side Runtime** | Private channel mismatch on alerts; KPI stat pagination desync | Medium (metrics desync, missing events) | ✅ Completed |

---

## Phase 1: Critical Database Architecture & Indexing (P0 - High Scale Impact)

- [x] **Task 1.1: Composite & Foreign Key Index Migration**
  - **Files:** `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php`
  - **Details:**
    - Index missing foreign keys on `device_alerts(device_id)`, `visits(host_employee_id, personnel_id)`, `employees(designation_id, location_id, shift_id, reporting_manager_id)`.
    - Add composite index on `device_alerts(captured_at, severity)` and `device_alerts(status)`.
    - Add indexes on `leave_requests(employee_id, status, start_date, end_date)` and `leave_requests(status)`.
    - Add index on `visits(check_in_time)`, `visits(check_out_time)`, and `visitors(email, phone)`.
    - Add composite index on `access_logs(captured_at, verify_status)` and `access_logs(customize_id, captured_at)`.
  - **Verification:** Unit tests passing in `PerformanceOptimizationTest::test_performance_and_foreign_key_indexes_exist`.

- [x] **Task 1.2: Eliminate Destructive Synchronous Telemetry Lock in Personnel Deletion**
  - **Files:** `app/Observers/PersonnelObserver.php`
  - **Details:**
    - Removed synchronous `AccessLog::where(...)->delete()` statement in `PersonnelObserver::deleting`.
    - Preserved immutable compliance audit records to eliminate long-running write table locks on `access_logs`.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_personnel_deletion_preserves_telemetry_access_logs`.

- [x] **Task 1.3: Replace Concurrency Race in Personnel ID Assignment with Database Sequence**
  - **Files:** `app/Models/Personnel.php`, `database/migrations/2026_10_01_000002_create_personnel_customize_id_seq.php`
  - **Details:**
    - Replaced `static::max('customize_id') + 1` with atomic PostgreSQL sequence `personnel_customize_id_seq`.
    - Eliminates table scanning locks during concurrent enrollment bursts.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_personnel_customize_id_generates_atomically`.

- [x] **Task 1.4: Deep Composite Indexes for Telemetry Queries & SARGable Range Scans** `[ARCHIVED · Jules 16726078788087429660]`
  - **Files:** `database/migrations/2026_10_04_000001_add_deep_performance_indexes.php`
  - **Details:**
    - Add composite index on `visits(expected_arrival, status)` to resolve filesort in `VisitorController::listVisits`.
    - Add composite index on `stranger_snaps(device_id, captured_at DESC)` to accelerate camera-specific stranger feeds.
    - Add composite index on `sync_tasks(device_id, updated_at DESC)` for high-frequency audit and sync queue queries.
    - Add composite index on `attendance_records(date, status)` to accelerate daily attendance status filters.
  - **Verification:** Unit tests passing in `PerformanceOptimizationTest::test_deep_composite_indexes_exist`.

---

## Phase 2: Application Runtime & Query Aggregation Overhaul (P0/P1 - High Scale Impact)

- [x] **Task 2.1: Refactor Monthly Attendance & Payroll Calculations to SQL Aggregates**
  - **Files:** `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/PayrollExportController.php`
  - **Details:**
    - Replaced quadratic in-memory PHP collection filtering with single SQL queries using `GROUP BY employee_id` and conditional aggregation (`COUNT(CASE WHEN status IN (...) THEN 1 END)`).
    - Implemented streamed cursor exports for CSV output.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_monthly_attendance_report_aggregates_via_sql`.

- [x] **Task 2.2: Strip Massive Base64 Image Columns from Entity Serialization**
  - **Files:** `app/Models/Personnel.php`, `app/Http/Controllers/PersonnelController.php`, `app/Http/Controllers/EmployeeController.php`
  - **Details:**
    - Added `'photo_base64'` to `$hidden` array on `Personnel` model.
    - Explicitly selected column subsets in `PersonnelController::index` and `EmployeeController::index`.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_personnel_and_employee_indexes_exclude_photo_base64`.

- [x] **Task 2.3: Offload Camera Hardware Personnel Import to Asynchronous Queue Job**
  - **Files:** `app/Http/Controllers/DeviceController.php`, `app/Jobs/ImportCameraPersonnelJob.php`, `app/Services/CameraService.php`
  - **Details:**
    - Moved `CameraService::importPersonnelFromCamera` to background queue worker on `camera-sync`.
    - Returns `202 Accepted` immediately with UUID task token.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_import_camera_personnel_returns_202_accepted_and_dispatches_job`.

- [x] **Task 2.4: Eliminate N+1 Query Cascade in Daily Attendance Finalization Job** `[ARCHIVED · Jules 11503891485603121106]`
  - **Files:** `app/Jobs/DailyAttendanceFinalizerJob.php`
  - **Details:**
    - Replace `Employee::where('employment_status', 'active')->get()` and individual `AttendanceRecord::where('employee_id', $emp->id)->where('date', ...)->first()` queries in loop.
    - Pre-fetch existing attendance record IDs for the date into a keyed hash (`AttendanceRecord::where('date', $dateStr)->pluck('id', 'employee_id')`).
    - Process active employees using `chunkById(250)` to bound memory consumption to $O(1)$.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_daily_attendance_finalizer_chunking`.

- [x] **Task 2.5: Batch Multi-Record SQL Operations in Bulk Shift Assignment** `[ARCHIVED · Jules 11503891485603121106]`
  - **Files:** `app/Http/Controllers/ShiftController.php`
  - **Details:**
    - Replace 1,500 individual queries in `performShiftAssignment` loop with 3 set-based bulk queries:
      1. Bulk update previous assignments: `EmployeeShiftAssignment::whereIn('employee_id', $ids)->where('effective_from', '<=', $from)->whereNull('effective_to')->update(['effective_to' => $prevEnd])`.
      2. Bulk insert new assignments: `EmployeeShiftAssignment::insert($rows)`.
      3. Bulk update active shift: `Employee::whereIn('id', $ids)->update(['shift_id' => $shift->id])`.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_bulk_shift_assignment_batching`.

- [x] **Task 2.6: Paginate Workforce Daily Attendance Roster and Stream JSON/CSV Exports** `[JULES: AWAITING FEEDBACK · 11358639326197026043]`
  - **Files:** `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/EmployeeController.php`, `app/Http/Controllers/PayrollExportController.php`
  - **Details:**
    - Paginate `AttendanceController::daily()` with `paginate($perPage)` instead of `->get()`.
    - Compute daily status metrics via a single SQL conditional aggregation query rather than counting hydrated collections.
    - Replace `Employee::get()` and `PayrollExportController::get()` in JSON exports with chunking / cursor streaming.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_attendance_daily_pagination_and_streaming_exports`.

- [x] **Task 2.7: Column-Specific Eager Loading for Personnel Relationships** `[ARCHIVED · Jules 16726078788087429660]`
  - **Files:** `app/Http/Controllers/AccessLogController.php`, `app/Http/Controllers/SyncTaskController.php`, `app/Http/Controllers/DeviceController.php`
  - **Details:**
    - Specify column constraints on eager loaded personnel: `with(['personnel:id,customize_id,name,person_type,photo_path'])` instead of bare `with('personnel')`.
    - Prevent PostgreSQL from fetching massive `photo_base64` text columns into PHP memory.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_column_specific_eager_loading_excludes_photo_base64`.

- [x] **Task 2.8: Eliminate Duplicate Query in Monthly Attendance Report** `[ARCHIVED · Jules 11503891485603121106]`
  - **Files:** `app/Http/Controllers/ReportController.php:57-82`
  - **Details:**
    - Remove duplicate `Employee::with('department')->where('employment_status', 'active')->get()` call executed twice back-to-back.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_monthly_attendance_report_aggregates_via_sql`.

---

## Phase 3: Telemetry Streaming & MQTT Ingestion Pipeline (P1 - High/Medium Scale Impact)

- [x] **Task 3.1: Enforce Complete Heartbeat Write Throttling in MQTT Telemetry Daemon** `[JULES: AWAITING FEEDBACK · 8924291706478942295]`
  - **Files:** `app/Console/Commands/MqttListenCommand.php:248,335,407`
  - **Details:**
    - Remove unconditional `$device->update(['last_heartbeat_at' => now()])` in `handleVerifyPush`, `handleStrangerSnapPush`, and `handleDeviceAlert`.
    - Rely strictly on the 60-second Redis heartbeat throttle key (`device_hb_throttle:{$deviceId}`) to eliminate 100+ writes/sec on `devices` table during peak traffic.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_mqtt_listener_heartbeat_throttling_skips_database_write`.

- [x] **Task 3.2: Asynchronous Event Broadcasting Across All Real-Time Events** `[ARCHIVED · Jules 8924291706478942295 + 8102084558259711491]`
  - **Files:** `app/Events/DeviceAlertReceived.php`, `app/Events/DeviceAlertUpdated.php`, `app/Events/StrangerSnapReceived.php`, `app/Events/DeviceStatusUpdated.php`, `app/Events/AttendancePunchReceived.php`, `app/Events/NotificationCreated.php`
  - **Details:**
    - Change events from `ShouldBroadcastNow` (synchronous HTTP to Reverb) to `ShouldBroadcast` backed by Redis `broadcasts` queue.
    - Remove synchronous `COUNT(*)` queries on `access_logs` and `stranger_snaps` inside `DeviceStatusUpdated::broadcastWith()`.
    - Ensure single-threaded MQTT ingestion loop is never blocked by socket latency or Reverb restarts.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_all_realtime_events_implement_should_broadcast`.

- [x] **Task 3.3: Connection Pooling for MQTT Downlink Request-Reply Commands** `[JULES: AWAITING FEEDBACK · 8924291706478942295]`
  - **Files:** `app/Services/CameraMqttService.php:78-121`
  - **Details:**
    - Eliminate tearing down and establishing fresh TCP/TLS connections for every `publishCommandAndWait()`.
    - Share persistent client connection across request-reply commands or pool active worker connections via `getSharedClient()`.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_sync_personnel_dispatches_parallel_sync_device_personnel_jobs`.

---

## Phase 4: Caching Layer & Invalidation Engine (P2 - Medium Scale Impact)

- [x] **Task 4.1: Redis Caching for High-Frequency Dashboard Telemetry KPI Metrics**
  - **Files:** `app/Http/Controllers/DashboardStatsController.php`
  - **Details:**
    - Wrapped aggregated stats in Redis cache with 5-second TTL.
    - Consolidated queries into single conditional aggregation passes.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_dashboard_stats_endpoint_uses_caching_and_consolidates_alerts`.

- [x] **Task 4.2: Redis Caching for Device Alert Statistics** `[ARCHIVED · Jules 5031391123107886080]`
  - **Files:** `app/Http/Controllers/DeviceAlertController.php:50-68`
  - **Details:**
    - Consolidate 6 separate queries into a single SQL conditional aggregation query.
    - Cache response in Redis with 5-second TTL (`device_alert_stats`).
  - **Verification:** Verified in `PerformanceOptimizationTest::test_device_alert_stats_caching_and_consolidation`.

- [x] **Task 4.3: Cache Invalidation Engine on Holiday & Shift Mutations** `[ARCHIVED · Jules 5031391123107886080]`
  - **Files:** `app/Http/Controllers/HolidayController.php`, `app/Http/Controllers/ShiftController.php`, `app/Models/Employee.php`
  - **Details:**
    - Add `Cache::forget("holidays_{$year}")` in `HolidayController::store`, `update`, and `destroy`.
    - Invalidate employee shift cache `Cache::forget("emp_shift:{$employeeId}:*")` upon shift assignment.
    - In `Employee::isHoliday()`, use `AttendanceProcessingService::isHoliday()` to leverage the yearly Redis cache instead of running direct SQL queries.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_holiday_mutations_invalidate_cache` and `PerformanceOptimizationTest::test_attendance_processing_service_caches_holidays_and_shifts`.

- [x] **Task 4.4: Device Fleet Status & Count Caching** `[ARCHIVED · Jules 5031391123107886080]`
  - **Files:** `app/Http/Controllers/DeviceController.php:20-22`, `app/Models/Device.php`
  - **Details:**
    - Eliminate `Device::withCount(['accessLogs', 'strangerSnaps'])` on every index query.
    - Cache log counts in Redis or maintain summary counters on the `devices` table to eliminate full table scans.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_device_fleet_counts_caching`.

---

## Phase 5: Client-Side Runtime & Asset Optimization (P2 - Medium/Low Scale Impact)

- [x] **Task 5.1: Dynamic Code-Splitting and Lazy-Loading for Heavy Views**
  - **Files:** `resources/js/App.vue`
  - **Details:**
    - Converted view components to `defineAsyncComponent` in `App.vue`.

- [x] **Task 5.2: Vite Bundle Chunk Splitting (`manualChunks`)** `[ARCHIVED · Jules 356073742579482516]`
  - **Files:** `vite.config.js`
  - **Details:**
    - Configure `rollupOptions.output.manualChunks` in `vite.config.js` to split vendor dependencies:
      - `vendor-vue`: `vue`, `pinia`
      - `vendor-realtime`: `laravel-echo`, `pusher-js`
      - `vendor-charts-player`: WebGL renderer, video decoder scripts
    - Reduces initial bundle size and accelerates First Contentful Paint (FCP).
  - **Verification:** Verified with production build `npm run build` outputting dedicated vendor chunks.

- [x] **Task 5.3: Eliminate Redundant 4-Second Polling Over Active WebSockets** `[ARCHIVED · Jules 356073742579482516]`
  - **Files:** `resources/js/views/LiveTelemetry.vue:431-436`
  - **Details:**
    - Pause the 4-second HTTP polling timer when `store.wsConnected` is `true`.
    - Only activate polling fallback if the WebSocket connection drops or disconnects.
  - **Verification:** Verified in `LiveTelemetry.vue` component logic and `npm run build`.

- [x] **Task 5.4: Deduplicate WebSocket Echo Listeners & Teardown Connection Handlers** `[ARCHIVED · Jules 356073742579482516]`
  - **Files:** `resources/js/App.vue:819-888`
  - **Details:**
    - Remove duplicate `.listen(".Event")` and `.listen("Event")` dual bindings.
    - Store references to Pusher connection callbacks and call `unbind()` during `cleanupTelemetry()` to prevent memory leaks on sign-out / sign-in cycles.
  - **Verification:** Verified in `App.vue` lifecycle hooks and `npm run build`.

- [x] **Task 5.5: Fix AudioContext Leak on Telemetry Security Alerts** `[ARCHIVED · Jules 356073742579482516]`
  - **Files:** `resources/js/stores/cameraStore.js:422-435`
  - **Details:**
    - Create a lazily initialized singleton `AudioContext` instead of instantiating `new AudioContext()` on every alert.
    - Avoid browser audio channel exhaustion and hardware graph resource leaks.
  - **Verification:** Verified in `cameraStore.js` and `npm run build`.

- [x] **Task 5.6: WebGL Texture and Shader Resource Teardown** `[ARCHIVED · Jules 356073742579482516]`
  - **Files:** `resources/js/utils/cameraHqPlayer.js:149-160`
  - **Details:**
    - Explicitly call `gl.deleteTexture()`, `gl.deleteBuffer()`, `gl.deleteProgram()`, and `gl.deleteShader()` prior to `loseContext()` in `WebGLYUVRenderer::destroy()`.
  - **Verification:** Verified in `cameraHqPlayer.js` and `npm run build`.

---

## Phase 6: Deep Performance & Architecture Audit Backlog (New Audit Scope)

### 1. Database & Schema Optimization (P0/P1)

- [x] **Task 6.1: Eliminate Non-SARGable `whereDate()` Expressions Across Attendance & Visitor Engines**
  - **Files:** `app/Services/AttendanceProcessingService.php:59-63`, `app/Http/Controllers/VisitorController.php:142-144`
  - **Details:**
    - Replace `AttendancePunch::where('employee_id', $empId)->whereDate('punch_time', $date)->first()` with SARGable range query `whereBetween('punch_time', [$startOfDay, $endOfDay])` to utilize composite index `['employee_id', 'punch_time']`.
    - Replace `Visit::whereDate('expected_arrival', $date)` with `whereBetween('expected_arrival', [$startOfDay, $endOfDay])` to leverage `idx_visits_expected_arrival_status`.
  - **Scale Impact:** High (prevents full-table scans / unindexed expression scans in high-frequency queries).

- [x] **Task 6.2: Add Missing Composite & Foreign Key Indexes for Telemetry & Punches**
  - **Files:** `database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php`
  - **Details:**
    - Add composite index on `access_logs(device_id, captured_at DESC)` to eliminate filesorts on camera telemetry feeds (`DeviceController::audit` & telemetry endpoints).
    - Add foreign key index on `attendance_punches(device_id)` to accelerate device-specific attendance queries.
    - Add composite index on `notifications(notifiable_type, notifiable_id, created_at DESC)` and `notifications(notifiable_type, notifiable_id, read_at)` to accelerate user notification lookups.
  - **Scale Impact:** High (removes memory filesorts and table scans as telemetry scales to millions of records).

- [x] **Task 6.3: Optimize Unbounded Table Scan on `sync_tasks` in Dashboard Stats**
  - **Files:** `app/Http/Controllers/DashboardStatsController.php:68-74`
  - **Details:**
    - Scope `SyncTask::toBase()->selectRaw(...)` with `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])` to allow PostgreSQL to use the `sync_tasks_status_index` instead of sequentially scanning the entire historical table.
  - **Scale Impact:** Medium (avoids linear scan latency as sync tasks accumulate).

- [x] **Task 6.4: Paginate and Column-Constrain Wide Read Endpoints in Leave & Organization Modules**
  - **Files:** `app/Http/Controllers/LeaveController.php:99-120`, `app/Http/Controllers/OrganizationController.php:123-137, 222-243, 290-309`
  - **Details:**
    - Paginate `LeaveController::listBalances()` with `paginate($perPage)` and constrain relationships: `with(['employee:id,first_name,last_name,employee_code', 'leaveType:id,name,code'])`.
    - Add pagination and column constraints to `listLocations()`, `listDepartments()`, and `listDesignations()`.
  - **Scale Impact:** High (prevents worker OOM crashes when organizations scale to thousands of employees).

---

### 2. Application Runtime & Compute (P0/P1)

- [x] **Task 6.5: Eliminate $O(N)$ Database Queries in `Employee::isRestDay` Inside Summary Loop**
  - **Files:** `app/Http/Controllers/EmployeeController.php:263-269`, `app/Models/Employee.php:205-213`
  - **Details:**
    - `EmployeeController::attendanceSummary` loops through 30+ days and executes a database query on `shift_assignments` for every day.
    - Pre-fetch all overlapping `EmployeeShiftAssignment` records for the requested range once into memory and evaluate rest days against the collection.
  - **Scale Impact:** High (reduces 30+ SQL queries per attendance summary request to 1).

- [x] **Task 6.6: Eliminate Linear $O(N \times M)$ Collection Scan and Large Outbox Pull in `DeviceController::audit()`**
  - **Files:** `app/Http/Controllers/DeviceController.php:509-514, 562-564`
  - **Details:**
    - Key `$localPersonnel` by `customize_id` (`$localPersonnel->keyBy('customize_id')`) to convert `$localPersonnel->contains('customize_id', $cId)` from an $O(N)$ linear scan into an $O(1)$ hash map lookup.
    - Fetch only the most recent sync task per personnel for the device via SQL (`DISTINCT ON (personnel_id)` on PostgreSQL or subquery) instead of loading all historical device sync tasks and grouping in PHP collection memory.
  - **Scale Impact:** High (eliminates memory spikes and $O(N^2)$ CPU overhead on device audits).

- [x] **Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`**
  - **Files:** `app/Http/Controllers/DeviceAlertController.php:117-122`
  - **Details:**
    - Replace the `foreach ($alerts as $alert) { $alert->update(...); }` loop with a single bulk query: `DeviceAlert::whereIn('id', $validated['ids'])->update($updateData)`.
    - Dispatch a single batched event or queue individual broadcasts asynchronously.
  - **Scale Impact:** Medium (replaces hundreds of sequential database writes with 1 atomic SQL query).

---

### 3. Caching & Asynchronous Processing (P0/P1)

- [x] **Task 6.8: Eliminate Blocking Redis `KEYS` Command in Bulk Shift Assignment**
  - **Files:** `app/Http/Controllers/ShiftController.php:298-311`
  - **Details:**
    - Remove `$redis->keys($prefix . $cachePattern)` inside the `foreach ($employeeIds)` loop. `KEYS` is a blocking $O(N)$ Redis operation that scans the entire Redis database, locking the event loop and freezing queues and WebSocket traffic under load.
    - Implement versioned cache keys (`emp_shift_v:{$employeeId}` counter where eviction is an $O(1)$ `INCR`), or track active keys in a Redis set per employee.
  - **Scale Impact:** High (prevents complete Redis event loop freezes during bulk shift operations).

- [x] **Task 6.9: Cache Pre-Enrolled Device Existence in High-Frequency MQTT Telemetry Stream**
  - **Files:** `app/Console/Commands/MqttListenCommand.php:243-246, 333-336, 410-413`
  - **Details:**
    - `MqttListenCommand` executes `Device::firstOrCreate(['device_id' => $deviceId], ...)` on every incoming `VerifyPush`, `StrSnapPush`, and `DeviceAlert`.
    - Cache known active `device_id` values in memory or Redis for 10 minutes (`device_registered:{$deviceId}`) to eliminate redundant database SELECT queries during 100+ events/sec telemetry bursts.
  - **Scale Impact:** High (removes 100+ DB queries/sec from the database connection pool).

- [x] **Task 6.10: Cache Biometric `customize_id` to Employee Mapping in Punch Ingestion**
  - **Files:** `app/Jobs/ProcessAttendancePunchJob.php:33-46`
  - **Details:**
    - Pre-cache the bidirectional identity bridge (`emp_custom_id:{$customizeId} => $employeeId`) in Redis with 1-hour TTL, evicting on `EmployeeObserver` and `PersonnelObserver` changes.
    - Prevents 2 sequential SQL lookups on every single verification punch job.
  - **Scale Impact:** Medium (speeds up attendance punch processing throughput).

- [x] **Task 6.11: Cache Invalidation Engine for Device Alerts & Public Settings**
  - **Files:** `app/Http/Controllers/DeviceAlertController.php:96, 120`, `app/Http/Controllers/SettingController.php:30-45`, `app/Services/SettingService.php:60-89`
  - **Details:**
    - Invalidate `Cache::forget('device_alert_stats')` and `Cache::forget('dashboard_telemetry_stats')` upon alert status changes (`updateStatus` and `bulkUpdateStatus`).
    - Cache public branding settings (`SettingController::publicSettings`) in Redis with 1-hour TTL (`settings.public`) and invalidate upon setting updates.
  - **Scale Impact:** Medium (eliminates stale KPI counts and uncached DB queries on every page load).

---

### 4. Frontend Runtime & Real-Time Sync (P2)

- [x] **Task 6.12: Fix Echo Channel Type Mismatch in `DeviceAlertsCenter.vue`**
  - **Files:** `resources/js/views/DeviceAlertsCenter.vue:732-736`
  - **Details:**
    - `DeviceAlertsCenter.vue` calls `echo.channel('device-alerts')` (public channel), but `DeviceAlertReceived` broadcasts on `PrivateChannel('device-alerts')`.
    - Update to `echo.private('device-alerts')` or consume directly from `cameraStore.deviceAlerts` to avoid silent WebSocket dropouts.
  - **Scale Impact:** Medium (ensures real-time alerts update without manual page reloads).

- [x] **Task 6.13: Correct Metric Binding in `attendanceStore` from Server Summary**
  - **Files:** `resources/js/stores/attendanceStore.js:80-82, 91-100`
  - **Details:**
    - `attendanceStore` currently looks for `data.stats` (which does not exist; backend returns `data.summary`), causing it to fall back to `computeLocalStats()` on the current page subset (only 50 items).
    - Map `data.summary` directly into `this.stats` to accurately reflect workforce attendance rates regardless of pagination.
  - **Scale Impact:** Low/Medium (fixes metric calculation on paginated workforce views).
