# Comprehensive Survey Report: Frontend Real-Time & Test Infrastructure (Phase 6)

**Agent:** Explorer 3 (`teamwork_preview_explorer`)  
**Scope:** Phase 6 Tasks 6.12 – 6.13 and Full Test Infrastructure Verification  
**Repository:** `whoamikenken/AI-Camera-Integration`  
**Date:** 2026-10-07  

---

## 1. Executive Summary

This investigation explores the frontend real-time telemetry architecture and test verification framework for **Phase 6 Performance & Optimization** tasks defined in `tasks-performance.md`.

### Core Findings
1. **Task 6.12 (Echo Channel Type Mismatch in `DeviceAlertsCenter.vue`)**:
   - The backend broadcast events `DeviceAlertReceived` and `DeviceAlertUpdated` broadcast strictly on authenticated private channels: `new PrivateChannel('device-alerts')` and `new PrivateChannel('alerts')`.
   - `routes/channels.php` registers an authorization callback specifically on `device-alerts` requiring `'devices.view'`, `'devices.manage'`, or role `'security'`.
   - In `resources/js/views/DeviceAlertsCenter.vue:732, 740`, the component erroneously subscribes via `echo.channel('device-alerts')` (public channel).
   - In Laravel Reverb / Pusher protocol, public subscribers listen to the unadorned channel name `device-alerts`, whereas private broadcasts are prefixed as `private-device-alerts`. Consequently, **`DeviceAlertsCenter.vue` never receives live alert pushes**, dropping telemetry events silently.
   - Cross-component finding: `PersonnelManager.vue` and `SyncTasksMonitor.vue` suffer from the exact same pattern (`echo.channel('personnel')` and `echo.channel('sync-tasks')`), whereas `App.vue` correctly subscribes to private channels (`echo.private(...)`).

2. **Task 6.13 (Metric Binding in `attendanceStore.js`)**:
   - The backend endpoint `/api/attendance/daily` (`AttendanceController::daily()`) executes a high-performance single-pass SQL conditional aggregation and returns a JSON payload containing `date`, `summary`, `records`, and `data`.
   - The `summary` object contains pre-aggregated workforce KPIs: `total`, `present`, `late`, `early_out`, `absent`, `half_day`, `on_leave`, `holiday`.
   - In `resources/js/stores/attendanceStore.js:70, 80`, the action checks `if (data.stats)` — which is `undefined`.
   - Because `data.stats` is undefined, `if (!data.stats)` evaluates to `true`, triggering `computeLocalStats()` on `this.dailyRoster`.
   - `this.dailyRoster` holds only the paginated slice of the current page (e.g. 50 items). As a result, **workforce summary metrics in `AttendanceDashboard.vue` (Total Scheduled, Present, Rate, etc.) are computed only over the first 50 employees rather than the full workforce**, causing massive KPI desynchronization when paginated.

3. **Test Infrastructure & Verification**:
   - `tests/Feature/PerformanceOptimizationTest.php` already exists (876 lines), covering Tasks 1.1 through 4.4. All 22 existing performance tests pass cleanly (119 assertions, 565ms).
   - The entire project test suite passes cleanly: **360 tests, 358 passed, 0 failures, 2 skipped, 1472 assertions** (`php artisan test`).
   - PHPUnit testing runs under `sqlite` in-memory (`:memory:`), `array` cache driver, and `sync` queue driver (`phpunit.xml`).
   - Phase 6 tasks (6.1 through 6.13) need dedicated test methods in `tests/Feature/PerformanceOptimizationTest.php`.
   - Frontend build (`npm run build`) builds cleanly with zero errors in 843ms via Vite 8.2.2.

---

## 2. Task 6.12 Deep Dive: Echo Channel Type Mismatch

### 2.1 Backend Broadcast Implementation
Inspecting `app/Events/DeviceAlertReceived.php`:
```php
// Lines 21-27
public function broadcastOn(): array
{
    return [
        new PrivateChannel('device-alerts'),
        new PrivateChannel('alerts'),
    ];
}

public function broadcastAs(): string
{
    return 'DeviceAlertReceived';
}
```

