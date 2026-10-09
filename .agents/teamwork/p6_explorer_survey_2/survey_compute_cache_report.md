# Comprehensive Architectural & Performance Survey Report: Tasks 6.5 – 6.11
## Compute Overhaul, Batching Architecture, and Telemetry Caching Pipeline

**Author:** Explorer 2 (Teamwork Performance Survey Subagent)  
**Date:** 2026-10-07  
**Scope:** Phase 6 Compute Overhaul & Caching Pipeline (`tasks-performance.md` Tasks 6.5 – 6.11)  
**Target Repository:** Intelligent AI Camera Hub & Biometric Attendance System (`AI-Camera-Integration`)  
**Status:** Read-Only Investigation & Architectural Blueprint  

---

## 1. Executive Summary

This survey provides an in-depth code audit and architectural blueprint for **Tasks 6.5 through 6.11** of Phase 6 in `tasks-performance.md`. Under edge-to-cloud telemetry scale (50+ smart cameras, 10,000+ employees, millions of access logs), the application runtime encounters critical performance bottlenecks across database round-trips, in-memory collection scans, Redis single-thread event loop blocks, and redundant queries in high-frequency workers.

### Key Audit Findings & Projected Optimization Impact

| Task Code | Subsystem & File Location | Primary Bottleneck | Time Complexity (Current vs Target) | Database Query Volume (Current vs Target) | Scale Impact |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Task 6.5** | `EmployeeController.php:263-269`<br>`Employee.php:196-235` | Loop queries in `isRestDay()` over 30+ days | $O(N)$ DB queries $\to$ $O(1)$ DB query + $O(N)$ in-memory | 31 queries $\to$ 1 query (96.8% reduction) | Critical (stops connection pool saturation during monthly summaries) |
| **Task 6.6** | `DeviceController.php:509-514, 562-564` | Linear collection scan `$localPersonnel->contains()` and unbounded outbox pull | $O(N \times M)$ scan $\to$ $O(N + M)$ hashmap<br>$O(T)$ memory $\to$ $O(P)$ memory | Unbounded historical scan $\to$ 1 indexed subquery / `DISTINCT ON` | High (eliminates quadratic CPU spikes and memory exhaustion in device audit) |
| **Task 6.7** | `DeviceAlertController.php:117-122` | Sequential `foreach` updating alert rows one-by-one | $O(N)$ SQL writes $\to$ $O(1)$ atomic bulk SQL update | $N$ UPDATE queries $\to$ 1 UPDATE query | Medium/High (eliminates row-by-row write contention on `device_alerts`) |
| **Task 6.8** | `ShiftController.php:298-312`<br>`AttendanceProcessingService.php:118` | Blocking `$redis->keys("emp_shift:{$id}:*")` in employee loop | $O(K \times E)$ blocking Redis scan $\to$ $O(1)$ atomic increment / set del | Full Redis DB scans $\to$ 0 `KEYS` commands | Critical (prevents freezing Redis event loop, queues, and WebSocket streams) |
| **Task 6.9** | `MqttListenCommand.php:243, 333, 410` | Redundant `Device::firstOrCreate` on every incoming telemetry packet | $O(E)$ DB lookups $\to$ $O(1)$ Redis cache lookup | 100+ SELECT/sec $\to$ 0 SELECT/sec (throttled) | Critical (relieves database connection pool during 100+ event/sec bursts) |
| **Task 6.10** | `ProcessAttendancePunchJob.php:33-46`<br>`EmployeeObserver.php`<br>`PersonnelObserver.php` | 2–3 sequential SQL lookups per punch to resolve `customize_id` $\to$ employee | 2–3 SQL queries/punch $\to$ 1 Redis lookup + 1 PK fetch | 20,000–30,000 queries $\to$ 0–10,000 fast PK lookups | High (accelerates attendance punch worker queue throughput by 3x) |
| **Task 6.11** | `DeviceAlertController.php:96, 120`<br>`SettingController.php:30-45`<br>`SettingService.php:60-89` | Missing invalidation for `'device_alert_stats'` and `'dashboard_telemetry_stats'`; uncached `'settings.public'` | Uncached public queries $\to$ 1-hour Redis cache; stale stats $\to$ immediate invalidation | Stale KPI reads eliminated; public settings DB reads $\to$ 0 | Medium (ensures UI KPI consistency and eliminates branding DB reads) |

---

## 2. Detailed Task-by-Task Deep Dive

