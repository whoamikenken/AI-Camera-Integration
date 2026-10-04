## 2026-10-01T06:39:08Z
You are worker_perf_1, a Performance & Telemetry Architecture Specialist subagent.
Your working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_perf_1
Project root: /home/wsk-devops2/AI-Camera-Integration

MANDATORY FIRST STEP:
Read the authoritative user request at /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md.
Also read:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_performance_1/handoff.md (Contains exhaustive code blueprints, exact line numbers, and implementation details for Phases 1-5, Tasks 1.1 through 5.2)
- /home/wsk-devops2/AI-Camera-Integration/tasks-performance.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_2/SCOPE.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your exclusive write boundaries:
- `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php` (new migration)
- `database/migrations/2026_10_01_000002_create_personnel_customize_id_seq.php` (new migration)
- `app/Observers/PersonnelObserver.php` and `app/Observers/EmployeeObserver.php`
- `app/Models/Personnel.php`
- `app/Http/Controllers/ReportController.php` and `app/Http/Controllers/PayrollExportController.php`
- `app/Http/Controllers/PersonnelController.php` and `app/Http/Controllers/EmployeeController.php`
- `app/Jobs/ImportCameraPersonnelJob.php` (new job)
- `app/Jobs/SyncDevicePersonnelJob.php` (new job)
- `app/Http/Controllers/DeviceController.php`
- `app/Console/Commands/MqttListenCommand.php`
- `app/Http/Controllers/HttpWebhookController.php`
- `app/Events/AccessLogReceived.php` (set to ShouldBroadcast on Redis queue, keep PrivateChannel)
- `app/Services/CameraMqttService.php` and `app/Jobs/SyncPersonnelJob.php`
- `app/Http/Controllers/DashboardStatsController.php`
- `app/Services/AttendanceProcessingService.php`
- `tests/Feature/PerformanceOptimizationTest.php` (new feature tests)

Do NOT modify any files in `resources/js/` (those are complete and owned by worker_a11y_1).
Do NOT break or undo any security remediations implemented by worker_sec_1 (such as webhook authentication, encrypted passwords, PrivateChannel instances, SSRF checks, etc.).

Your mission:
Implement all 12 performance and database architecture tasks from `tasks-performance.md` following the blueprints in `survey_performance_1/handoff.md`:
1. Task 1.1: Migration `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php` adding indexes for `device_alerts(device_id)`, `visits(host_employee_id, personnel_id)`, `employees(designation_id, location_id, shift_id, reporting_manager_id)`, composite on `device_alerts(captured_at, severity)`, `leave_requests(employee_id, status, start_date, end_date)`, `visits(check_in_time, check_out_time)`, `visitors(email, phone)`, composite on `access_logs(captured_at, verify_status)` and `access_logs(customize_id, captured_at)`.
2. Task 1.2: Eliminate synchronous `AccessLog::query()->delete()` in `PersonnelObserver.php` and `EmployeeObserver.php`. Telemetry access logs must be preserved as immutable compliance audit records.
3. Task 1.3: Concurrency race condition elimination: Provision PostgreSQL sequence `personnel_customize_id_seq` starting at 1000 in migration `2026_10_01_000002_create_personnel_customize_id_seq.php`. Update `Personnel::booted()` with driver-aware atomic sequence generation (using `nextval('personnel_customize_id_seq')` on PostgreSQL and max+1 fallback on SQLite).
4. Task 2.1: Refactor monthly attendance/payroll queries in `ReportController.php` and `PayrollExportController.php` to single SQL queries using `GROUP BY employee_id` and conditional sums (`SUM(CASE WHEN ...)`), plus cursor streaming for CSV exports.
5. Task 2.2: Add `'photo_base64'` to `$hidden` in `app/Models/Personnel.php`, and use targeted column selections in `PersonnelController::index` and `EmployeeController::index` to prevent multi-megabyte JSON payloads.
6. Task 2.3: Move camera personnel import to `ImportCameraPersonnelJob` queued on `camera-sync`. Refactor `DeviceController::importPersonnel` to return HTTP `202 Accepted` immediately with task token.
7. Task 3.1: Throttle camera heartbeat database writes in `MqttListenCommand.php` and `HttpWebhookController.php` using Redis cache (`device_hb_throttle:{$deviceId}`, 60s TTL), persisting to DB at most once per 60 seconds per camera.
8. Task 3.2: Convert `AccessLogReceived` to `ShouldBroadcast` (queue backed) rather than `ShouldBroadcastNow`, using connection `'redis'` and queue `'broadcasts'`, keeping `new PrivateChannel('access-logs')`.
9. Task 3.3: MQTT client connection reuse in `CameraMqttService` and parallel device dispatch in `SyncPersonnelJob` via `SyncDevicePersonnelJob`.
10. Task 4.1: Dashboard telemetry stats Redis caching (5-10s TTL) and consolidated conditional alert query in `DashboardStatsController.php`.
11. Task 4.2: Holiday & shift lookup caching in `AttendanceProcessingService.php`.