Inspecting `app/Events/DeviceAlertUpdated.php`:
```php
// Lines 23-29
public function broadcastOn(): array
{
    return [
        new PrivateChannel('device-alerts'),
        new PrivateChannel('alerts'),
    ];
}

public function broadcastAs(): string
{
    return 'DeviceAlertUpdated';
}
```

### 2.2 Channel Authorization in `routes/channels.php`
```php
// Lines 13-15
Broadcast::channel('device-alerts', function ($user) {
    return $user->hasRole('security') || $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);
```

### 2.3 Frontend Bug in `resources/js/views/DeviceAlertsCenter.vue`
```javascript
// Lines 727-745 of resources/js/views/DeviceAlertsCenter.vue
onMounted(() => {
  fetchAlertStats();
  fetchAlerts(1);

  // BUG: echo.channel creates a PublicChannel subscription ("device-alerts")
  echo.channel('device-alerts')
    .listen('.DeviceAlertReceived', handleLiveAlertReceived)
    .listen('DeviceAlertReceived', handleLiveAlertReceived)
    .listen('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .listen('DeviceAlertUpdated', handleLiveAlertUpdated);
});

onUnmounted(() => {
  echo.channel('device-alerts')
    .stopListening('.DeviceAlertReceived')
    .stopListening('DeviceAlertReceived')
    .stopListening('.DeviceAlertUpdated')
    .stopListening('DeviceAlertUpdated');
});
```

### 2.4 Why This Fails in Laravel Reverb
1. Under Pusher/Reverb protocol, when a backend event broadcasts on `new PrivateChannel('device-alerts')`, the transport channel is named `private-device-alerts`.
2. Echo's `echo.channel('device-alerts')` registers a listener on the public channel `device-alerts` without initiating authorization via `/broadcasting/auth`.
3. Reverb delivers the frame only to connections subscribed to `private-device-alerts`.
4. As a result, the browser client never receives `DeviceAlertReceived` or `DeviceAlertUpdated` events in `DeviceAlertsCenter.vue`.
5. In contrast, `App.vue:825-827` correctly subscribes:
   ```javascript
   echo.private("device-alerts")
       .listen(".DeviceAlertReceived", (e) => store.addDeviceAlert(e))
       .listen(".DeviceAlertUpdated", (e) => store.updateAlertStatus(e));
   ```

### 2.5 Recommended Proposed Fix for `DeviceAlertsCenter.vue`
Target: `resources/js/views/DeviceAlertsCenter.vue:731-745`

```javascript
<<<<
  // Real-time echo subscription for DeviceAlertReceived and DeviceAlertUpdated
  echo.channel('device-alerts')
    .listen('.DeviceAlertReceived', handleLiveAlertReceived)
    .listen('DeviceAlertReceived', handleLiveAlertReceived)
    .listen('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .listen('DeviceAlertUpdated', handleLiveAlertUpdated);
});

onUnmounted(() => {
  echo.channel('device-alerts')
    .stopListening('.DeviceAlertReceived')
    .stopListening('DeviceAlertReceived')
    .stopListening('.DeviceAlertUpdated')
    .stopListening('DeviceAlertUpdated');
});
====
  // Real-time echo subscription for DeviceAlertReceived and DeviceAlertUpdated
  echo.private('device-alerts')
    .listen('.DeviceAlertReceived', handleLiveAlertReceived)
    .listen('DeviceAlertReceived', handleLiveAlertReceived)
    .listen('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .listen('DeviceAlertUpdated', handleLiveAlertUpdated);
});

onUnmounted(() => {
  echo.private('device-alerts')
    .stopListening('.DeviceAlertReceived', handleLiveAlertReceived)
    .stopListening('DeviceAlertReceived', handleLiveAlertReceived)
    .stopListening('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .stopListening('DeviceAlertUpdated', handleLiveAlertUpdated);
});
>>>>
```

*Note on teardown:* Passing the callback reference `handleLiveAlertReceived` and `handleLiveAlertUpdated` to `stopListening()` ensures that when `DeviceAlertsCenter.vue` is unmounted, it does NOT accidentally detach the global listeners registered in `App.vue` on the shared Echo channel instance.

---

