# DISPATCH DIRECTIVE — Spec Miner (M3)

## Identity & Role
- **Agent**: `teamwork_preview_spec_miner_m3_11_1`
- **Archetype**: `teamwork_preview_spec_miner`
- **Role**: Specification Miner for Milestone M3 (Resilient Domain Lifecycle State Machines)
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
You MUST read these documents before starting your investigation:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2: Resilient Domain Lifecycle State Machines)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Feature Inventory #13-#19, Milestone M3)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (E2E requirements)

## Investigation Objective
Extract and document the exact, exhaustive behavioral specification, state transitions, validation rules, database column requirements, API contracts, and error handling for:
1. **Leave Cancellation Workflow**:
   - Supported state transitions: `pending -> cancelled`, `approved -> cancelled`.
   - Disallowed states (e.g. already rejected, already cancelled, past leaves if applicable).
   - Database columns needed on `leave_requests` (`cancellation_reason`, `cancelled_by`, `cancelled_at`, status enum values).
   - Atomic balance restoration behavior: how `LeaveBalance` is restored (used vs pending), transaction isolation, `lockForUpdate`.
   - Attendance rollback: reverting daily `AttendanceRecord` statuses (e.g. from `on_leave` back to daily status) and recalculating via `AttendanceProcessingService::processDay()`.
   - Route and payload: `POST /api/leave-requests/{id}/cancel`.
2. **Regularization Cancellation Workflow**:
   - Supported state transitions: `pending -> cancelled`.
   - Disallowed states (e.g. already approved, already rejected, already cancelled).
   - Database columns needed on `attendance_regularizations` (`cancellation_reason`, `cancelled_by`, `cancelled_at`).
   - Route and payload: `POST /api/regularization-requests/{id}/cancel` (or existing route naming).
3. **Visitor Lifecycle State Handling**:
   - State machine on `visits`: `expected`, `checked_in`, `checked_out`, `cancelled`, `no_show`, `overstayed`.
   - Cancellation transition: `expected -> cancelled`. Immediate de-provisioning of face credentials from edge cameras.
   - Database columns needed on `visits` (`expected_departure`, `overstay_alerted_at`, `cancellation_reason`, `cancelled_by`, `cancelled_at`).
   - Background Job `DetectOverstayVisitorsJob` (runs every 15 minutes): identifies `checked_in` visits where `now() > expected_departure`, flags alert, creates `DeviceAlert`.
   - Background Job `ExpireNoShowVisitsJob` (runs at midnight): transitions past `expected` visits to `no_show`.
   - Route: `POST /api/visits/{id}/cancel` and `GET /api/visits/overstayed`.
4. **UI Surfaces**:
   - Cancel buttons in `LeaveApprovalQueue.vue` / `SelfServicePortal.vue`.
   - Overstay badge alerts on `VisitorDashboard.vue`.

## Deliverables
Write a structured report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1/handoff.md` with:
- Exhaustive Specification Matrix (Entity, Current States, New States, Transition Triggers, Side Effects).
- API Endpoint Contracts (URL, HTTP Method, Request Body, Response Envelope, Status Codes).
- Edge Cases & Boundary Conditions.
Notify the parent orchestrator via `send_message` when done.


## 2026-10-08T05:53:10Z
[Message] timestamp=2026-10-08T05:53:10Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH content=You are teamwork_preview_spec_miner_m3_11_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2: Resilient Domain Lifecycle State Machines)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M3, Features #13-#19)
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Extract exhaustive behavioral specifications, states and transitions, database columns, API routes, validation rules, and error conditions for:
1. Leave cancellation (pending->cancelled, approved->cancelled with atomic LeaveBalance restoration and AttendanceRecord status rollback/recalculation).
2. Regularization cancellation (pending->cancelled).
3. Visitor lifecycle (expected->cancelled with immediate camera de-provisioning, overstayed detection every 15m, no-show expiration at midnight, GET /api/visits/overstayed).
4. UI requirements on Vue files.

Write your findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message with a summary and link to handoff.md.
