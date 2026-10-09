# DISPATCH DIRECTIVE — Reviewer 2 (M3 Frontend & Integration)

## Identity & Role
- **Agent**: `teamwork_preview_reviewer_m3_11_2`
- **Archetype**: `teamwork_preview_reviewer`
- **Role**: Frontend & Full-Stack Integration Reviewer for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md`

## Review Objective
Review frontend components, store integrations, and full-stack behavior for Milestone M3:
1. Examine `resources/js/views/LeaveApprovalQueue.vue` & `resources/js/components/leave/LeaveApprovalQueue.vue`:
   - Filter includes `cancelled`.
   - Cancel action button appears for pending & approved requests.
   - Cancellation reason modal prompts user.
2. Examine `resources/js/views/SelfServicePortal.vue`:
   - Cancellation of pending leaves and regularizations supported.
3. Examine `resources/js/views/VisitorDashboard.vue` & `resources/js/components/visitors/VisitorDashboard.vue`:
   - Overstay KPI card / alert banner.
   - Status badges for `overstayed`, `no_show`, `cancelled`.
   - Cancel action with confirmation modal.
4. Examine `resources/js/stores/leaveStore.js` and `resources/js/stores/visitorStore.js`:
   - Store actions dispatch correct API requests to `/api/leave-requests/{id}/cancel`, `/api/regularization-requests/{id}/cancel`, `/api/visits/{id}/cancel`, `/api/visits/overstayed`.
5. Run build and tests:
   - `npm run build`
   - `php artisan test --filter="test_f1[3-9]"`

Deliver verdict (`APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/handoff.md` and notify parent via `send_message`.

## 2026-10-08T06:44:40Z
[Message] timestamp=2026-10-08T06:44:40Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH
content=You are teamwork_preview_reviewer_m3_11_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md

Review frontend views and store integrations (LeaveApprovalQueue.vue, SelfServicePortal.vue, VisitorDashboard.vue, leaveStore.js, visitorStore.js).
Run build: npm run build
Run tests: php artisan test --filter="test_f1[3-9]"

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/handoff.md and notify parent via send_message.