## 3. Task 6.13 Deep Dive: Metric Binding in `attendanceStore.js`

### 3.1 Backend Response Contract
In `app/Http/Controllers/AttendanceController.php:41-75`:
```php
// Count summary metrics via a single SQL conditional aggregation query
$summaryRecord = $summaryQuery->selectRaw("
    COUNT(*) as total,
    COUNT(CASE WHEN status IN ('present', 'late', 'early_out', 'late_and_early_out') THEN 1 END) as present,
    COUNT(CASE WHEN status IN ('late', 'late_and_early_out') THEN 1 END) as late,
    COUNT(CASE WHEN status IN ('early_out', 'late_and_early_out') THEN 1 END) as early_out,
    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
    COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day,
    COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as on_leave,
    COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holiday
")->first();

$summary = [
    'total' => (int) $summaryRecord->total,
    'present' => (int) $summaryRecord->present,
    'late' => (int) $summaryRecord->late,
    'early_out' => (int) $summaryRecord->early_out,
    'absent' => (int) $summaryRecord->absent,
    'half_day' => (int) $summaryRecord->half_day,
    'on_leave' => (int) $summaryRecord->on_leave,
    'holiday' => (int) $summaryRecord->holiday,
];

return response()->json([
    'date' => $date,
    'summary' => $summary,
    'records' => $paginated,
    'data' => $paginated->items(),
]);
```

### 3.2 Frontend Flaw in `resources/js/stores/attendanceStore.js`
```javascript
// Lines 63-82 of resources/js/stores/attendanceStore.js
const res = await apiClient.get('/attendance/daily', { params });
const data = res.data;

if (Array.isArray(data)) {
    this.dailyRoster = data;
} else if (data.roster) {
    this.dailyRoster = data.roster;
    if (data.stats) {
        this.stats = { ...this.stats, ...data.stats };
    }
} else if (data.data) {
    this.dailyRoster = data.data;
} else {
    this.dailyRoster = [];
}

// Compute local stats if not from server
if (!data.stats) {
    this.computeLocalStats();
}
```

```javascript
// Lines 91-108
computeLocalStats() {
    const total = this.dailyRoster.length;
    const present = this.dailyRoster.filter(r => ['present', 'late', 'early_out', 'late_and_early_out', 'half_day'].includes(r.status)).length;
    const absent = this.dailyRoster.filter(r => r.status === 'absent').length;
    const late = this.dailyRoster.filter(r => r.is_late || ['late', 'late_and_early_out'].includes(r.status)).length;
    const on_leave = this.dailyRoster.filter(r => r.status === 'on_leave').length;
    const early_out = this.dailyRoster.filter(r => r.is_early_out || ['early_out', 'late_and_early_out'].includes(r.status)).length;

    this.stats = {
        total_employees: total,
        present,
        absent,
        late,
        on_leave,
        early_out,
        attendance_rate: total > 0 ? Math.round((present / total) * 100) : 0,
    };
},
```

### 3.3 UI Impact on Components
`resources/js/components/attendance/AttendanceDashboard.vue:5-35` binds directly to `attendanceStore.stats`:
- `Total Scheduled`: `attendanceStore.stats.total_employees`
- `Present Today`: `attendanceStore.stats.present`
- `Attendance Rate`: `attendanceStore.stats.attendance_rate`
- `Absent`: `attendanceStore.stats.absent`
- `Late Arrivals`: `attendanceStore.stats.late`
- `On Leave`: `attendanceStore.stats.on_leave`
- `Early Out`: `attendanceStore.stats.early_out`

When `data.summary` is ignored and `computeLocalStats()` runs, `this.dailyRoster.length` is bounded by pagination (max 50). If the company has 500 employees, the dashboard displays:
- Total Scheduled: **50** (instead of 500)
- Present Today: **42** (instead of 430)
- Attendance Rate: calculated only on the current 50 items.

### 3.4 Recommended Proposed Fix for `attendanceStore.js`
Target: `resources/js/stores/attendanceStore.js:63-89`

