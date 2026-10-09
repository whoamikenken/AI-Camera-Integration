## 2026-10-07T06:38:59Z

You are Worker M1 Remediation (Database & Schema Engineer) for Phase 6 Milestone 1 Iteration 2.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_worker_remed

Read these documents first:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_worker_remed/DISPATCH.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_challenger_1/handoff.md

Exclusive Write Ownership:
- app/Http/Controllers/AttendanceController.php
- app/Http/Controllers/VisitorController.php
- tests/Feature/PerformanceOptimizationTest.php

Your Tasks:
1. SARGability in `app/Http/Controllers/AttendanceController.php:114-118`:
   In `punches()`, replace `whereDate('punch_time', $request->query('date'))` with SARGable `whereBetween('punch_time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])` wrapped in a defensive `try / catch (\Throwable)` block that sets `$query->whereRaw('1 = 0')` if date string is unparseable. Ensure `Carbon\Carbon` is imported.
2. Defensive Date Parsing in `app/Http/Controllers/VisitorController.php:141-146`:
   Wrap `$date = Carbon::parse($request->query('date'));` in `try / catch (\Throwable)` that sets `$query->whereRaw('1 = 0')` if date string is unparseable.
3. Dedicated Test Methods in `tests/Feature/PerformanceOptimizationTest.php`:
   Add dedicated test methods:
   - `test_phase6_attendance_and_visitor_queries_use_sargable_ranges`: Assert query log on `/api/visits` and `/api/attendance/punches` uses range clauses and does NOT wrap columns in `strftime()` or `whereDate`.
   - `test_phase6_composite_and_foreign_key_indexes_exist`: Assert `idx_access_logs_device_id_captured_at`, `idx_attendance_punches_device_id`, `idx_notifications_notifiable_created_at`, `idx_notifications_notifiable_read_at` exist in schema.
   - `test_phase6_sync_tasks_dashboard_stats_query_filters_active_statuses`: Assert `/api/dashboard/stats` filters `sync_tasks` query with `whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])`.
   - `test_phase6_wide_read_endpoints_are_paginated_and_column_constrained`: Assert paginated response structure and constrained columns for `/api/leave-balances`, `/api/locations`, `/api/departments`, and `/api/designations`.
4. Verification:
   - Run `php artisan test --filter=Phase6Milestone1Challenger1Test` -> Must pass all 10 tests!
   - Run `php artisan test --filter=PerformanceOptimizationTest` -> All tests pass!
   - Run `php artisan test` -> Full test suite passes.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Deliverables:
- Maintain `progress.md` in your working directory.
- Write handoff report in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m1_worker_remed/handoff.md`.
- Send message back to parent orchestrator.
