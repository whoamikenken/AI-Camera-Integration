# Execution Plan: Phase 6 Milestones 3 & 5

## Milestone 3: Caching & Telemetry Pipeline Optimization (Tasks 6.8 – 6.11)
1. **Survey / Exploration (Step 2B.a)**:
   - Spawn 3 Explorers in parallel:
     - `p6_m3_explorer_1`: Investigate Task 6.8 (`ShiftController.php:298-311`) - analyze shift cache keying, versioned keys / key tracking sets replacing `KEYS`, and cache consumption points.
     - `p6_m3_explorer_2`: Investigate Task 6.9 (`MqttListenCommand.php:243-246, 333-336, 410-413`) and Task 6.10 (`ProcessAttendancePunchJob.php:33-46`, `EmployeeObserver.php`, `PersonnelObserver.php`) - analyze device registration caching and customize_id to employee identity caching.
     - `p6_m3_spec_miner`: Investigate Task 6.11 (`DeviceAlertController.php:96, 120`, `SettingController.php:30-45`, `SettingService.php:60-89`) - analyze alert stats and public settings caching, and inspect existing `PerformanceOptimizationTest.php`.
2. **Worker Implementation (Step 2B.b)**:
   - Spawn `p6_m3_worker` with merged explorer findings.
   - Implement Task 6.8, 6.9, 6.10, 6.11.
   - Run tests: `php artisan test --filter=PerformanceOptimizationTest`.
3. **Independent Reviews (Step 2B.c)**:
   - Spawn 2 Reviewers (`p6_m3_reviewer_1`, `p6_m3_reviewer_2`) to check correctness, performance, edge cases, and Redis non-blocking semantics.
4. **Adversarial Verification (Step 2B.d)**:
   - Spawn 2 Challengers (`p6_m3_challenger_1`, `p6_m3_challenger_2`) to verify cache invalidation, cache hits/misses, race conditions, and edge cases.
5. **Forensic Audit (Step 2B.e)**:
   - Spawn 1 Forensic Auditor (`p6_m3_auditor`) to verify genuine implementation (no facades, no bypassing).
6. **Milestone 3 Gate (Step 2B.f)**:
   - Strict AND check on all verdicts.

## Milestone 5: Verification & Final Acceptance
1. **Worker / Test Writer Implementation**:
   - Ensure comprehensive dedicated test methods exist in `tests/Feature/PerformanceOptimizationTest.php` for all Phase 6 tasks (6.1 – 6.11).
   - Verify `php artisan test --filter=PerformanceOptimizationTest` passes.
   - Verify full test suite `php artisan test` passes with 0 failures and 0 errors.
   - Verify `npm run build` succeeds cleanly.
   - Update `tasks-performance.md` marking Phase 6 tasks as completed `[x]`.
2. **Review & Gate for Milestone 5**:
   - Reviewer, Challenger, and Auditor verification.
3. **Completion Report**:
   - Synthesize results and report completion back to the Sentinel caller.