```javascript
<<<<
                const res = await apiClient.get('/attendance/daily', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.dailyRoster = data;
                } else if (data.roster) {
                    this.dailyRoster = data.roster;
                    if (data.stats) {
                        this.stats = { ...this.stats, ...data.stats };
                    }
                } else if (data.data) {
                    this.dailyRoster = data.data;
                } else {
                    this.dailyRoster = [];
                }

                // Compute local stats if not from server
                if (!data.stats) {
                    this.computeLocalStats();
                }
====
                const res = await apiClient.get('/attendance/daily', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.dailyRoster = data;
                } else if (data.data) {
                    this.dailyRoster = data.data;
                } else if (data.roster) {
                    this.dailyRoster = data.roster;
                } else {
                    this.dailyRoster = [];
                }

                // Sync pagination if provided
                if (data.records && typeof data.records === 'object') {
                    this.pagination = {
                        current_page: data.records.current_page || 1,
                        last_page: data.records.last_page || 1,
                        per_page: data.records.per_page || 50,
                        total: data.records.total || 0,
                    };
                }

                // Bind summary metrics from server if provided, fallback to local compute
                const summary = data.summary || data.stats;
                if (summary) {
                    const total = Number(summary.total ?? summary.total_employees ?? 0);
                    const present = Number(summary.present ?? 0);
                    this.stats = {
                        total_employees: total,
                        present: present,
                        absent: Number(summary.absent ?? 0),
                        late: Number(summary.late ?? 0),
                        on_leave: Number(summary.on_leave ?? 0),
                        early_out: Number(summary.early_out ?? 0),
                        half_day: Number(summary.half_day ?? 0),
                        holiday: Number(summary.holiday ?? 0),
                        attendance_rate: total > 0 ? Math.round((present / total) * 100) : 0,
                    };
                } else {
                    this.computeLocalStats();
                }
>>>>
```

---

## 4. Test Infrastructure & Verification Architecture

### 4.1 Current Test Suite Status
- **Test Runner:** PHPUnit 11+ via `php artisan test`
- **Environment:**
  - `DB_CONNECTION`: `sqlite` (`:memory:`)
  - `CACHE_STORE`: `array`
  - `QUEUE_CONNECTION`: `sync`
  - `BROADCAST_CONNECTION`: `null`
- **Full Suite Execution:**
  - `php artisan test`: **360 tests, 358 passed, 0 failures, 2 skipped, 1472 assertions in 62s**.
- **Existing Performance Test:**
  - `php artisan test --filter=PerformanceOptimizationTest`: **22 tests, 22 passed, 119 assertions in 565ms**.
  - Covers Phase 1 (1.1–1.4), Phase 2 (2.1–2.8), Phase 3 (3.1–3.3), Phase 4 (4.1–4.4), and Phase 5 (5.4).

### 4.2 Proposed Test Suite for Phase 6 in `tests/Feature/PerformanceOptimizationTest.php`

To verify all Phase 6 tasks with regression-proof automated tests, the following 13 dedicated test methods should be added to `PerformanceOptimizationTest.php`:

