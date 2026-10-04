# Stage 3 Worker Dispatch: Performance Optimization (`tasks-performance.md`)

## Objectives
You are the Performance Pipeline Worker. Your role is to formulate explicit Jules briefs for all pending tasks in `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md`, dispatch them via `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`, track remote sessions, pull/teleport patches (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`), validate with `php artisan test` and `npm run build`, and mark corresponding tasks in `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md` from `- [ ]` to `- [x]`.

## Mandatory Reading
First read:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
and
`/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md`

## Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Task Breakdown for Jules Sessions

### Brief 1: Database Architecture & Indexing (Task 1.4 & Task 2.7)
- Target: `database/migrations/2026_10_04_000001_add_deep_performance_indexes.php`, `app/Http/Controllers/AccessLogController.php`, `app/Http/Controllers/SyncTaskController.php`, `app/Http/Controllers/DeviceController.php`
- Details:
  - Task 1.4: Add composite indexes: `visits(expected_arrival, status)`, `stranger_snaps(device_id, captured_at DESC)`, `sync_tasks(device_id, updated_at DESC)`, and `attendance_records(date, status)`. Ensure migration rolls up and rolls back cleanly (`migrate:rollback`).
  - Task 2.7: Column-specific eager loading on personnel: use `with(['personnel:id,customize_id,name,person_type,photo_path'])` instead of bare `with('personnel')` in `AccessLogController`, `SyncTaskController`, and `DeviceController` to avoid hydrating massive `photo_base64`.

### Brief 2: Attendance & Shift Query Optimizations (Task 2.4, Task 2.5, Task 2.8)
- Target: `app/Jobs/DailyAttendanceFinalizerJob.php`, `app/Http/Controllers/ShiftController.php`, `app/Http/Controllers/ReportController.php`
- Details:
  - Task 2.4: Eliminate N+1 queries in `DailyAttendanceFinalizerJob.php`. Pre-fetch existing attendance record IDs (`where('date', $dateStr)->pluck('id', 'employee_id')`), process active employees using `chunkById(250)` to bound memory to O(1).
  - Task 2.5: Batch multi-record SQL operations in `performShiftAssignment` in `ShiftController.php`: (1) bulk update previous assignments (`whereIn('employee_id', $ids)...update(['effective_to' => $prevEnd])`), (2) bulk insert new assignments (`insert($rows)`), (3) bulk update active shift on employees (`Employee::whereIn('id', $ids)->update(['shift_id' => $shift->id])`).
  - Task 2.8: Eliminate duplicate `Employee::with('department')->where('employment_status', 'active')->get()` in `ReportController.php:57-82`.

### Brief 3: Attendance Pagination & Export Streaming (Task 2.6)
- Target: `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/EmployeeController.php`, `app/Http/Controllers/PayrollExportController.php`
- Details:
  - Paginate `AttendanceController::daily()` using `paginate($perPage)`. Compute daily metrics via single SQL conditional aggregation rather than collection counting.
  - Stream JSON and CSV exports in `EmployeeController` and `PayrollExportController` using cursor or chunking to prevent memory bloat.

### Brief 4: Telemetry Streaming & MQTT Ingestion (Task 3.1, Task 3.2, Task 3.3)
- Target: `app/Console/Commands/MqttListenCommand.php`, `app/Events/DeviceAlertReceived.php`, `app/Events/DeviceAlertUpdated.php`, `app/Events/StrangerSnapReceived.php`, `app/Events/DeviceStatusUpdated.php`, `app/Events/AttendancePunchReceived.php`, `app/Events/NotificationCreated.php`, `app/Services/CameraMqttService.php`
- Details:
  - Task 3.1: Enforce heartbeat write throttling in `MqttListenCommand.php`. Remove unconditional `$device->update(['last_heartbeat_at' => now()])` in telemetry push handlers; rely strictly on 60s Redis throttle key (`device_hb_throttle:{$deviceId}`).
  - Task 3.2: Asynchronous event broadcasting: change events from `ShouldBroadcastNow` to `ShouldBroadcast` on Redis `broadcasts` queue. Remove expensive synchronous counts in `DeviceStatusUpdated::broadcastWith()`.
  - Task 3.3: Connection pooling / persistent connection for MQTT downlink in `CameraMqttService.php` to prevent teardown on every command.

### Brief 5: Caching Layer & Invalidation Engine (Task 4.2, Task 4.3, Task 4.4)
- Target: `app/Http/Controllers/DeviceAlertController.php`, `app/Http/Controllers/HolidayController.php`, `app/Http/Controllers/ShiftController.php`, `app/Models/Employee.php`, `app/Http/Controllers/DeviceController.php`, `app/Models/Device.php`
- Details:
  - Task 4.2: Consolidate 6 queries into single conditional aggregation query and cache with 5s TTL in `DeviceAlertController::stats()`.
  - Task 4.3: Cache invalidation engine: `Cache::forget("holidays_{$year}")` on holiday mutations; invalidate employee shift cache `emp_shift:{$id}:*` on shift assignment; leverage yearly Redis cache in `Employee::isHoliday()`.
  - Task 4.4: Device fleet status caching: eliminate `withCount(['accessLogs', 'strangerSnaps'])` full-table scans on every index query.

### Brief 6: Client-Side Runtime & Asset Optimization (Task 5.2, 5.3, 5.4, 5.5, 5.6)
- Target: `vite.config.js`, `resources/js/views/LiveTelemetry.vue`, `resources/js/App.vue`, `resources/js/stores/cameraStore.js`, `resources/js/utils/cameraHqPlayer.js`
- Details:
  - Task 5.2: Configure `manualChunks` in `vite.config.js` (`vendor-vue`, `vendor-realtime`, `vendor-charts-player`).
  - Task 5.3: Pause 4s HTTP polling in `LiveTelemetry.vue` when `store.wsConnected` is true.
  - Task 5.4: Deduplicate WebSocket Echo listeners and call `unbind()` during cleanup in `App.vue`.
  - Task 5.5: Lazily initialized singleton `AudioContext` in `cameraStore.js`.
  - Task 5.6: Explicit WebGL texture, buffer, program, shader deletion prior to `loseContext()` in `WebGLYUVRenderer::destroy()`.

## Workflow Execution Steps
1. Dispatch Jules sessions using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
2. Monitor session statuses via `jules remote list --session`.
3. Pull or teleport completed sessions (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`).
4. Validate changes:
   - Run `php artisan test` (must pass 100%).
   - Run `npm run build` (must pass cleanly).
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md`:
   Mark all 17 completed tasks from `- [ ]` to `- [x]`.
6. Write a comprehensive handoff report at `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage3_performance/handoff.md`.
7. Send a message to orchestrator upon completion.


## 2026-10-04T01:53:39Z
You are the Worker subagent responsible for executing Stage 3: Performance Optimization (all 17 pending tasks in tasks-performance.md) using autonomous Jules CLI sessions.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage3_performance

Read your dispatch instructions:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage3_performance/DISPATCH.md

MANDATORY: Read the original user request before starting:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your responsibilities:
1. Formulate and dispatch Jules sessions for the 17 pending tasks across Phases 1-5 using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
2. Monitor session progress via `jules remote list --session`.
3. Pull/teleport and apply patches cleanly (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`).
4. Validate changes with `php artisan test` and `npm run build`.
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-performance.md` by marking all 17 resolved tasks `- [x]`.
6. Document all Jules session IDs, brief summaries, pull statuses, test results, and verified tasks in:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage3_performance/handoff.md
Once complete, send a message to your parent with your findings and results.


## 2026-10-04T02:24:44Z
**Context**: Stage 3 Performance Jules sessions monitoring.
**Content**: Checking in on session completion status for PERF-01 through PERF-06. Have any of the 6 remote sessions finished execution on whoamikenken/AI-Camera-Integration?
**Action**: Please inspect `jules remote list --session`, pull completed sessions, update progress.md, and report back status.
