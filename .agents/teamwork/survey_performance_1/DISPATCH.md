## 2026-10-01T05:50:07Z
You are survey_performance_1, a Performance Architecture Surveyor subagent.
Your working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_performance_1
Project root: /home/wsk-devops2/AI-Camera-Integration

MANDATORY FIRST STEP:
Read the authoritative user request at /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md.
Also inspect:
- /home/wsk-devops2/AI-Camera-Integration/tasks-performance.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

Your mission:
Conduct a comprehensive, read-only code and schema survey of the AI-Camera-Integration repository to analyze every item in tasks-performance.md (Phases 1 through 5, Tasks 1.1 through 5.2).
You must examine the existing implementation and document the exact file paths, line numbers, current behavior, performance bottlenecks, required architectural modifications, and verification test requirements for:
1. Task 1.1: Missing foreign keys and composite indexes on `device_alerts`, `visits`, `employees`, `leave_requests`, `access_logs`. Design exact migration schema for `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php`.
2. Task 1.2: Destructive synchronous telemetry lock in `app/Observers/PersonnelObserver.php` deleting hook (`AccessLog::where(...)->delete()`). Analyze how to decouple access logs as immutable compliance records.
3. Task 1.3: Concurrency race condition in `app/Models/Personnel.php` (`static::max('customize_id') + 1`). Design PostgreSQL sequence migration and model boot hook refactoring.
4. Task 2.1: Refactoring monthly attendance and payroll calculations in `app/Http/Controllers/ReportController.php` and `app/Http/Controllers/PayrollExportController.php` from quadratic in-memory PHP loops to SQL `GROUP BY` and conditional `SUM(...)` aggregations, plus CSV chunking/cursor streaming.
5. Task 2.2: Stripping massive `photo_base64` image columns from entity serialization in `app/Models/Personnel.php`, `app/Http/Controllers/PersonnelController.php`, and `app/Http/Controllers/EmployeeController.php` via `$hidden` and column selections.
6. Task 2.3: Offloading camera personnel import in `app/Http/Controllers/DeviceController.php` to a queued background job (`ImportCameraPersonnelJob`) with immediate 202 Accepted response.
7. Task 3.1: Throttling heartbeat database writes in `app/Console/Commands/MqttListenCommand.php` and `app/Http/Controllers/HttpWebhookController.php` using Redis cache with 60-second TTL.
8. Task 3.2: Converting `AccessLogReceived` from `ShouldBroadcastNow` to asynchronous `ShouldBroadcast` backed by Redis queue.
9. Task 3.3: MQTT connection reuse and parallel per-device dispatch in `app/Services/CameraMqttService.php` and `app/Jobs/SyncPersonnelJob.php`.
10. Task 4.1: Dashboard telemetry stats Redis caching (5-10s TTL) and query consolidation in `app/Http/Controllers/DashboardStatsController.php`.
11. Task 4.2: Holiday & shift lookup caching in `app/Services/AttendanceProcessingService.php`.
12. Task 5.1 & 5.2: Frontend code-splitting and WebSocket listener deduplication in `resources/js/App.vue`.

Write your complete findings and implementation blueprint to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_performance_1/handoff.md`
Also update `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_performance_1/progress.md` with your status.
When finished, send a message to the orchestrator (conversation ID: 555048b9-bfc8-4063-abe1-34a9a4ddd93f) with a summary and link to your handoff.md.