| Task | Test Method Name | Verification Technique |
| :--- | :--- | :--- |
| **6.1** | `test_phase6_attendance_and_visitor_queries_use_sargable_ranges` | `DB::enableQueryLog()`, verify NO `where strftime` / `where date()` on `punch_time` and `expected_arrival`; assert `whereBetween` or `>=`/`<=` range clauses. |
| **6.2** | `test_phase6_composite_and_foreign_key_indexes_exist` | Inspect `PRAGMA index_list` on `access_logs`, `attendance_punches`, and `notifications`; assert composite index on `access_logs(device_id, captured_at DESC)`, `attendance_punches(device_id)`, and `notifications`. |
| **6.3** | `test_phase6_sync_tasks_dashboard_stats_query_filters_active_statuses` | `DB::enableQueryLog()`, call `GET /api/dashboard/stats`, assert query on `sync_tasks` includes `where "status" in ('PENDING', 'PROCESSING', 'FAILED')`. |
| **6.4** | `test_phase6_wide_read_endpoints_are_paginated_and_column_constrained` | Call `GET /api/leaves/balances`, `GET /api/organizations/locations`, `/departments`, `/designations`; assert `assertJsonStructure(['current_page', 'data', 'per_page', 'total'])` and employee relationship excludes `photo_base64`. |
| **6.5** | `test_phase6_employee_attendance_summary_prefetches_shifts_without_day_loop_queries` | `DB::enableQueryLog()`, call `GET /api/employees/{id}/attendance-summary?from_date=...&to_date=...` for 30 days. Assert queries on `employee_shift_assignments` is `<= 1` (not 30). |
| **6.6** | `test_phase6_device_audit_uses_hash_map_lookup_and_deduplicated_sync_tasks` | Seed device with personnel and sync tasks, call `POST /api/devices/{id}/audit`, assert correct discrepancy report and subquery/distinct sync task resolution. |
| **6.7** | `test_phase6_device_alert_bulk_update_executes_single_batch_query` | Seed 5 `DeviceAlert`s, call `POST /api/device-alerts/bulk-status` with 5 IDs, inspect query log, assert exactly **1** `UPDATE device_alerts` query is executed. |
| **6.8** | `test_phase6_bulk_shift_assignment_avoids_redis_keys_command` | Execute bulk shift assignment; verify versioned key counter or set invalidation without executing `KEYS`. |
| **6.9** | `test_phase6_mqtt_listen_caches_registered_device_existence` | Seed device, assert device check caches `device_registered:{$deviceId}`, assert second check hits cache without database query. |
| **6.10** | `test_phase6_attendance_punch_job_caches_biometric_customize_id_mapping` | Run `ProcessAttendancePunchJob`, assert `emp_custom_id:{$customizeId}` is cached, assert subsequent punch for same ID resolves with 0 personnel queries. |
| **6.11** | `test_phase6_alert_mutations_and_settings_invalidate_cache` | Assert `device_alert_stats` and `dashboard_telemetry_stats` are evicted on alert status updates; assert `settings.public` is cached and evicted on setting update. |
| **6.12** | `test_phase6_device_alerts_broadcast_on_private_channels` | Assert `DeviceAlertReceived::broadcastOn()` and `DeviceAlertUpdated::broadcastOn()` return `PrivateChannel('device-alerts')`; assert `routes/channels.php` defines authorization callback for `device-alerts`. |
| **6.13** | `test_phase6_attendance_daily_endpoint_returns_aggregated_summary_structure` | Call `GET /api/attendance/daily`, assert JSON structure contains `summary` with `total`, `present`, `late`, `early_out`, `absent`, `half_day`, `on_leave`, `holiday`. |

### 4.3 Proposed PHPUnit Test Code Snippet for Tasks 6.12 & 6.13
```php
/**
 * Task 6.12: Verify DeviceAlert broadcast events use PrivateChannel('device-alerts')
 * and routes/channels.php authorizer is present.
 */
public function test_phase6_device_alerts_broadcast_on_private_channels(): void
{
    $device = Device::create([
        'device_id' => 'CAM-TEST-612',
        'name' => 'Alert Channel Test Camera',
        'ip_address' => '192.168.1.150',
        'is_active' => true,
    ]);

    $alert = DeviceAlert::create([
        'device_id' => $device->device_id,
        'alert_type' => 'AREA_INTRUSION',
        'severity' => 'WARNING',
        'title' => 'Intrusion Alert',
        'status' => 'NEW',
        'captured_at' => now(),
    ]);

    $receivedEvent = new \App\Events\DeviceAlertReceived($alert);
    $channels = $receivedEvent->broadcastOn();
    $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channels[0]);
    $this->assertEquals('private-device-alerts', $channels[0]->name);

    $updatedEvent = new \App\Events\DeviceAlertUpdated($alert, 'NEW');
    $updatedChannels = $updatedEvent->broadcastOn();
    $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $updatedChannels[0]);
    $this->assertEquals('private-device-alerts', $updatedChannels[0]->name);
}

/**
 * Task 6.13: Verify AttendanceController::daily returns summary structure with accurate totals.
 */
public function test_phase6_attendance_daily_endpoint_returns_aggregated_summary_structure(): void
{
    $today = Carbon::today()->toDateString();

    $dept = Department::create([
        'organization_id' => $this->org->id,
        'name' => 'Engineering',
        'code' => 'ENG',
    ]);

    $emp1 = Employee::factory()->create([
        'organization_id' => $this->org->id,
        'department_id' => $dept->id,
        'employment_status' => 'active',
    ]);

    $emp2 = Employee::factory()->create([
        'organization_id' => $this->org->id,
        'department_id' => $dept->id,
        'employment_status' => 'active',
    ]);

    AttendanceRecord::create([
        'employee_id' => $emp1->id,
        'date' => $today,
        'status' => 'present',
    ]);

    AttendanceRecord::create([
        'employee_id' => $emp2->id,
        'date' => $today,
        'status' => 'absent',
    ]);

    $response = $this->getJson("/api/attendance/daily?date={$today}");
    $response->assertOk()
        ->assertJsonStructure([
            'date',
            'summary' => [
                'total',
                'present',
                'late',
                'early_out',
                'absent',
                'half_day',
                'on_leave',
                'holiday',
            ],
            'records',
            'data',
        ]);

    $summary = $response->json('summary');
    $this->assertEquals(2, $summary['total']);
    $this->assertEquals(1, $summary['present']);
    $this->assertEquals(1, $summary['absent']);
}
```

