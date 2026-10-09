# DISPATCH DIRECTIVE — Reviewer 1 (M3 Backend & Domain Lifecycle)

## Identity & Role
- **Agent**: `teamwork_preview_reviewer_m3_11_1`
- **Archetype**: `teamwork_preview_reviewer`
- **Role**: Backend & Domain Reviewer for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2: Resilient Domain Lifecycle State Machines)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M3)
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md`

## Review Objective
Review backend changes across models, services, migrations, controllers, background jobs, and routes for Milestone M3:
1. Verify database migration `2026_10_08_000001_add_m3_lifecycle_state_columns.php`.
2. Verify `LeaveService::cancelLeaveRequest`: atomic lock (`lockForUpdate`), pending vs used days restoration, attendance rollback with `processDay()`, disallowed state rejection (422).
3. Verify `RegularizationService::cancelRegularization`: only pending allowed, approved/rejected rejected.
4. Verify `VisitorSyncService::cancelVisit`: immediate edge camera face revocation via `revokeVisitorFace()`.
5. Verify background jobs: `DetectOverstayVisitorsJob` (15m grace window, DeviceAlert) and `ExpireNoShowVisitsJob` (midnight no-show).
6. Verify routes in `routes/api.php`: `GET visits/overstayed` is declared BEFORE `GET visits/{id}`.
7. Run verification commands:
   - `php artisan test --filter="test_f1[3-9]"`
   - `php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"`
   - `php artisan test --filter="test_cross_leave|test_cross_visitor|test_scenario_7|test_scenario_9"`
   - `php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php`

Deliver verdict (`APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_1/handoff.md` and notify parent via `send_message`.


## 2026-10-08T06:44:40Z
[Message] timestamp=2026-10-08T06:44:40Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH content=You are teamwork_preview_reviewer_m3_11_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md

Review all backend implementations (LeaveService, AttendanceProcessingService, RegularizationService, VisitorSyncService, Background Jobs, routes/api.php, migrations).
Run tests:
php artisan test --filter="test_f1[3-9]"
php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"
php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_1/handoff.md and notify parent via send_message.
