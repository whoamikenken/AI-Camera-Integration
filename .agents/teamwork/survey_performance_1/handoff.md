# Performance Architecture Survey & Engineering Blueprint

**Surveyor:** `survey_performance_1` (Performance Architecture Surveyor)  
**Date:** 2026-10-01  
**Project:** Intelligent AI Camera Hub & Biometric Attendance Management System  
**Scope:** `tasks-performance.md` (Phases 1 through 5, Tasks 1.1 through 5.2)

---

## 1. Observation

A comprehensive read-only survey of the repository was conducted across database migrations, Eloquent models, controllers, commands, services, event classes, jobs, and Vue/Vite frontend assets. The verbatim observations, file paths, line numbers, and existing implementations are cataloged below:

### Phase 1: Database & Data Integrity

#### Task 1.1: Missing Foreign Key & Composite Indexes
1. **`database/migrations/2026_09_30_000022_create_device_alerts_table.php` (Lines 13-14, 24, 27):**
   ```php
   13: $table->string('device_id', 64);
   14: $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
   ...
   24: $table->string('status', 20)->default('NEW')->index();
   27: $table->timestampTz('captured_at')->index();
   ```
   - *Observation:* While a foreign key constraint exists on `device_alerts(device_id)`, PostgreSQL does not automatically index foreign key columns. Queries filtering alerts by `device_id` trigger sequential table scans.
   - *Observation:* High-frequency dashboard filtering queries filter by `captured_at` and `severity` (e.g., `WHERE captured_at >= ? AND severity = 'CRITICAL'`), but only a single-column index on `captured_at` exists. Composite index on `(captured_at, severity)` is missing.

2. **`database/migrations/2026_09_30_000020_create_visitors_and_visits_tables.php` (Lines 16-17, 30-31, 35-36, 42):**
   ```php
   16: $table->string('email', 128)->nullable();
   17: $table->string('phone', 32)->nullable();
   ...
   30: $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
   31: $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
   ...
   35: $table->timestamp('check_in_time')->nullable();
   36: $table->timestamp('check_out_time')->nullable();
   ...
   42: $table->index(['status', 'visitor_id']);
   ```
   - *Observation:* Foreign keys `visits(host_employee_id)` and `visits(personnel_id)` have no indexes.
   - *Observation:* Temporal columns `visits(check_in_time)` and `visits(check_out_time)` used in daily reports and checkout queries lack indexes.
   - *Observation:* Lookup fields `visitors(email)` and `visitors(phone)` used for pre-registration and check-in lookup lack indexes.

3. **`database/migrations/2026_09_30_000014_create_employees_table.php` (Lines 17-20, 37-39):**
   ```php
   17: $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
   18: $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
   19: $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
   20: $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
   ...
   37: $table->index('employment_status');
   38: $table->index('department_id');
   39: $table->index('organization_id');
   ```
   - *Observation:* Foreign keys `designation_id`, `location_id`, `reporting_manager_id`, and `shift_id` lack database indexes.

4. **`database/migrations/2026_09_30_000019_create_leave_and_regularization_tables.php` (Lines 41-47):**
   ```php
   41: $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
   42: $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
   43: $table->date('start_date');
   44: $table->date('end_date');
   47: $table->string('status', 32)->default('pending');
   ```
   - *Observation:* No indexes exist on `leave_requests`. Leave balance checks and roster conflict queries filter on `(employee_id, status, start_date, end_date)` and `status`.

5. **`database/migrations/2026_08_22_000003_create_access_logs_table.php` (Lines 13-26):**
   ```php
   13: $table->string('device_id', 64)->index();
   15: $table->unsignedBigInteger('customize_id')->nullable()->index();
   18: $table->integer('verify_status')->index();
   26: $table->timestampTz('captured_at')->index();
   ```
   - *Observation:* Only single-column indexes exist. Dashboard aggregation queries execute `WHERE captured_at >= ? AND verify_status = ?`, and attendance processing queries execute `WHERE customize_id = ? AND captured_at BETWEEN ? AND ?`. Lack of composite indexes `(captured_at, verify_status)` and `(customize_id, captured_at)` forces PostgreSQL into bitmap index merges or full sequential scans on high-volume audit tables.

#### Task 1.2: Destructive Synchronous Telemetry Lock in Personnel Deletion
1. **`app/Observers/PersonnelObserver.php` (Lines 35-50):**
   ```php
   35: public function deleting(Personnel $personnel): void
   36: {
   37:     // Delete telemetry access logs associated with this personnel record
   38:     if ($personnel->customize_id || $personnel->person_uuid) {
   39:         \App\Models\AccessLog::query()
   40:             ->where(function ($q) use ($personnel) {
   41:                 if ($personnel->customize_id) {
   42:                     $q->where('customize_id', $personnel->customize_id);
   43:                 }
   44:                 if ($personnel->person_uuid) {
   45:                     $q->orWhere('person_uuid', $personnel->person_uuid);
   46:                 }
   47:             })
   48:             ->delete();
   49:     }
   50:     ...
   ```