---

## 5. Frontend Build & Asset Compilation Verification

### 5.1 Build Infrastructure
- **Bundler:** Vite v8.2.2 with `@tailwindcss/vite` and `@vitejs/plugin-vue`
- **Output:** Entry and chunk files hashed with `-v6.js` suffix
- **Code-Splitting (`vite.config.js`):**
  - `vendor-vue`: `vue`, `pinia` (63.37 kB)
  - `vendor-realtime`: `laravel-echo`, `pusher-js` (72.65 kB)
  - `vendor-charts-player`: WebGL renderer, video decoder scripts (13.01 kB)
  - Dedicated async component chunks for `DeviceAlertsCenter`, `AttendanceHub`, `EmployeeDirectory`, etc.

### 5.2 Build Execution Verification
Running `npm run build` in `/home/wsk-devops2/AI-Camera-Integration`:
- **Result:** Code 0 (Success) in 843ms.
- **Output Artifacts:**
  - `public/build/assets/DeviceAlertsCenter-CfjtPykQ-v6.js`: 25.18 kB (gzip: 6.75 kB)
  - `public/build/assets/AttendanceHub-Cqd48DqE-v6.js`: 31.04 kB (gzip: 7.93 kB)
  - `public/build/assets/vendor-realtime-uX-pOzx6-v6.js`: 72.65 kB (gzip: 20.56 kB)
  - `public/build/assets/app-BUv67u4S-v6.js`: 212.49 kB (gzip: 63.19 kB)
- Zero syntax errors, zero missing imports, zero rollup circular dependency warnings.

---

## 6. Implementation Readiness & Recommendations for Subteams

1. **For Frontend Subteam (`resources/js/views/DeviceAlertsCenter.vue`)**:
   - Replace `echo.channel('device-alerts')` with `echo.private('device-alerts')` on both mount and unmount.
   - Pass handler references to `stopListening` so unmounting `DeviceAlertsCenter` does not break `App.vue`'s global alert listeners.
   - Also inspect `PersonnelManager.vue:528, 535` and `SyncTasksMonitor.vue:169, 175` for similar public/private channel mismatches.

2. **For Frontend Subteam (`resources/js/stores/attendanceStore.js`)**:
   - Update `fetchDailyAttendance()` to bind `data.summary` into `this.stats`.
   - Synchronize `this.pagination` from `data.records`.
   - Consider adding `onMounted(() => attendanceStore.fetchDailyAttendance())` in `AttendanceDashboard.vue` to ensure KPI cards populate immediately when viewing the Overview Dashboard tab without waiting for the Roster tab to mount.

3. **For Backend Subteam (`tests/Feature/PerformanceOptimizationTest.php`)**:
   - Add the 13 dedicated test methods outlined in Section 4.2.
   - Use `DB::enableQueryLog()` and query count assertions for runtime compute optimizations.
   - Ensure all tests run cleanly against SQLite in-memory without requiring a physical PostgreSQL or Redis instance during unit testing.
