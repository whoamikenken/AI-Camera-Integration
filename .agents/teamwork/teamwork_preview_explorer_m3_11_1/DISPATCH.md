# DISPATCH DIRECTIVE — Leave & Attendance Explorer (M3)

## Identity & Role
- **Agent**: `teamwork_preview_explorer_m3_11_1`
- **Archetype**: `teamwork_preview_explorer`
- **Role**: Backend Leave & Attendance Explorer for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
You MUST read these documents before starting your investigation:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`

## Technical Exploration Objective
Investigate the current codebase for Leave and Attendance Regularization:
1. **Leave Domain**:
   - Inspect `app/Models/LeaveRequest.php`, `app/Models/LeaveBalance.php`, `app/Models/LeaveType.php`.
   - Inspect `app/Services/LeaveService.php`, `app/Http/Controllers/LeaveController.php` (or similar leave controllers).
   - Trace existing balance deduction logic during submission and approval (`used`, `pending`, `balance`).
   - Trace existing attendance creation/marking logic when leave is approved (`AttendanceRecord` marked as `on_leave`).
   - Inspect `app/Services/AttendanceProcessingService.php` to see how `processDay()` works and how daily attendance records are recalculated.
   - Design the exact implementation of `LeaveService::cancelLeaveRequest(LeaveRequest $request, User $user, string $reason)`:
     * DB transaction with `lockForUpdate` on `LeaveBalance`.
     * If status was `pending`: decrement `pending_days` on `LeaveBalance`.
     * If status was `approved`: decrement `used_days`, restore balance.
     * Revert `AttendanceRecord` statuses that were `on_leave` for the date range, and trigger `processDay($employee, $date)`.
     * Update `leave_requests` status to `cancelled`, record `cancellation_reason`, `cancelled_by`, `cancelled_at`.
2. **Regularization Domain**:
   - Inspect `app/Models/AttendanceRegularization.php`, `app/Http/Controllers/AttendanceRegularizationController.php` (or `RegularizationController.php`), and any related service.
   - Trace submission and approval workflows.
   - Design regularization cancellation endpoint `POST /api/regularization-requests/{id}/cancel` (or existing route convention):
     * Allow cancellation only if `status === 'pending'`.
     * Update status to `cancelled`, record cancellation metadata.
3. **Database Schema & Migrations**:
   - Check existing schema and column types for `leave_requests` and `attendance_regularizations`.
   - Determine what migrations are needed to add columns or status enum updates.
4. **Existing Tests & Test Coverage**:
   - Review `tests/Unit/LeaveServiceTest.php` or `tests/Feature/Leave*` or `Attendance*` tests.

## Deliverables
Write a comprehensive technical report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1/handoff.md` with:
- Exact file paths, line numbers, and existing code snippets.
- Proposed implementation changes, step-by-step logic, edge case handling.
- Suggested automated tests.
Notify parent via `send_message`.


## 2026-10-08T05:53:11Z
You are teamwork_preview_explorer_m3_11_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md

Investigate the backend codebase for Leave and Regularization workflows:
1. Inspect LeaveRequest, LeaveBalance, LeaveType models, LeaveService, LeaveController, AttendanceRecord, AttendanceProcessingService::processDay().
2. Inspect AttendanceRegularization model, controller, services.
3. Design exact implementation for cancelLeaveRequest with atomic balance restoration and attendance rollback.
4. Design regularization cancellation endpoint.
5. Identify required migrations and test cases.

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message.