2. **`app/Observers/EmployeeObserver.php` (Lines 31-43):**
   ```php
   31: // 2. Delete telemetry access logs
   32: if ($customizeId || $personUuid) {
   33:     AccessLog::query()
   34:         ->where(function ($q) use ($customizeId, $personUuid) {
   35:             if ($customizeId) {
   36:                 $q->where('customize_id', $customizeId);
   37:             }
   38:             if ($personUuid) {
   39:                 $q->orWhere('person_uuid', $personUuid);
   40:             }
   41:         })
   42:         ->delete();
   43: }
   ```
   - *Observation:* When an employee or personnel record is deleted, all matching historical telemetry rows in `access_logs` are deleted synchronously within the web request transaction.

#### Task 1.3: Concurrency Race in Personnel ID Assignment
1. **`app/Models/Personnel.php` (Lines 58-66):**
   ```php
   58: static::creating(function ($model) {
   59:     if (empty($model->person_uuid)) {
   60:         $model->person_uuid = (string) Str::uuid();
   61:     }
   62:     if (empty($model->customize_id)) {
   63:         $maxId = static::max('customize_id') ?? 100;
   64:         $model->customize_id = $maxId + 1;
   65:     }
   66: });
   ```
   - *Observation:* `customize_id` is assigned using non-atomic `static::max('customize_id') + 1`. Under concurrent creation (visitor check-in, bulk employee import, multi-user HR operations), multiple threads read the same max ID, leading to unique constraint crashes.

---

### Phase 2: Compute & Memory Bottlenecks

#### Task 2.1: Quadratic In-Memory Attendance & Payroll Calculations
1. **`app/Http/Controllers/ReportController.php` (Lines 57-81):**
   ```php
   57: $employees = Employee::with('department')->where('employment_status', 'active')->get();
   64: $records = AttendanceRecord::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])->get();
   66: $data = $employees->map(function ($emp) use ($records) {
   67:     $empRecords = $records->where('employee_id', $emp->id);
   74:     'days_present' => $empRecords->whereIn('status', ['present', 'late', 'early_out', 'late_and_early_out'])->count(),
   75:     'days_late' => $empRecords->whereIn('status', ['late', 'late_and_early_out'])->count(),
   76:     'days_absent' => $empRecords->where('status', 'absent')->count(),
   77:     'days_on_leave' => $empRecords->where('status', 'on_leave')->count(),
   78:     'total_work_hours' => round($empRecords->sum('total_work_hours'), 2),
   79:     'total_overtime_hours' => round($empRecords->sum('overtime_hours'), 2),
   ```
2. **`app/Http/Controllers/PayrollExportController.php` (Lines 26-60):**
   ```php
   26: $employees = Employee::with(['department', 'designation'])->where('employment_status', 'active')->orderBy('employee_code')->get();
   31: $records = AttendanceRecord::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])->get();
   33: $data = $employees->map(function ($emp) use ($records, $startDate, $endDate) {
   34:     $empRecords = $records->where('employee_id', $emp->id);
   ...
   ```
   - *Observation:* Both controllers load all employees and all monthly records into PHP memory, running an $O(N \times M)$ nested filtering loop in PHP instead of delegating aggregation to the PostgreSQL query engine. Furthermore, CSV exports assemble the full array in memory before streaming.

#### Task 2.2: Large Base64 Image Columns in Entity Serialization
1. **`app/Models/Personnel.php` (Lines 16-18, 39-40):**
   - *Observation:* `$hidden` is not declared on `Personnel`. The `photo_base64` attribute (ranging from 100 KB to 2 MB per face) is included in every serialized model instance.
2. **`app/Http/Controllers/PersonnelController.php` (Line 19, 40):**
   ```php
   19: $query = Personnel::with('employee');
   40: $personnel = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));
   ```
   - *Observation:* `select(*)` retrieves `photo_base64` from PostgreSQL, causing large database network payload and JSON responses reaching 15–30 MB for a single page of 15 records.
3. **`app/Http/Controllers/EmployeeController.php` (Line 19, 61):**
   ```php
   19: $query = Employee::with(['personnel', 'department', 'designation', 'location', 'shift', 'manager', 'reportingManager']);
   ```
   - *Observation:* Eager-loading `personnel` without column scoping serializes `personnel.photo_base64` in `GET /api/employees` responses.

#### Task 2.3: Synchronous Camera Personnel Import
1. **`app/Http/Controllers/DeviceController.php` (Lines 86-91):**
   ```php
   86: public function importPersonnel(Device $device): JsonResponse
   87: {
   88:     $result = $this->cameraService->importPersonnelFromCamera($device);
   89:     return response()->json($result);
   90: }
   ```
2. **`app/Services/CameraService.php` (Lines 155-260):**
   - *Observation:* `importPersonnelFromCamera` synchronously executes `searchPersonList` over MQTT (timeout 5-10s) and loops through every person item. For every item without an image, it fires a secondary `searchPerson` MQTT request (timeout 2-5s per person). A camera with 50-100 personnel takes 60–180 seconds, guaranteeing an HTTP 504 Gateway Timeout or PHP-FPM execution timeout.