```
                                  TASKS ARCHITECTURE MAP
┌──────────────────────────────────────────────────────────────────────────────────┐
│                             APPLICATION RUNTIME & COMPUTE                         │
│                                                                                  │
│   Task 6.5: Employee Summary Date Loop                                           │
│   [ 30+ Day Iterations ] ──────► [ Pre-fetch Range Assignments (1 Query) ]       │
│                                           │                                      │
│                                           ▼                                      │
│                               [ In-Memory Collection Filter ]                    │
│                                                                                  │
│   Task 6.6: Device Audit Edge Reconciliation                                     │
│   [ Edge Camera Persons (M) ] ──► [ $localPersonnel->keyBy('customize_id') (O(1))]│
│   [ Historical Outbox Tasks ] ──► [ SQL Subquery MAX(id) / DISTINCT ON (personnel_id)]│
│                                                                                  │
│   Task 6.7: Device Alerts Management                                             │
│   [ Multi-Record Status Change ] ──► [ Atomic SQL UPDATE whereIn('id', $ids) ]   │
│                                           │                                      │
│                                           ▼                                      │
│                               [ Cache::forget('device_alert_stats') ]            │
└───────────────────────────────────────────┬──────────────────────────────────────┘
                                            │
                                            ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                          CACHING & TELEMETRY STREAM PIPELINE                      │
│                                                                                  │
│   Task 6.8: Bulk Shift Cache Invalidation                                        │
│   [ Eliminate $redis->keys() ] ──► [ Version Counter emp_shift_v:{$id} INCR ]    │
│                                    [ or Tracked Set emp_shift_keys:{$id} ]       │
│                                                                                  │
│   Task 6.9: High-Throughput MQTT Telemetry Listener                              │
│   [ Incoming Verify/Snap/Alert ] ─► [ Cache::has('device_registered:{$id}') ]   │
│                                     └── Yes: Skip DB query completely!           │
│                                                                                  │
│   Task 6.10: Biometric Verification Punch Worker                                 │
│   [ Ingest customize_id ] ───────► [ Cache 'emp_custom_id:{$cId}' (1h TTL) ]    │
│   [ Observers (Personnel/Emp) ] ─► [ Cache::forget('emp_custom_id:{$cId}') ]    │
│                                                                                  │
│   Task 6.11: Settings & Alert Invalidation Engine                                │
│   [ GET /api/settings/public ] ──► [ Cache::remember('settings.public', 3600) ]  │
│   [ PUT /api/settings ] ─────────► [ Cache::forget('settings.public') ]          │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

### Task 6.5: Eliminate $O(N)$ Database Queries in `Employee::isRestDay` Inside Summary Loop

#### A. Code Location & Existing Implementation
- **Files:** `app/Http/Controllers/EmployeeController.php` (lines 262–269), `app/Models/Employee.php` (lines 196–235)
- **Current Code in `EmployeeController.php`:**
```php
262:         $totalWorkingDays = 0;
263:         $current = $from->copy();
264:         while ($current->lte($to)) {
265:             if (!$employee->isRestDay($current) && !$employee->isHoliday($current)) {
266:                 $totalWorkingDays++;
267:             }
268:             $current->addDay();
269:         }
```
- **Current Code in `Employee.php` (`isRestDay`):**
```php
196:     public function isRestDay(Carbon|string $date): bool
197:     {
198:         $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
199:         $dateStr = $carbon->toDateString();
...
205:         $assignment = $this->shiftAssignments()
206:             ->whereDate('effective_from', '<=', $dateStr)
207:             ->where(function ($query) use ($dateStr) {
208:                 $query->whereNull('effective_to')
209:                       ->orWhereDate('effective_to', '>=', $dateStr);
210:             })
211:             ->orderBy('effective_from', 'desc')
212:             ->orderBy('id', 'desc')
213:             ->first();
...
```

#### B. Architectural & Algorithmic Problem
1. While `$employee->isHoliday($current)` delegates to `AttendanceProcessingService::isHoliday()` which already caches all holidays for the year in Redis (`holidays_{$year}` with 1-hour TTL), `$employee->isRestDay($current)` makes a fresh database query to `shift_assignments` on every single iteration of the while loop.
2. For a typical monthly attendance summary (30–31 days), this executes **31 separate SQL queries** per employee summary call.
3. The SQL query also wraps columns in non-SARGable `whereDate('effective_from', ...)` and `whereDate('effective_to', ...)`, preventing effective index range scans.
4. If a payroll or HR officer views attendance summaries for multiple employees or across a quarterly period (90 days), the query volume scales linearly to hundreds of queries, saturating PostgreSQL connections.

#### C. Proposed Refactoring Specification
1. **In `app/Models/Employee.php`:**
   Modify `isRestDay()` signature to accept an optional pre-fetched collection or iterable:
   `public function isRestDay(Carbon|string $date, ?iterable $preloadedAssignments = null): bool`
   - If `$preloadedAssignments !== null` OR `$this->relationLoaded('shiftAssignments')`:
     Filter from in-memory collection without hitting the database:
     ```php
     $assignments = $preloadedAssignments ?? $this->shiftAssignments;
     $assignment = $assignments
         ->filter(function ($a) use ($dateStr) {
             $from = is_string($a->effective_from) ? $a->effective_from : $a->effective_from?->toDateString();
             $to = is_string($a->effective_to) ? $a->effective_to : $a->effective_to?->toDateString();
             return $from <= $dateStr && ($to === null || $to >= $dateStr);
         })
         ->sortByDesc(fn($a) => [$a->effective_from, $a->id])
         ->first();
     ```
   - If no preloaded collection is provided and relation is not loaded: Fall back to existing database query so all external calls and unit tests retain 100% backward compatibility.
2. **In `app/Http/Controllers/EmployeeController.php`:**
   Before entering the `while ($current->lte($to))` loop, execute a single SARGable range query to fetch all shift assignments overlapping `[$from, $to]`:
   ```php
   $shiftAssignments = $employee->shiftAssignments()
       ->where('effective_from', '<=', $to->toDateString())
       ->where(function ($q) use ($from) {
           $q->whereNull('effective_to')
             ->orWhere('effective_to', '>=', $from->toDateString());
       })
       ->orderBy('effective_from', 'desc')
       ->orderBy('id', 'desc')
       ->get();
   ```
   Pass `$shiftAssignments` to `$employee->isRestDay($current, $shiftAssignments)`.

---

### Task 6.6: Eliminate Linear $O(N \times M)$ Collection Scan and Large Outbox Pull in `DeviceController::audit()`

#### A. Code Location & Existing Implementation
- **File:** `app/Http/Controllers/DeviceController.php` (lines 506–514, 562–564)
- **Current Code in `DeviceController.php`:**
```php
506:         $localPersonnel = \App\Models\Personnel::select(['id', 'customize_id', 'name', 'person_type', 'gender', 'id_card', 'tel_num', 'photo_path'])
507:             ->orderBy('customize_id', 'asc')
508:             ->get();
509:         $syncTasks = \App\Models\SyncTask::where('device_id', $device->device_id)
510:             ->latest('updated_at')
511:             ->get()
512:             ->groupBy('personnel_id')
513:             ->map(fn($tasks) => $tasks->first());
...
562:         foreach ($cameraPersons as $cId => $cp) {
563:             $existsInLocal = $localPersonnel->contains('customize_id', $cId);
564:             if (!$existsInLocal) {
```

#### B. Architectural & Algorithmic Problem
1. **$O(N \times M)$ Quadratic Scan:**
   `$cameraPersons` is a collection of personnel registered on the camera hardware (size $M$, e.g. 5,000).
   `$localPersonnel` is a collection of personnel in the local database (size $N$, e.g. 5,000).
   In line 563: `$localPersonnel->contains('customize_id', $cId)` traverses the collection sequentially from index 0 until a match is found. For untracked or tail entries, this performs $N$ comparisons per camera person, resulting in up to **25,000,000 item comparisons** in a single synchronous PHP thread, consuming seconds of CPU time.
2. **Unbounded Historical Outbox Pull:**
   In line 509–513:
   `\App\Models\SyncTask::where('device_id', $device->device_id)->latest('updated_at')->get()` pulls **every historical sync task ever generated for this device**.
   In a production fleet operating over months, a camera accumulates 50,000 to 200,000 sync tasks. Loading all rows into memory and hydrating Eloquent model instances causes severe memory spikes (100MB+ per audit call) and eventual memory exhaustion (`Allowed memory size exhausted`).

#### C. Proposed Refactoring Specification
1. **Constant-Time $O(1)$ Hash Map Lookup:**
   Key local personnel by `customize_id` immediately after retrieval:
   ```php
   $localPersonnelKeyed = $localPersonnel->keyBy('customize_id');
   ```
   In the loop (line 563):
   Replace `$localPersonnel->contains('customize_id', $cId)` with:
   `$existsInLocal = $localPersonnelKeyed->has($cId);` (or `isset($localPersonnelKeyed[$cId])`).
   Total comparison complexity drops from $O(N \times M)$ to $O(N + M)$.
2. **SQL Deduplication of Outbox Sync Tasks:**
   Fetch only the most recent sync task per personnel for the device directly in SQL, avoiding loading historical task logs.
   - Standard SQL Subquery (100% portable across PostgreSQL and SQLite):
     ```php
     $latestTaskIds = \App\Models\SyncTask::where('device_id', $device->device_id)
         ->whereNotNull('personnel_id')
         ->groupBy('personnel_id')
         ->selectRaw('MAX(id)');

     $syncTasks = \App\Models\SyncTask::whereIn('id', $latestTaskIds)
         ->get()
         ->keyBy('personnel_id');
     ```
   - PostgreSQL Driver Check (leveraging `DISTINCT ON (personnel_id)`):
     ```php
     if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
         $syncTasks = \App\Models\SyncTask::where('device_id', $device->device_id)
             ->selectRaw('DISTINCT ON (personnel_id) *')
             ->orderBy('personnel_id')
             ->orderByDesc('updated_at')
             ->orderByDesc('id')
             ->get()
             ->keyBy('personnel_id');
     } else {
         $latestTaskIds = \App\Models\SyncTask::where('device_id', $device->device_id)
             ->whereNotNull('personnel_id')
             ->groupBy('personnel_id')
             ->selectRaw('MAX(id)');
         $syncTasks = \App\Models\SyncTask::whereIn('id', $latestTaskIds)
             ->get()
             ->keyBy('personnel_id');
     }
     ```
   Memory usage is strictly bounded to $O(P)$ (active personnel count on device) rather than $O(T)$ (all historical sync tasks).

---

### Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`

#### A. Code Location & Existing Implementation
- **File:** `app/Http/Controllers/DeviceAlertController.php` (lines 110–128)
- **Current Code:**
```php
111:         $updateData = ['status' => $validated['status']];
112:         if ($validated['status'] === 'RESOLVED' || $validated['status'] === 'ACKNOWLEDGED') {
113:             $updateData['resolved_at'] = now();
114:             $updateData['resolved_by'] = auth()->id();
115:         }
116: 
117:         $alerts = DeviceAlert::whereIn('id', $validated['ids'])->get();
118:         foreach ($alerts as $alert) {
119:             $previousStatus = $alert->status;
120:             $alert->update($updateData);
121:             broadcast(new DeviceAlertUpdated($alert, $previousStatus));
122:         }
```

#### B. Architectural & Algorithmic Problem
1. When security or operations personnel bulk acknowledge or resolve alerts (e.g., 200 alerts during a drill or incident), the controller executes **200 separate SQL UPDATE queries** sequentially inside the `foreach` loop.
2. This creates row-level lock contention on `device_alerts` and saturates the database connection pool.
3. Crucially, neither `updateStatus()` nor `bulkUpdateStatus()` invalidates the cached statistics (`device_alert_stats` and `dashboard_telemetry_stats`), meaning the web dashboard displays stale alert metrics until the 5-second TTL expires.

#### C. Proposed Refactoring Specification
1. **Single Atomic Bulk SQL Update:**
   ```php
   $updateData['updated_at'] = now();
   DeviceAlert::whereIn('id', $validated['ids'])->update($updateData);
   ```
2. **In-Memory Broadcast Dispatch:**
   Update the in-memory `$alerts` collection and dispatch `DeviceAlertUpdated` events without issuing any further database writes. Since `DeviceAlertUpdated` implements `ShouldBroadcast` onto Redis queue `broadcasts` (established in Phase 3), queuing events is non-blocking:
   ```php
   foreach ($alerts as $alert) {
       $previousStatus = $alert->status;
       $alert->status = $validated['status'];
       if (isset($updateData['resolved_at'])) {
           $alert->resolved_at = $updateData['resolved_at'];
           $alert->resolved_by = $updateData['resolved_by'];
       }
       broadcast(new DeviceAlertUpdated($alert, $previousStatus));
   }
   ```
3. **Cache Invalidation:**
   Immediately purge both cached statistics:
   ```php
   \Illuminate\Support\Facades\Cache::forget('device_alert_stats');
   \Illuminate\Support\Facades\Cache::forget('dashboard_telemetry_stats');
   ```

---

### Task 6.8: Eliminate Blocking Redis `KEYS` Command in Bulk Shift Assignment

#### A. Code Location & Existing Implementation
- **File:** `app/Http/Controllers/ShiftController.php` (lines 298–312)
- **Current Code:**
```php
298:             // Invalidate employee shift cache
299:             foreach ($employeeIds as $employeeId) {
300:                 $cachePattern = "emp_shift:{$employeeId}:*";
301:                 try {
302:                     if (config('cache.default') === 'redis') {
303:                         $redis = \Illuminate\Support\Facades\Redis::connection();
304:                         $prefix = config('database.redis.options.prefix', '');
305:                         $keys = $redis->keys($prefix . $cachePattern);
306:                         foreach ($keys as $key) {
307:                             $redis->del(str_replace($prefix, '', $key));
308:                         }
309:                     }
310:                 } catch (\Throwable $e) {
311:                     // Ignore cache errors
312:                 }
313:             }
```

#### B. Architectural & Algorithmic Problem
1. **Blocking Single-Threaded Redis Execution:**
   The Redis `KEYS` command is an $O(K)$ operation where $K$ is the **total number of keys across the entire Redis database**.
   Because Redis is single-threaded, `KEYS` freezes the entire Redis server while scanning keys.
   Running `$redis->keys(...)` inside a `foreach ($employeeIds as $employeeId)` loop for a department of 500 employees executes **500 sequential full-database scans**.
2. **Impact on Real-Time Architecture:**
   While Redis is blocked, background queue workers (`queue:work`), Laravel Horizon, Laravel Reverb WebSockets, and MQTT ingestion daemons cannot read or write to Redis, causing queue stalls, dropped WebSocket connections, and ingestion latency.

#### C. Proposed Refactoring Specification
Eliminate all calls to `$redis->keys()`. Implement a non-blocking dual invalidation strategy:
1. **Versioned Key Counters (`emp_shift_v:{$employeeId}`):**
   - In `ShiftController::performShiftAssignment()`:
     Eviction becomes an atomic $O(1)$ command:
     ```php
     Cache::increment("emp_shift_v:{$employeeId}");
     ```
     `INCR` executes in less than 0.05 milliseconds in Redis and never blocks other clients.
2. **Tracked Active Keys Set (`emp_shift_keys:{$employeeId}`):**
   - In `AttendanceProcessingService::resolveEffectiveShift()`:
     Whenever a key `emp_shift:{$employee->id}:{$dateStr}` is cached, track the key in a local set:
     ```php
     $cacheKey = "emp_shift:{$employee->id}:{$dateStr}";
     $tracked = Cache::get("emp_shift_keys:{$employee->id}", []);
     if (!in_array($cacheKey, $tracked, true)) {
         $tracked[] = $cacheKey;
         Cache::put("emp_shift_keys:{$employee->id}", $tracked, 86400);
     }
     ```
   - In `ShiftController.php`:
     Directly delete the tracked keys without any pattern matching:
     ```php
     foreach ($employeeIds as $employeeId) {
         try {
             Cache::increment("emp_shift_v:{$employeeId}");
             $keys = Cache::get("emp_shift_keys:{$employeeId}", []);
             foreach ($keys as $k) {
                 Cache::forget($k);
             }
             Cache::forget("emp_shift_keys:{$employeeId}");
         } catch (\Throwable $e) {}
     }
     ```
   This guarantees that neither Redis nor SQLite/array cache drivers encounter `KEYS` commands, ensuring zero thread blocking under high concurrency.

---

### Task 6.9: Cache Pre-Enrolled Device Existence in High-Frequency MQTT Telemetry Stream

#### A. Code Location & Existing Implementation
- **File:** `app/Console/Commands/MqttListenCommand.php`
  - `handleVerifyPush` (lines 243–252)
  - `handleStrangerSnapPush` (lines 333–342)
  - `handleDeviceAlert` (lines 410–419)
- **Current Code Pattern across All Three Handlers:**
```php
243:         $device = Device::firstOrCreate(
244:             ['device_id' => $deviceId],
245:             ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
246:         );
247: 
248:         $throttleKey = "device_hb_throttle:{$deviceId}";
249:         if (!Cache::has($throttleKey)) {
250:             $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
251:             Cache::put($throttleKey, true, 60);
252:         }
```

#### B. Architectural & Algorithmic Problem
1. In high-density turnstile environments, cameras stream verification logs at 50–100+ events per second.
2. Although heartbeat updates were throttled to once per 60 seconds (Phase 3 Task 3.1), `Device::firstOrCreate(['device_id' => $deviceId], ...)` is still called **on every single incoming packet**.
3. `firstOrCreate` issues an SQL query:
   `SELECT * FROM "devices" WHERE "device_id" = ? LIMIT 1`
   Every single verification, stranger snapshot, and device alert checks the database.
4. Crucially, after line 252, `$device` is **not used anywhere else** in `handleVerifyPush`, `handleStrangerSnapPush`, or `handleDeviceAlert`. `AccessLog::create()`, `StrangerSnap::create()`, and `DeviceAlert::create()` all consume the string `$deviceId` directly!
5. As a result, 99% of `Device::firstOrCreate` calls are completely redundant overhead.

#### C. Proposed Refactoring Specification
Introduce a fast Redis cache key for pre-enrolled device existence:
`device_registered:{$deviceId}` with 600s (10-minute) TTL.
Implement a helper method `touchDeviceHeartbeat()` or `ensureDeviceRegistered()`:
```php
protected function touchDeviceHeartbeat(string $deviceId, array $attributes = []): void
{
    $regKey = "device_registered:{$deviceId}";
    $throttleKey = "device_hb_throttle:{$deviceId}";

    $isRegistered = Cache::has($regKey);
    $needsHeartbeat = !Cache::has($throttleKey);

    // Fast exit: device existence confirmed and heartbeat is already throttled
    if ($isRegistered && !$needsHeartbeat) {
        return; // ZERO database operations!
    }

    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        array_merge(['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true], $attributes)
    );
    Cache::put($regKey, true, 600);

    if ($needsHeartbeat) {
        $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
        Cache::put($throttleKey, true, 60);
    }
}
```
In `handleVerifyPush`, `handleStrangerSnapPush`, and `handleDeviceAlert`, replace the 10-line `firstOrCreate` + throttle block with:
`$this->touchDeviceHeartbeat($deviceId);`
This reduces database load from 100 queries/sec to 1 query every 60 seconds per camera (>98% reduction).

---

### Task 6.10: Cache Biometric `customize_id` to Employee Mapping in Punch Ingestion

#### A. Code Location & Existing Implementation
- **Files:** `app/Jobs/ProcessAttendancePunchJob.php` (lines 31–46), `app/Observers/EmployeeObserver.php`, `app/Observers/PersonnelObserver.php`
- **Current Code in `ProcessAttendancePunchJob.php`:**
```php
31:         // Find linked employee via customize_id or personnel_id
32:         $personnel = null;
33:         if ($this->accessLog->customize_id) {
34:             $personnel = Personnel::where('customize_id', $this->accessLog->customize_id)->first();
35:         }
36: 
37:         $employee = null;
38:         if ($personnel) {
39:             $employee = Employee::where('personnel_id', $personnel->id)->first();
40:         }
41: 
42:         if (!$employee && $this->accessLog->customize_id) {
43:             $employee = Employee::where('employee_code', (string) $this->accessLog->customize_id)
44:                 ->orWhere('id', $this->accessLog->customize_id)
45:                 ->first();
46:         }
```

#### B. Architectural & Algorithmic Problem
1. For every punch event processed asynchronously, `ProcessAttendancePunchJob` executes 2 to 3 sequential database queries to map the hardware `customize_id` to the internal `Employee` record:
   - Query 1: `SELECT * FROM personnel WHERE customize_id = ? LIMIT 1`
   - Query 2: `SELECT * FROM employees WHERE personnel_id = ? LIMIT 1`
   - Query 3 (if unlinked): `SELECT * FROM employees WHERE employee_code = ? OR id = ? LIMIT 1`
2. During the morning clock-in rush (10,000 punches), worker queues process these jobs concurrently, executing **20,000 to 30,000 database queries** purely to resolve the employee identity.
3. Because biometric personnel linkage is static (changes only when employees or personnel cards are modified), running multiple database lookups for every clock-in is completely wasteful.

#### C. Proposed Refactoring Specification
1. **Cache Identity Bridge in Redis:**
   Cache `emp_custom_id:{$customizeId}` with 1-hour TTL (3600 seconds) returning `$employeeId`.
   In `ProcessAttendancePunchJob.php`:
   ```php
   $employee = null;
   $customizeId = $this->accessLog->customize_id;
   if ($customizeId) {
       $employeeId = \Illuminate\Support\Facades\Cache::remember("emp_custom_id:{$customizeId}", 3600, function () use ($customizeId) {
           $personnel = Personnel::where('customize_id', $customizeId)->first();
           if ($personnel) {
               $emp = Employee::where('personnel_id', $personnel->id)->first();
               if ($emp) {
                   return $emp->id;
               }
           }
           $fallback = Employee::where('employee_code', (string) $customizeId)
               ->orWhere('id', $customizeId)
               ->first();
           return $fallback?->id;
       });

       if ($employeeId) {
           $employee = Employee::find($employeeId);
       }
   }
   ```
2. **Bidirectional Invalidation Hooks:**
   - In `app/Observers/EmployeeObserver.php`:
     On `saved(Employee $employee)` and `deleting(Employee $employee)`:
     ```php
     if ($employee->personnel?->customize_id) {
         Cache::forget("emp_custom_id:{$employee->personnel->customize_id}");
     }
     if (is_numeric($employee->employee_code)) {
         Cache::forget("emp_custom_id:{$employee->employee_code}");
     }
     Cache::forget("emp_custom_id:{$employee->id}");
     ```
   - In `app/Observers/PersonnelObserver.php`:
     On `created(Personnel $personnel)`, `updated(Personnel $personnel)`, and `deleting(Personnel $personnel)`:
     ```php
     if ($personnel->customize_id) {
         Cache::forget("emp_custom_id:{$personnel->customize_id}");
     }
     ```
   This ensures instant cache invalidation upon any workforce or badge mutation while reducing punch worker query overhead to a single fast primary key fetch (`Employee::find($id)`).

---

### Task 6.11: Cache Invalidation Engine for Device Alerts & Public Settings

#### A. Code Location & Existing Implementation
- **Files:** `app/Http/Controllers/DeviceAlertController.php` (lines 48, 96, 120), `app/Http/Controllers/SettingController.php` (lines 30–45, 50–89), `app/Services/SettingService.php` (lines 60–89, 121–140)
- **Current Code in `SettingController.php` (`publicSettings`):**
```php
30:     public function publicSettings(): JsonResponse
31:     {
32:         $publicSettings = Setting::whereNull('organization_id')
33:             ->where('is_public', true)
34:             ->get();
35: 
36:         $data = [];
37:         foreach ($publicSettings as $setting) {
38:             $data[$setting->key] = $setting->casted_value;
39:         }
40: 
41:         return response()->json([
42:             'success' => true,
43:             'data' => $data,
44:         ]);
45:     }
```

#### B. Architectural & Algorithmic Problem
1. **Uncached Public Settings Endpoint:**
   `publicSettings()` is called unauthenticated by web and mobile clients during initial app load, login page rendering, and tenant branding initialization. Currently, it executes an uncached database query on every page load.
2. **Stale Alert Statistics on Dashboard:**
   `DeviceAlertController::stats()` caches `device_alert_stats` for 5 seconds.
   `DashboardStatsController::index()` caches `dashboard_telemetry_stats` for 5 seconds (which embeds unacknowledged and critical alert counts).
   When an operator changes alert status via `updateStatus()` or `bulkUpdateStatus()`, neither cache key is invalidated. The dashboard UI continues displaying stale counters until TTL expiration.

#### C. Proposed Refactoring Specification
1. **Cache Public Branding Settings:**
   In `SettingController::publicSettings()`, wrap results in 1-hour Redis cache (`settings.public`):
   ```php
   $data = \Illuminate\Support\Facades\Cache::remember('settings.public', 3600, function () {
       $publicSettings = Setting::whereNull('organization_id')
           ->where('is_public', true)
           ->get();

       $data = [];
       foreach ($publicSettings as $setting) {
           $data[$setting->key] = $setting->casted_value;
       }
       return $data;
   });
   ```
2. **Setting Mutation Invalidation:**
   In `SettingService::set()`, `SettingService::reset()`, and `SettingController::update()`:
   Add `Cache::forget('settings.public');` to ensure branding changes appear immediately.
3. **Alert Status Invalidation:**
   In `DeviceAlertController::updateStatus()` and `DeviceAlertController::bulkUpdateStatus()`:
   Add:
   ```php
   Cache::forget('device_alert_stats');
   Cache::forget('dashboard_telemetry_stats');
   ```

---

## 3. Cross-Cutting Cache Invalidation Matrix

The following table summarizes all cache keys, TTL configurations, mutation triggers, and invalidation mechanisms across Tasks 6.5 through 6.11:

| Cache Key Pattern | TTL | Source Generator | Invalidation Trigger | Invalidation Mechanism | Task Code |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `emp_shift_v:{$employeeId}` | Persistent | `ShiftController` | Shift assignment / unassignment | Atomic `Cache::increment("emp_shift_v:{$id}")` | Task 6.8 |
| `emp_shift_keys:{$employeeId}` | 24 Hours | `AttendanceProcessingService` | Shift assignment / unassignment | Iterative `Cache::forget($k)` + `Cache::forget(set)` | Task 6.8 |
| `device_registered:{$deviceId}` | 10 Minutes | `MqttListenCommand` | Device deletion or deactivation | TTL auto-eviction / `DeviceObserver::deleting` | Task 6.9 |
| `device_hb_throttle:{$deviceId}`| 60 Seconds | `MqttListenCommand` / Webhook | Heartbeat packet ingestion | 60s TTL throttle lock | Task 6.9 |
| `emp_custom_id:{$customizeId}` | 1 Hour | `ProcessAttendancePunchJob` | Personnel / Employee edit / delete | `EmployeeObserver` & `PersonnelObserver` hooks | Task 6.10 |
| `device_alert_stats` | 5 Seconds | `DeviceAlertController::stats` | Alert status mutation | `Cache::forget('device_alert_stats')` | Task 6.11 / 6.7 |
| `dashboard_telemetry_stats` | 5 Seconds | `DashboardStatsController::index`| Alert status mutation | `Cache::forget('dashboard_telemetry_stats')` | Task 6.11 / 6.7 |
| `settings.public` | 1 Hour | `SettingController::publicSettings`| Setting update / reset | `Cache::forget('settings.public')` | Task 6.11 |

---

## 4. Test Strategy & Verification Methods

All proposed optimizations can be verified against the project test suite (`tests/Feature/PerformanceOptimizationTest.php`) and full regression test suite (`php artisan test`).

### Required Dedicated Test Methods in `PerformanceOptimizationTest.php`

1. **`test_employee_is_rest_day_prefetches_shift_assignments_in_range` (Task 6.5):**
   - Seed employee with assigned days (e.g. Mon–Fri).
   - Mock DB listener to count queries on `shift_assignments`.
   - Call `EmployeeController::attendanceSummary` across a 30-day window.
   - Assert query count on `shift_assignments` is exactly 1 (not 30+).
   - Assert working days and rest days are calculated accurately.

2. **`test_device_audit_uses_hashmap_lookup_and_deduplicated_sync_tasks` (Task 6.6):**
   - Seed 10 personnel and 50 historical sync tasks for a device (multiple tasks per person).
   - Call `DeviceController::audit($device->id)`.
   - Assert response accurately keys sync tasks to personnel without loading all 50 tasks into memory.
   - Assert untracked edge camera persons are reconciled in $O(1)$ time without collection linear scanning errors.

3. **`test_device_alert_bulk_update_status_batches_query_and_invalidates_cache` (Task 6.7 & 6.11):**
   - Seed 5 `DeviceAlert` records with status `'NEW'`.
   - Seed cache keys `'device_alert_stats'` and `'dashboard_telemetry_stats'`.
   - Call `POST /api/device-alerts/bulk-status` with `status: 'RESOLVED'`.
   - Assert all 5 records are updated in database.
   - Assert cache keys `'device_alert_stats'` and `'dashboard_telemetry_stats'` were evicted (`Cache::has` is false).

4. **`test_bulk_shift_assignment_does_not_execute_redis_keys` (Task 6.8):**
   - Seed multiple employees and call shift bulk assignment.
   - Assert that no `KEYS` command is issued.
   - Assert that `emp_shift_v:{$employeeId}` counter is incremented and active keys are evicted.

5. **`test_mqtt_listener_caches_device_registration_to_avoid_redundant_queries` (Task 6.9):**
   - Dispatch simulated MQTT telemetry payload for a device.
   - Assert `device_registered:{$deviceId}` is stored in cache with 600s TTL.
   - Send subsequent packet within throttle window and assert zero queries executed against `devices`.

6. **`test_process_attendance_punch_job_caches_biometric_mapping` (Task 6.10):**
   - Create Personnel, Employee, and AccessLog.
   - Dispatch `ProcessAttendancePunchJob`.
   - Assert `emp_custom_id:{$customizeId}` is stored in cache.
   - Update Employee/Personnel and assert cache key is purged.

7. **`test_public_settings_caching_and_invalidation` (Task 6.11):**
   - Call `GET /api/settings/public`.
   - Assert response is cached under `settings.public`.
   - Call `PUT /api/settings` and assert `settings.public` is purged.

---

## 5. Risk Assessment & Implementation Preconditions

1. **Backwards Compatibility of `Employee::isRestDay`:**
   Single-day calls throughout tests (`$employee->isRestDay('2026-10-05')`) must continue to work seamlessly. Providing `$preloadedAssignments = null` as a default parameter preserves complete backward compatibility.
2. **Cross-Database SQL Portability in Device Audit:**
   The test environment uses SQLite in `:memory:`, while production uses PostgreSQL 16. Using the subquery `whereIn('id', $latestIds)` or checking `DB::getDriverName() === 'pgsql'` guarantees clean execution in both environments without SQLite syntax errors.
3. **Cache Driver Flexibility:**
   While production uses Redis (`CACHE_STORE=redis`), testing runs with `CACHE_STORE=array`. Cache key implementations must rely on Laravel's `Cache` facade abstractions rather than direct `$redis` calls wherever possible.

---

## 6. Next Steps for Implementation Agents

1. Implement Task 6.5 (`Employee.php` and `EmployeeController.php`).
2. Implement Task 6.6 (`DeviceController.php`).
3. Implement Task 6.7 (`DeviceAlertController.php`).
4. Implement Task 6.8 (`ShiftController.php` and `AttendanceProcessingService.php`).
5. Implement Task 6.9 (`MqttListenCommand.php`).
6. Implement Task 6.10 (`ProcessAttendancePunchJob.php`, `EmployeeObserver.php`, `PersonnelObserver.php`).
7. Implement Task 6.11 (`DeviceAlertController.php`, `SettingController.php`, `SettingService.php`).
8. Add corresponding unit and feature assertions in `tests/Feature/PerformanceOptimizationTest.php`.
9. Run test suite: `php artisan test --filter=PerformanceOptimizationTest` and `php artisan test`.
