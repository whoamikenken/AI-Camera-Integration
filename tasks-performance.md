# Performance & Optimization Engineering Task Matrix (`tasks-performance.md`)

> **Role:** Principal Performance Engineer & Database Architect  
> **Target:** Intelligent AI Camera Hub & Biometric Attendance System (`AI-Camera-Integration`)  
> **Status Legend:** 🔲 Open · 🔄 In Progress · ✅ Completed  

---

## 📊 Performance Audit Summary & Baseline Metrics

The Intelligent AI Camera Hub manages high-throughput bidirectional edge-to-cloud telemetry, real-time facial recognition verification streams, and enterprise-grade biometric workforce attendance. Under production scale (50+ cameras, 10,000+ employees, millions of telemetry logs), the system encounters algorithmic bottlenecks, database connection saturation, table write locks, and client-side memory leakage if unmitigated.

| Category | Primary Root Cause | Scale Impact | Status |
| :--- | :--- | :--- | :--- |
| **Database & Schema** | N+1 queries in finalizer & shift assignment; unindexed sorting; full-table counts | High (table locks, pool exhaustion) | 🔄 In Progress |
| **Compute & Memory** | Unbuffered CSV/JSON exports; duplicate queries; in-memory roster maps | High (OOM errors under load) | 🔄 In Progress |
| **Caching & Async** | `ShouldBroadcastNow` blocking daemons; heartbeat write bypass; missing cache eviction | High (daemon stalls, WAL saturation) | 🔄 In Progress |
| **Client-Side Runtime** | Redundant 4s polling over active WebSockets; AudioContext leak; bundle bloat | Medium (client CPU/memory leak) | 🔄 In Progress |

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

- [ ] **Task 1.4: Deep Composite Indexes for Telemetry Queries & SARGable Range Scans**
  - **Files:** `database/migrations/2026_10_04_000001_add_deep_performance_indexes.php`
  - **Details:**
    - Add composite index on `visits(expected_arrival, status)` to resolve filesort in `VisitorController::listVisits`.
    - Add composite index on `stranger_snaps(device_id, captured_at DESC)` to accelerate camera-specific stranger feeds.
    - Add composite index on `sync_tasks(device_id, updated_at DESC)` for high-frequency audit and sync queue queries.
    - Add composite index on `attendance_records(date, status)` to accelerate daily attendance status filters.

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

- [ ] **Task 2.4: Eliminate N+1 Query Cascade in Daily Attendance Finalization Job**
  - **Files:** `app/Jobs/DailyAttendanceFinalizerJob.php`
  - **Details:**
    - Replace `Employee::where('employment_status', 'active')->get()` and individual `AttendanceRecord::where('employee_id', $emp->id)->where('date', ...)->first()` queries in loop.
    - Pre-fetch existing attendance record IDs for the date into a keyed hash (`AttendanceRecord::where('date', $dateStr)->pluck('id', 'employee_id')`).
    - Process active employees using `chunkById(250)` to bound memory consumption to $O(1)$.

- [ ] **Task 2.5: Batch Multi-Record SQL Operations in Bulk Shift Assignment**
  - **Files:** `app/Http/Controllers/ShiftController.php`
  - **Details:**
    - Replace 1,500 individual queries in `performShiftAssignment` loop with 3 set-based bulk queries:
      1. Bulk update previous assignments: `EmployeeShiftAssignment::whereIn('employee_id', $ids)->where('effective_from', '<=', $from)->whereNull('effective_to')->update(['effective_to' => $prevEnd])`.
      2. Bulk insert new assignments: `EmployeeShiftAssignment::insert($rows)`.
      3. Bulk update active shift: `Employee::whereIn('id', $ids)->update(['shift_id' => $shift->id])`.

- [ ] **Task 2.6: Paginate Workforce Daily Attendance Roster and Stream JSON/CSV Exports**
  - **Files:** `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/EmployeeController.php`, `app/Http/Controllers/PayrollExportController.php`
  - **Details:**
    - Paginate `AttendanceController::daily()` with `paginate($perPage)` instead of `->get()`.
    - Compute daily status metrics via a single SQL conditional aggregation query rather than counting hydrated collections.
    - Replace `Employee::get()` and `PayrollExportController::get()` in JSON exports with chunking / cursor streaming.

- [ ] **Task 2.7: Column-Specific Eager Loading for Personnel Relationships**
  - **Files:** `app/Http/Controllers/AccessLogController.php`, `app/Http/Controllers/SyncTaskController.php`, `app/Http/Controllers/DeviceController.php`
  - **Details:**
    - Specify column constraints on eager loaded personnel: `with(['personnel:id,customize_id,name,person_type,photo_path'])` instead of bare `with('personnel')`.
    - Prevent PostgreSQL from fetching massive `photo_base64` text columns into PHP memory.

- [ ] **Task 2.8: Eliminate Duplicate Query in Monthly Attendance Report**
  - **Files:** `app/Http/Controllers/ReportController.php:57-82`
  - **Details:**
    - Remove duplicate `Employee::with('department')->where('employment_status', 'active')->get()` call executed twice back-to-back.

---

## Phase 3: Telemetry Streaming & MQTT Ingestion Pipeline (P1 - High/Medium Scale Impact)

- [ ] **Task 3.1: Enforce Complete Heartbeat Write Throttling in MQTT Telemetry Daemon**
  - **Files:** `app/Console/Commands/MqttListenCommand.php:248,335,407`
  - **Details:**
    - Remove unconditional `$device->update(['last_heartbeat_at' => now()])` in `handleVerifyPush`, `handleStrangerSnapPush`, and `handleDeviceAlert`.
    - Rely strictly on the 60-second Redis heartbeat throttle key (`device_hb_throttle:{$deviceId}`) to eliminate 100+ writes/sec on `devices` table during peak traffic.