---

### Phase 3: Telemetry, WebSockets & Real-Time Ingestion

#### Task 3.1: Heartbeat Database Write Flooding
1. **`app/Console/Commands/MqttListenCommand.php` (Lines 86-93, 567-576):**
   ```php
   86: $deviceModel = Device::where('device_id', $deviceId)->first();
   87: if ($deviceModel) {
   88:     $deviceModel->update([
   89:         'last_heartbeat_at' => now(),
   90:         'is_active' => true,
   91:     ]);
   92: }
   ```
   - *Observation:* Lines 86-93 execute on *every single incoming MQTT packet* across all wildcard streams. For 20 cameras generating telemetry, this triggers 50–200 queries/updates per second against the `devices` table.
2. **`app/Http/Controllers/HttpWebhookController.php` (Lines 99-109, 126-130, 184-194, 205-210):**
   - *Observation:* In `handleVerify` and `handleSnap`, `Device::firstOrCreate` is called, followed by an immediate update, and then 20 lines later `Device::where('device_id', $deviceId)->first()->update(...)` is called again in the exact same HTTP request.

#### Task 3.2: Synchronous WebSocket Broadcasting
1. **`app/Events/AccessLogReceived.php` (Line 8, 12):**
   ```php
   8: use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
   12: class AccessLogReceived implements ShouldBroadcastNow
   ```
2. **`app/Console/Commands/MqttListenCommand.php` (Line 264):**
   ```php
   264: broadcast(new AccessLogReceived($log));
   ```
   - *Observation:* `ShouldBroadcastNow` forces Laravel to issue a synchronous HTTP POST request to Reverb (`:8080`) inline inside the single-threaded MQTT loop. Any socket saturation or latency on Reverb stalls packet ingestion from cameras.

#### Task 3.3: Downlink Connection Churn & Sequential Head-of-Line Blocking
1. **`app/Services/CameraMqttService.php` (Lines 65-116, 216-235):**
   ```php
   216: $clientId = 'camera_hub_cmd_' . uniqid();
   217: $mqtt = new MqttClient($this->host, $this->port, $clientId);
   229: $mqtt->connect($settings, true);
   233: $mqtt->publish($topic, $jsonPayload, 0);
   234: $mqtt->disconnect();
   ```
   - *Observation:* Every downlink command instantiates, handshakes, connects, and disconnects a TCP socket.
2. **`app/Jobs/SyncPersonnelJob.php` (Lines 48-125):**
   ```php
   48: $devices = $this->targetDeviceId
   49:     ? Device::where('id', $this->targetDeviceId)->where('is_active', true)->get()
   50:     : Device::where('is_active', true)->get();
   ...
   57: foreach ($devices as $device) {
   ...
   89:     $res = $cameraService->deletePerson($device, [$cId]);
   ...
   123:    $res = $cameraService->addOrUpdatePerson($device, $person);
   ```
   - *Observation:* When an employee face is added, updated, or removed, `SyncPersonnelJob` iterates over active devices *sequentially*. If one camera is offline, `publishCommandAndWait` stalls for the full timeout duration (1.8s–10s), blocking the entire `camera-sync` worker queue and delaying sync to all remaining online cameras.

---

### Phase 4: Query Consolidation & Caching Strategy

#### Task 4.1: Dashboard KPI Stats Consolidation & Caching
1. **`app/Http/Controllers/DashboardStatsController.php` (Lines 32-34):**
   ```php
   32: $alertsToday = \App\Models\DeviceAlert::where('captured_at', '>=', $today)->count();
   33: $criticalAlertsToday = \App\Models\DeviceAlert::where('captured_at', '>=', $today)->where('severity', 'CRITICAL')->count();
   34: $unresolvedAlerts = \App\Models\DeviceAlert::whereIn('status', ['NEW', 'ACKNOWLEDGED'])->count();
   ```
   - *Observation:* 3 separate queries hit `device_alerts`. No caching is applied to `DashboardStatsController::index`. Multi-tab operator polling triggers repeated table scans across 6 tables every 5-10 seconds.

#### Task 4.2: Holiday & Shift Lookups in Attendance Processing
1. **`app/Services/AttendanceProcessingService.php` (Lines 98, 142, 156-162):**
   ```php
   156: $isHoliday = Holiday::where('date', $dateStr)
   157:     ->orWhere(function ($q) use ($dateObj) {
   158:         $q->where('is_recurring', true)
   159:           ->whereMonth('date', $dateObj->month)
   160:           ->whereDay('date', $dateObj->day);
   161:     })
   162:     ->exists();
   ```
   - *Observation:* `Holiday::whereMonth()->whereDay()` executes non-SARGable `EXTRACT()` queries on the `holidays` table on *every incoming biometric punch*. In addition, `resolveEffectiveShift` is called twice per punch (in `resolveWorkDate` and `recalculateDailyAttendance`), executing redundant database lookups.

---

### Phase 5: Frontend Bundle & Client-Side Concurrency

