## 2026-10-07T01:57:46Z

You are teamwork_preview_worker (Worker M1: Database & Schema Engineer).
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_worker

You MUST read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_6/DISPATCH.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_1/survey_db_report.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_1/handoff.md

You have EXCLUSIVE write ownership of:
- app/Services/AttendanceProcessingService.php
- app/Http/Controllers/VisitorController.php
- database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php
- app/Http/Controllers/DashboardStatsController.php
- app/Http/Controllers/LeaveController.php
- app/Http/Controllers/OrganizationController.php

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Mission - Implement Tasks 6.1 through 6.4:
1. Task 6.1: In app/Services/AttendanceProcessingService.php (around lines 59-63), replace non-SARGable whereDate('punch_time', $punchTime->toDateString()) with SARGable range whereBetween('punch_time', [$punchTime->copy()->startOfDay(), $punchTime->copy()->endOfDay()]).
   In app/Http/Controllers/VisitorController.php (around lines 141-144), replace whereDate('expected_arrival', $request->query('date')) with whereBetween('expected_arrival', [\Carbon\Carbon::parse($request->query('date'))->startOfDay(), \Carbon\Carbon::parse($request->query('date'))->endOfDay()]). Ensure Carbon instance is imported or fully qualified and not mutated unexpectedly.
2. Task 6.2: Create database migration database/migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php.
   Add indexes:
   - access_logs: composite index on ['device_id', 'captured_at']
   - attendance_punches: index on ['device_id']
   - notifications: composite index on ['notifiable_type', 'notifiable_id', 'created_at'] and composite index on ['notifiable_type', 'notifiable_id', 'read_at']
   Provide proper down() method with dropIndex.
   Run `php artisan migrate` and verify it runs cleanly.
3. Task 6.3: In app/Http/Controllers/DashboardStatsController.php (lines 68-74), scope $syncTaskStats = SyncTask::toBase() with ->whereIn('status', ['PENDING', 'PROCESSING', 'FAILED']).
4. Task 6.4: In app/Http/Controllers/LeaveController.php (listBalances):
   Support pagination with $perPage = (int) $request->query('per_page', 25) (or default 25 if not provided) and constrain relationships: with(['employee:id,first_name,last_name,employee_code', 'leaveType:id,name,code']). Ensure foreign keys (employee_id, leave_type_id) are selected. Return $balances->paginate($perPage) as JSON.
   In app/Http/Controllers/OrganizationController.php:
   - listLocations(): paginate with $request->query('per_page', 25) and column constraints.
   - listDepartments(): paginate with $request->query('per_page', 25), with(['parent:id,name,code', 'head:id,first_name,last_name']), withCount('employees').
   - listDesignations(): paginate with $request->query('per_page', 25), with(['department:id,name,code']), withCount('employees').
   Note: Maintain compatibility with frontend expectations ($request->boolean('all') or paginator structure res.data.data || res.data).

Verification:
- Run `php artisan migrate`
- Run `php artisan test` and verify tests pass with 0 failures.
- Document exact changes and test outputs in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_worker/handoff.md.
- Send message back to parent when done.