- [ ] **Task 3.2: Asynchronous Event Broadcasting Across All Real-Time Events**
  - **Files:** `app/Events/DeviceAlertReceived.php`, `app/Events/DeviceAlertUpdated.php`, `app/Events/StrangerSnapReceived.php`, `app/Events/DeviceStatusUpdated.php`, `app/Events/AttendancePunchReceived.php`, `app/Events/NotificationCreated.php`
  - **Details:**
    - Change events from `ShouldBroadcastNow` (synchronous HTTP to Reverb) to `ShouldBroadcast` backed by Redis `broadcasts` queue.
    - Remove synchronous `COUNT(*)` queries on `access_logs` and `stranger_snaps` inside `DeviceStatusUpdated::broadcastWith()`.
    - Ensure single-threaded MQTT ingestion loop is never blocked by socket latency or Reverb restarts.

- [ ] **Task 3.3: Connection Pooling for MQTT Downlink Request-Reply Commands**
  - **Files:** `app/Services/CameraMqttService.php:78-121`
  - **Details:**
    - Eliminate tearing down and establishing fresh TCP/TLS connections for every `publishCommandAndWait()`.
    - Share persistent client connection across request-reply commands or pool active worker connections.

---

## Phase 4: Caching Layer & Invalidation Engine (P2 - Medium Scale Impact)

- [x] **Task 4.1: Redis Caching for High-Frequency Dashboard Telemetry KPI Metrics**
  - **Files:** `app/Http/Controllers/DashboardStatsController.php`
  - **Details:**
    - Wrapped aggregated stats in Redis cache with 5-second TTL.
    - Consolidated queries into single conditional aggregation passes.
  - **Verification:** Verified in `PerformanceOptimizationTest::test_dashboard_stats_endpoint_uses_caching_and_consolidates_alerts`.

- [ ] **Task 4.2: Redis Caching for Device Alert Statistics**
  - **Files:** `app/Http/Controllers/DeviceAlertController.php:50-68`
  - **Details:**
    - Consolidate 6 separate queries into a single SQL conditional aggregation query.
    - Cache response in Redis with 5-second TTL (`device_alert_stats`).

- [ ] **Task 4.3: Cache Invalidation Engine on Holiday & Shift Mutations**
  - **Files:** `app/Http/Controllers/HolidayController.php`, `app/Http/Controllers/ShiftController.php`, `app/Models/Employee.php`
  - **Details:**
    - Add `Cache::forget("holidays_{$year}")` in `HolidayController::store`, `update`, and `destroy`.
    - Invalidate employee shift cache `Cache::forget("emp_shift:{$employeeId}:*")` upon shift assignment.
    - In `Employee::isHoliday()`, use `AttendanceProcessingService::isHoliday()` to leverage the yearly Redis cache instead of running direct SQL queries.

- [ ] **Task 4.4: Device Fleet Status & Count Caching**
  - **Files:** `app/Http/Controllers/DeviceController.php:20-22`, `app/Models/Device.php`
  - **Details:**
    - Eliminate `Device::withCount(['accessLogs', 'strangerSnaps'])` on every index query.
    - Cache log counts in Redis or maintain summary counters on the `devices` table to eliminate full table scans.

---

## Phase 5: Client-Side Runtime & Asset Optimization (P2 - Medium/Low Scale Impact)

- [x] **Task 5.1: Dynamic Code-Splitting and Lazy-Loading for Heavy Views**
  - **Files:** `resources/js/App.vue`
  - **Details:**
    - Converted view components to `defineAsyncComponent` in `App.vue`.

- [ ] **Task 5.2: Vite Bundle Chunk Splitting (`manualChunks`)**
  - **Files:** `vite.config.js`
  - **Details:**
    - Configure `rollupOptions.output.manualChunks` in `vite.config.js` to split vendor dependencies:
      - `vendor-vue`: `vue`, `pinia`
      - `vendor-realtime`: `laravel-echo`, `pusher-js`
      - `vendor-charts-player`: WebGL renderer, video decoder scripts
    - Reduces initial bundle size and accelerates First Contentful Paint (FCP).

- [ ] **Task 5.3: Eliminate Redundant 4-Second Polling Over Active WebSockets**
  - **Files:** `resources/js/views/LiveTelemetry.vue:431-436`
  - **Details:**
    - Pause the 4-second HTTP polling timer when `store.wsConnected` is `true`.
    - Only activate polling fallback if the WebSocket connection drops or disconnects.

- [ ] **Task 5.4: Deduplicate WebSocket Echo Listeners & Teardown Connection Handlers**
  - **Files:** `resources/js/App.vue:819-888`
  - **Details:**
    - Remove duplicate `.listen(".Event")` and `.listen("Event")` dual bindings.
    - Store references to Pusher connection callbacks and call `unbind()` during `cleanupTelemetry()` to prevent memory leaks on sign-out / sign-in cycles.

- [ ] **Task 5.5: Fix AudioContext Leak on Telemetry Security Alerts**
  - **Files:** `resources/js/stores/cameraStore.js:422-435`
  - **Details:**
    - Create a lazily initialized singleton `AudioContext` instead of instantiating `new AudioContext()` on every alert.
    - Avoid browser audio channel exhaustion and hardware graph resource leaks.

- [ ] **Task 5.6: WebGL Texture and Shader Resource Teardown**
  - **Files:** `resources/js/utils/cameraHqPlayer.js:149-160`
  - **Details:**
    - Explicitly call `gl.deleteTexture()`, `gl.deleteBuffer()`, `gl.deleteProgram()`, and `gl.deleteShader()` prior to `loseContext()` in `WebGLYUVRenderer::destroy()`.