#### Task 5.1: Dynamic Code-Splitting in Frontend
1. **`resources/js/App.vue` (Lines 540-547):**
   ```javascript
   import LiveTelemetry from "./views/LiveTelemetry.vue";
   import StrangerSnapsMonitor from "./views/StrangerSnapsMonitor.vue";
   import DeviceAlertsCenter from "./views/DeviceAlertsCenter.vue";
   import PersonnelManager from "./views/PersonnelManager.vue";
   import DeviceManager from "./views/DeviceManager.vue";
   import AccessLogsHistory from "./views/AccessLogsHistory.vue";
   import SyncTasksMonitor from "./views/SyncTasksMonitor.vue";
   ```
   - *Observation:* 7 heavy administrative views are eagerly imported and bundled into the initial `app-*.js` chunk (328.16 kB / 82 kB gzip).
2. **`resources/js/utils/cameraHqPlayer.js` (513 lines):**
   - *Observation:* WebGL/WASM player is imported by `CameraLivePreviewModal.vue`, which is imported by `DeviceManager.vue`, pulling WebGL shaders directly into the entry bundle.

#### Task 5.2: WebSocket Listener Deduplication & Memory Leaks
1. **`resources/js/App.vue` (Lines 754-808, 838-854):**
   ```javascript
   echo.channel("access-logs")
       .listen(".AccessLogReceived", (e) => store.addLiveLog(e))
       .listen("AccessLogReceived", (e) => store.addLiveLog(e));
   ```
   - *Observation:* Every channel registers dual listeners (`.Event` and `Event`). Both triggers execute, duplicating log entries, toast alerts, and counter increments.
   - *Observation:* `initTelemetry()` lacks an initialization guard (`isInitialized`). When authentication triggers, `initTelemetry()` runs multiple times, multiplying listener closures.
   - *Observation:* Connection listeners (`echo.connector.pusher.connection.bind`) are never unbound in `cleanupTelemetry()`, leaking memory on repeated login/logout cycles.

---

## 2. Logic Chain

From the direct observations above, the logical progression to the architectural solutions is established:

```
[Observation 1.1: Unindexed FKs & high-frequency filter columns]
   ──> Unindexed foreign keys cause full sequential table scans during JOINs and cascade operations.
   ──> Single-column indexes on access_logs and device_alerts fail to accelerate multi-condition queries.
   ──> Architectural Solution: Create migration with composite and foreign key indexes.

[Observation 1.2: Synchronous AccessLog deletion in Observers]
   ──> AccessLog represents physical security audit compliance data (who walked through which gate).
   ──> Deleting access logs during personnel deletion deletes audit trails and acquires exclusive row/table locks.
   ──> Under concurrent camera telemetry writes, this creates deadlocks or timeouts.
   ──> Architectural Solution: Remove AccessLog::query()->delete() from PersonnelObserver and EmployeeObserver.

[Observation 1.3: static::max('customize_id') + 1 in Personnel boot hook]
   ──> Non-atomic read-then-write under concurrency causes race conditions and duplicate key exceptions.
   ──> PostgreSQL provides atomic sequences (nextval) that operate lock-free across concurrent transactions.
   ──> Architectural Solution: Provision personnel_customize_id_seq sequence and refactor model boot hook.

[Observation 2.1: In-memory O(N*M) loops in monthly report/payroll controllers]
   ──> Fetching all records and looping in PHP causes quadratic compute time and high RAM usage.
   ──> Relational databases are optimized to perform GROUP BY and conditional SUM() in a single pass.
   ──> Architectural Solution: Single SQL aggregate query with keyBy('employee_id') and cursor() CSV streaming.

[Observation 2.2: photo_base64 serialized in index endpoints]
   ──> Multi-megabyte JSON payloads degrade network throughput, mobile client rendering, and memory usage.
   ──> Architectural Solution: Add photo_base64 to $hidden on Personnel and select lightweight column subsets.

[Observation 2.3: Synchronous MQTT import in DeviceController]
   ──> WAN MQTT round-trips for 50-100 personnel take up to 180 seconds, exceeding HTTP gateway timeouts.
   ──> Architectural Solution: Offload to ImportCameraPersonnelJob on camera-sync queue and return 202 Accepted.

[Observation 3.1: Heartbeat DB updates on every MQTT message]
   ──> Continuous video analytics streams bombard the devices table with duplicate UPDATE queries.
   ──> Camera presence is adequate when refreshed once every 60 seconds.
   ──> Architectural Solution: Cache heartbeat throttle key in Redis (60s TTL) before updating PostgreSQL.

[Observation 3.2: AccessLogReceived implements ShouldBroadcastNow]
   ──> Synchronous HTTP calls to Reverb (:8080) inside the single-threaded MQTT loop cause ingestion stalls.
   ──> Architectural Solution: Switch to ShouldBroadcast backed by Redis queue.

[Observation 3.3: Sequential multi-camera loop in SyncPersonnelJob]
   ──> Head-of-line blocking stalls the entire sync queue if one camera is offline.
   ──> Architectural Solution: Dispatch parallel SyncDevicePersonnelJob instances per device.

[Observation 4.1: Repeated multi-query polling on DashboardStatsController]
   ──> Polling without caching places continuous load on PostgreSQL.
   ──> Architectural Solution: Consolidate device_alerts queries and cache response for 5-10s in Redis.

[Observation 4.2: Non-SARGable holiday lookups on every punch]
   ──> EXTRACT(MONTH/DAY) queries prevent index usage.
   ──> Holidays change infrequently and fit easily in application memory.
   ──> Architectural Solution: Cache yearly holidays in Redis/memory and evaluate in PHP.

[Observation 5.1 & 5.2: Eager view imports and duplicate WebSocket listeners]
   ──> 328 KB entry bundle includes views not immediately needed.
   ──> Dual .listen() bindings cause duplicate event processing.
   ──> Architectural Solution: Use defineAsyncComponent, split player chunk, and deduplicate listeners with guards.
```

