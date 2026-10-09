# DISPATCH DIRECTIVE — Remediation Worker Replacement (Milestone M3 Iteration 2)

## Identity & Role
- **Agent**: `teamwork_preview_worker_m3_remed_rep`
- **Archetype**: `teamwork_preview_worker`
- **Role**: Replacement Remediation Worker for Milestone M3 (Resilient Domain Lifecycle State Machines)
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_remed_rep`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Reviewer 2 Report: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/handoff.md`
5. Explorer 1 Report & Patch: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_1/handoff.md` and `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_1/proposed_caching_strategy.patch`
6. Explorer 2 Report & Patch: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/handoff.md` and `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/m3_iter2_visitor_kpi_and_filters.patch`
7. Explorer 3 Report & Patch: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_3/handoff.md` and `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_3/remediation.patch`

## Remediation Tasks

### Task 1: Fix AttendanceProcessingService Cache Key Regression
In `app/Services/AttendanceProcessingService.php:207`:
- Restore `"holidays_{$year}"` as primary cache key.
- Store plain associative arrays of scalar attributes.
- Maintain `"holiday_ids_{$year}"` alias.
- Update `HolidayController` to evict both keys on `store`, `update`, `destroy`.
- Verify `php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts` passes.

### Task 2: Facility-wide Visitor KPI Statistics & UI Enhancements
- In `app/Http/Controllers/VisitorController.php`: implement `calculateVisitorStats()` and return aggregate stats in `listVisits` metadata (`meta.stats` / `stats`), and expose route `GET /api/visits/stats`.
- In `resources/js/stores/visitorStore.js`: bind server-provided facility stats and add `fetchStats()`.
- In `resources/js/components/visitors/VisitorDashboard.vue`: add `<option value="no_show">No Show</option>`, badge styling for `no_show`, and accessible pagination controls.

### Task 3: Modal Accessibility & Empty Reason Fallbacks
- In `resources/js/components/leave/LeaveApprovalQueue.vue`: add full WCAG 2.1 AA dialog accessibility on the cancellation modal (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `tabindex="-1"`, `@keydown.escape="showCancelModal = false"`, label linking with textarea id).
- In `app/Http/Controllers/LeaveController.php`, `app/Services/LeaveService.php`, `app/Http/Controllers/RegularizationController.php`, `app/Services/RegularizationService.php`, `app/Http/Controllers/VisitorController.php`, `app/Services/VisitorSyncService.php`:
  Use `!empty(trim((string) $reason)) ? trim((string) $reason) : 'Cancelled by user'` to prevent storing empty string reasons.

### Task 4: Verification Commands
Execute the following verification commands and record verbatim output in `handoff.md`:
1. `php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts`
2. `php artisan test --filter="test_f1[3-9]"`
3. `php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"`
4. `php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php`
5. `php artisan test tests/Feature/AdversarialMilestone3Challenger1Test.php tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/Phase6Milestone3Challenger2Test.php`
6. `php artisan test` (verify full test suite passes with 0 failures!)
7. `npm run build` (verify clean Vite build!)

Deliver `handoff.md` in your working directory and notify parent via `send_message`.


## 2026-10-08T18:21:50Z
Message from parent (340b2ee2-86ac-4ca7-9f71-8c1542c65adb):
You are teamwork_preview_worker_m3_remed_rep.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_remed_rep
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_remed_rep/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_remed_rep/DISPATCH.md
5. Explorer Reports and Patches:
   - /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_1/handoff.md & /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_1/proposed_caching_strategy.patch
   - /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/handoff.md & /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_2/m3_iter2_visitor_kpi_and_filters.patch
   - /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_3/handoff.md & /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_rep_3/remediation.patch

Implement all 4 remediation tasks:
1. Fix AttendanceProcessingService::isHoliday cache key regression, restoring holidays_{$year}.
2. Implement facility-wide visitor stats in VisitorController, visitorStore.js, and VisitorDashboard.vue (with no_show option/badge and pagination).
3. Implement modal accessibility in LeaveApprovalQueue.vue (WCAG 2.1 AA) and empty reason fallback across controllers/services.
4. Run verification commands:
   - php artisan test --filter=test_attendance_processing_service_caches_holidays_and_shifts
   - php artisan test --filter="test_f1[3-9]"
   - php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"
   - php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php
   - php artisan test tests/Feature/AdversarialMilestone3Challenger1Test.php tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/Phase6Milestone3Challenger2Test.php
   - php artisan test (verify entire test suite passes with 0 failures!)
   - npm run build (verify clean Vite build!)

Write your handoff report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_remed_rep/handoff.md and notify parent via send_message.