---

## 3. Caveats

1. **Test Environment vs Production Database Engine:**
   - In `phpunit.xml`, tests execute against in-memory SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). SQLite does not support PostgreSQL sequence syntax (`CREATE SEQUENCE`, `nextval()`). Model boot hooks and migrations must use conditional driver detection (`DB::getDriverName() === 'pgsql'`) with an atomic fallback for SQLite during unit testing.
2. **Security Sub-Team Parallelism:**
   - The security sub-team is modifying `routes/channels.php` to switch vision telemetry channels from public channels to private channels (`PrivateChannel`). When adjusting `App.vue` in Task 5.2, coordination with the private channel naming convention (`private-access-logs` vs `echo.private(...)`) must be maintained.
3. **Queue Worker Requirement:**
   - Converting `AccessLogReceived` from `ShouldBroadcastNow` to `ShouldBroadcast` requires a queue worker running (`php artisan queue:work redis` or `horizon`). In test environments with `QUEUE_CONNECTION=sync`, execution remains synchronous and fully compatible with test assertions.
4. **WebSocket Echo Listener Convention:**
   - Events defining `broadcastAs()` return exact names (e.g. `'AccessLogReceived'`). Laravel Echo requires a leading dot (e.g. `.listen(".AccessLogReceived", ...)`) to bypass default namespace prefixing. Removing the non-dotted listener is safe and eliminates duplicate execution.

---

## 4. Conclusion & Architectural Blueprints

### Detailed Blueprints per Task

#### Task 1.1: Migration Blueprint (`database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php`)
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. device_alerts table
        Schema::table('device_alerts', function (Blueprint $table) {
            $table->index('device_id', 'device_alerts_device_id_index');
            $table->index(['captured_at', 'severity'], 'device_alerts_captured_at_severity_index');
        });

        // 2. visits table
        Schema::table('visits', function (Blueprint $table) {
            $table->index('host_employee_id', 'visits_host_employee_id_index');
            $table->index('personnel_id', 'visits_personnel_id_index');
            $table->index('check_in_time', 'visits_check_in_time_index');
            $table->index('check_out_time', 'visits_check_out_time_index');
        });

        // 3. visitors table
        Schema::table('visitors', function (Blueprint $table) {
            $table->index('email', 'visitors_email_index');
            $table->index('phone', 'visitors_phone_index');
        });

        // 4. employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->index('designation_id', 'employees_designation_id_index');
            $table->index('location_id', 'employees_location_id_index');
            $table->index('shift_id', 'employees_shift_id_index');
            $table->index('reporting_manager_id', 'employees_reporting_manager_id_index');
        });

        // 5. leave_requests table
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'leave_requests_emp_status_dates_index');
            $table->index('status', 'leave_requests_status_index');
        });

        // 6. access_logs table
        Schema::table('access_logs', function (Blueprint $table) {
            $table->index(['captured_at', 'verify_status'], 'access_logs_captured_at_verify_status_index');
            $table->index(['customize_id', 'captured_at'], 'access_logs_customize_id_captured_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropIndex('access_logs_customize_id_captured_at_index');
            $table->dropIndex('access_logs_captured_at_verify_status_index');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_status_index');
            $table->dropIndex('leave_requests_emp_status_dates_index');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_reporting_manager_id_index');
            $table->dropIndex('employees_shift_id_index');
            $table->dropIndex('employees_location_id_index');
            $table->dropIndex('employees_designation_id_index');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex('visitors_phone_index');
            $table->dropIndex('visitors_email_index');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_check_out_time_index');
            $table->dropIndex('visits_check_in_time_index');
            $table->dropIndex('visits_personnel_id_index');
            $table->dropIndex('visits_host_employee_id_index');
        });

        Schema::table('device_alerts', function (Blueprint $table) {
            $table->dropIndex('device_alerts_captured_at_severity_index');
            $table->dropIndex('device_alerts_device_id_index');
        });
    }
};
```

#### Task 1.2: Decouple Access Logs from Personnel & Employee Deletion
- **File:** `app/Observers/PersonnelObserver.php`
  - *Modification:* In `deleting(Personnel $personnel)`, remove lines 37-49 entirely. Keep only `SyncTask` cleanup, `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', ...)`, and `PersonnelUpdated` event.
- **File:** `app/Observers/EmployeeObserver.php`
  - *Modification:* In `deleting(Employee $employee)`, remove lines 31-43 entirely. Attendance punches and records associated with the internal employee profile can still be cleaned up, but `AccessLog` remains an immutable compliance record.

#### Task 1.3: PostgreSQL Sequence for Personnel `customize_id`
- **Migration:** In `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php` (or dedicated migration):
  ```php
  if (DB::getDriverName() === 'pgsql') {
      DB::statement("CREATE SEQUENCE IF NOT EXISTS personnel_customize_id_seq START WITH 1000");
      DB::statement("SELECT setval('personnel_customize_id_seq', GREATEST(COALESCE((SELECT MAX(customize_id) FROM personnel), 999) + 1, 1000), false)");
      DB::statement("ALTER TABLE personnel ALTER COLUMN customize_id SET DEFAULT nextval('personnel_customize_id_seq')");
  }
  ```
- **Model:** `app/Models/Personnel.php` lines 62-65:
  ```php
  if (empty($model->customize_id)) {
      if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
          $model->customize_id = (int) \Illuminate\Support\Facades\DB::scalar("SELECT nextval('personnel_customize_id_seq')");
      } else {
          $maxId = static::max('customize_id') ?? 999;
          $model->customize_id = max($maxId + 1, 1000);
      }
  }
  ```

#### Task 2.1: SQL Aggregates for Monthly Attendance & Payroll
- **`app/Http/Controllers/ReportController.php` (`monthlyAttendance`):**
  ```php
  $startDate = Carbon::create($year, $month, 1)->startOfMonth();
  $endDate = $startDate->copy()->endOfMonth();

  $aggregates = AttendanceRecord::query()
      ->selectRaw("
          employee_id,
          COUNT(CASE WHEN status IN ('present', 'late', 'early_out', 'late_and_early_out') THEN 1 END) as days_present,
          COUNT(CASE WHEN status IN ('late', 'late_and_early_out') THEN 1 END) as days_late,
          COUNT(CASE WHEN status = 'absent' THEN 1 END) as days_absent,
          COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as days_on_leave,
          SUM(total_work_hours) as total_work_hours,
          SUM(overtime_hours) as total_overtime_hours
      ")
      ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
      ->groupBy('employee_id')
      ->get()
      ->keyBy('employee_id');

  $employees = Employee::with('department')
      ->where('employment_status', 'active')
      ->get();

  $data = $employees->map(function ($emp) use ($aggregates) {
      $agg = $aggregates->get($emp->id);
      return [
          'employee_id' => $emp->id,
          'employee_code' => $emp->employee_code,
          'employee_name' => $emp->name,
          'department' => $emp->department?->name ?? 'N/A',
          'days_present' => (int) ($agg->days_present ?? 0),
          'days_late' => (int) ($agg->days_late ?? 0),
          'days_absent' => (int) ($agg->days_absent ?? 0),
          'days_on_leave' => (int) ($agg->days_on_leave ?? 0),
          'total_work_hours' => round((float) ($agg->total_work_hours ?? 0), 2),
          'total_overtime_hours' => round((float) ($agg->total_overtime_hours ?? 0), 2),
      ];
  });
  ```
- **`app/Http/Controllers/PayrollExportController.php` (`export`):**
  Stream CSV row-by-row via cursor:
  ```php
  $callback = function () use ($startDate, $endDate, $aggregates) {
      $handle = fopen('php://output', 'w');
      fputcsv($handle, ['Employee Code', 'Employee Name', 'Department', 'Designation', 'Present Days', 'Half Days', 'Leave Days', 'Absent Days', 'Payable Days', 'Total Work Hours', 'Overtime Hours']);

      $employees = Employee::with(['department', 'designation'])
          ->where('employment_status', 'active')
          ->orderBy('employee_code');

      foreach ($employees->cursor() as $emp) {
          $agg = $aggregates->get($emp->id);
          $presentDays = (int) ($agg->days_present ?? 0);
          $halfDays = (int) ($agg->days_half_day ?? 0);
          $leaveDays = (int) ($agg->days_on_leave ?? 0);
          $absentDays = (int) ($agg->days_absent ?? 0);
          $payableDays = $presentDays + ($halfDays * 0.5) + $leaveDays;

          fputcsv($handle, [
              $emp->employee_code,
              $emp->name,
              $emp->department?->name ?? 'General',
              $emp->designation?->name ?? 'Staff',
              $presentDays,
              $halfDays,
              $leaveDays,
              $absentDays,
              $payableDays,
              round((float) ($agg->total_work_hours ?? 0), 2),
              round((float) ($agg->total_overtime_hours ?? 0), 2),
          ]);
      }
      fclose($handle);
  };
  ```

#### Task 2.2: Strip `photo_base64` from Model & Index Payloads
- **`app/Models/Personnel.php`:**
  ```php
  protected $hidden = [
      'photo_base64',
  ];
  ```
- **`app/Http/Controllers/PersonnelController.php` (`index`):**
  ```php
  $query = Personnel::query()
      ->select([
          'id', 'customize_id', 'person_uuid', 'name', 'person_type',
          'gender', 'id_card', 'tel_num', 'photo_path', 'temp_valid',
          'valid_begin', 'valid_end', 'effect_number', 'created_at', 'updated_at'
      ])
      ->with(['employee:id,personnel_id,employee_code,first_name,last_name,department_id,designation_id']);
  ```
- **`app/Http/Controllers/EmployeeController.php` (`index`):**
  ```php
  $query = Employee::with([
      'personnel:id,customize_id,person_uuid,name,person_type,tel_num,photo_path',
      'department:id,name',
      'designation:id,name',
      'location:id,name',
      'shift:id,name,shift_start,shift_end',
      'manager:id,first_name,last_name',
      'reportingManager:id,first_name,last_name'
  ]);
  ```

#### Task 2.3: Asynchronous Camera Personnel Import Job
- **Job Class:** `app/Jobs/ImportCameraPersonnelJob.php`
  - Implements `ShouldQueue`, uses `Queueable`, runs on queue `'camera-sync'`.
  - In `handle(CameraService $cameraService)`: executes `$cameraService->importPersonnelFromCamera($this->device)`.
- **Controller:** `app/Http/Controllers/DeviceController.php` (`importPersonnel`):
  ```php
  public function importPersonnel(Device $device): JsonResponse
  {
      ImportCameraPersonnelJob::dispatch($device, auth()->id());

      return response()->json([
          'status' => 'QUEUED',
          'message' => 'Personnel import task dispatched successfully.',
          'device_id' => $device->device_id,
      ], 202);
  }
  ```

#### Task 3.1: Redis Throttle for Camera Heartbeat Writes
- **`app/Console/Commands/MqttListenCommand.php`:**
  In `handleMessage` lines 84-93:
  ```php
  if ($deviceId) {
      $deviceId = trim((string) $deviceId);
      $throttleKey = "device_hb_throttle:{$deviceId}";
      if (!Cache::has($throttleKey)) {
          Device::where('device_id', $deviceId)->update([
              'last_heartbeat_at' => now(),
              'is_active' => true,
          ]);
          Cache::put($throttleKey, true, 60);
      }
  }
  ```
  Apply the same throttle in `handleHeartbeat()` and `handleOnlineStatus()`.
- **`app/Http/Controllers/HttpWebhookController.php`:**
  Eliminate duplicate queries in `handleVerify` and `handleSnap`. Use Redis throttle key `device_hb_throttle:{$deviceId}`.

#### Task 3.2: Asynchronous `AccessLogReceived` Event
- **`app/Events/AccessLogReceived.php`:**
  ```php
  use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
  ...
  class AccessLogReceived implements ShouldBroadcast
  {
      use Dispatchable, InteractsWithSockets, SerializesModels;

      public string $broadcastQueue = 'default';
      ...
  ```

#### Task 3.3: MQTT Connection Reuse & Parallel Dispatch
- **`app/Services/CameraMqttService.php`:**
  Maintain `$this->client` instance within the service:
  ```php
  protected ?MqttClient $sharedClient = null;

  protected function getClient(): MqttClient
  {
      if (!$this->sharedClient || !$this->sharedClient->isConnected()) {
          $this->sharedClient = new MqttClient($this->host, $this->port, 'camera_hub_worker_' . getmypid());
          $this->sharedClient->connect($this->getConnectionSettings(), true);
      }
      return $this->sharedClient;
  }
  ```
- **`app/Jobs/SyncPersonnelJob.php`:**
  Decompose into parallel sub-jobs:
  ```php
  foreach ($devices as $device) {
      SyncDevicePersonnelJob::dispatch($device->id, $this->personnelId, $this->action, $cId);
  }
  ```

#### Task 4.1: Consolidate Alert Queries & Cache Dashboard Stats
- **`app/Http/Controllers/DashboardStatsController.php`:**
  ```php
  return Cache::remember('dashboard_telemetry_stats', 5, function () use ($today) {
      // 1. Single consolidated query for device alerts
      $alertStats = \App\Models\DeviceAlert::toBase()
          ->selectRaw('count(case when captured_at >= ? then 1 end) as alerts_today', [$today])
          ->selectRaw('count(case when captured_at >= ? and severity = ? then 1 end) as critical_alerts_today', [$today, 'CRITICAL'])
          ->selectRaw('count(case when status in (?, ?) then 1 end) as unresolved_alerts', ['NEW', 'ACKNOWLEDGED'])
          ->first();

      ...
      return response()->json([...]);
  });
  ```

#### Task 4.2: Holiday & Shift Caching in Attendance Engine
- **`app/Services/AttendanceProcessingService.php`:**
  ```php
  protected function isHoliday(Carbon $date): bool
  {
      $year = $date->year;
      $holidays = Cache::remember("holidays_{$year}", 3600, function () use ($year) {
          return Holiday::whereYear('date', $year)->orWhere('is_recurring', true)->get();
      });

      $dateStr = $date->format('Y-m-d');
      return $holidays->contains(function ($h) use ($date, $dateStr) {
          if ($h->date === $dateStr) return true;
          return $h->is_recurring && (int)$h->date->format('m') === $date->month && (int)$h->date->format('d') === $date->day;
      });
  }
  ```

#### Task 5.1: Frontend Code-Splitting
- **`resources/js/App.vue`:**
  Convert eager imports to `defineAsyncComponent`:
  ```javascript
  const LiveTelemetry = defineAsyncComponent(() => import("./views/LiveTelemetry.vue"));
  const StrangerSnapsMonitor = defineAsyncComponent(() => import("./views/StrangerSnapsMonitor.vue"));
  const DeviceAlertsCenter = defineAsyncComponent(() => import("./views/DeviceAlertsCenter.vue"));
  const PersonnelManager = defineAsyncComponent(() => import("./views/PersonnelManager.vue"));
  const DeviceManager = defineAsyncComponent(() => import("./views/DeviceManager.vue"));
  const AccessLogsHistory = defineAsyncComponent(() => import("./views/AccessLogsHistory.vue"));
  const SyncTasksMonitor = defineAsyncComponent(() => import("./views/SyncTasksMonitor.vue"));
  ```
- **`vite.config.js`:**
  Add manual chunking for the WebGL player:
  ```javascript
  build: {
      rollupOptions: {
          output: {
              manualChunks(id) {
                  if (id.includes('cameraHqPlayer')) {
                      return 'camera-player';
                  }
              },
          },
      },
  },
  ```

#### Task 5.2: WebSocket Listener Deduplication & Lifecycle Safety
- **`resources/js/App.vue`:**
  ```javascript
  let isTelemetryInitialized = false;

  const initTelemetry = () => {
      if (isTelemetryInitialized) return;
      isTelemetryInitialized = true;

      store.fetchStats();
      store.fetchDevices();
      store.fetchRecentLogs();
      notificationStore.fetchNotifications();

      // Deduplicated single listeners
      echo.channel("access-logs").listen(".AccessLogReceived", (e) => store.addLiveLog(e));
      echo.channel("stranger-snaps").listen(".StrangerSnapReceived", (e) => store.addStrangerSnap(e));
      echo.channel("device-alerts")
          .listen(".DeviceAlertReceived", (e) => store.addDeviceAlert(e))
          .listen(".DeviceAlertUpdated", (e) => store.updateAlertStatus(e));
      echo.channel("device-status").listen(".DeviceStatusUpdated", (e) => store.updateDeviceStatus(e));
      echo.channel("personnel").listen(".PersonnelUpdated", (e) => store.handlePersonnelUpdated(e));
      echo.channel("sync-tasks").listen(".SyncTaskUpdated", (e) => store.handleSyncTaskUpdated(e));
      echo.channel("attendance").listen(".AttendancePunchReceived", (e) => attendanceStore.handleLivePunch(e));
      echo.channel("visitors")
          .listen(".VisitorCheckedIn", (e) => visitorStore.handleLiveVisitorCheckIn(e))
          .listen(".VisitorCheckedOut", (e) => visitorStore.handleLiveVisitorCheckOut(e));
      echo.channel("notifications").listen(".NotificationCreated", (e) => notificationStore.handleLiveNotification(e));
  };

  const cleanupTelemetry = () => {
      isTelemetryInitialized = false;
      ...
  };
  ```

---

## 5. Verification Method

To independently verify the implementation once applied:

1. **Migration Verification:**
   ```bash
   php artisan migrate
   php artisan migrate:status
   php artisan migrate:rollback --step=1
   php artisan migrate
   ```
   *Expected Result:* Migration executes and rolls back without constraint errors.

2. **Automated Test Suite:**
   ```bash
   php artisan test
   ```
   *Expected Result:* All 264 unit and feature tests pass without regression.

3. **Personnel Deletion Verification:**
   - Create a test personnel with access logs.
   - Delete the personnel record via `Personnel::destroy(...)`.
   - Verify that `AccessLog::where('customize_id', ...)->count()` remains unchanged (> 0).

4. **Personnel Sequence Concurrency Verification:**
   - Execute concurrent `Personnel::create([...])` operations in parallel workers.
   - Verify all assigned `customize_id` values are monotonically increasing and no duplicate key exceptions occur.

5. **Payload Size Verification:**
   - Call `GET /api/personnel` and `GET /api/employees`.
   - Inspect JSON responses to confirm `photo_base64` is null or omitted, and payload size is < 100 KB.

6. **Frontend Asset Build & Chunk Splitting Verification:**
   ```bash
   npm run build
   ```
   *Expected Result:* Build succeeds with 0 errors. Chunks `LiveTelemetry-*.js`, `DeviceManager-*.js`, and `camera-player-*.js` appear as distinct split assets, with the main entry chunk reduced below 200 KB.
