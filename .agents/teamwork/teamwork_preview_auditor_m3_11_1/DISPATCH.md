# DISPATCH DIRECTIVE — Forensic Auditor (Milestone M3)

## Identity & Role
- **Agent**: `teamwork_preview_auditor_m3_11_1`
- **Archetype**: `teamwork_preview_auditor`
- **Role**: Forensic Integrity Auditor for Milestone M3 (Resilient Domain Lifecycle State Machines)
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md`

## Forensic Audit Objective
Perform rigorous forensic integrity analysis across all files added or touched for Milestone M3:
1. **Source Code Integrity Checks**:
   - Verify NO hardcoded test results, fabricated return values, or artificial flags.
   - Verify that `LeaveService::cancelLeaveRequest` performs genuine database transaction and `lockForUpdate()`, genuine balance decrement/increment, and authentic daily recalculation via `AttendanceProcessingService::processDay()`.
   - Verify that `RegularizationService::cancelRegularization` genuinely validates status and updates `regularization_requests`.
   - Verify that `VisitorSyncService::cancelVisit` genuinely dispatches camera de-provisioning (`revokeVisitorFace()` -> `DelPerson` / `SyncPersonnelJob`).
   - Verify that `DetectOverstayVisitorsJob` and `ExpireNoShowVisitsJob` contain genuine database queries, timestamps, and `DeviceAlert` creation (no dummy returns).
   - Verify that migrations properly alter schemas and models use portable SQL without hardcoded driver assumptions.
2. **Execution & Runtime Verification**:
   - Verify git diff: `git diff --stat` and inspect changed files.
   - Run tests:
     * `php artisan test --filter="test_f1[3-9]"`
     * `php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"`
     * `npm run build`
3. **Integrity Verdict**:
   - Deliver binary verdict: `CLEAN` or `INTEGRITY VIOLATION`.
   - Provide full evidence chain in handoff report.

Deliver report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m3_11_1/handoff.md` and notify parent via `send_message`.


## 2026-10-08T06:44:40Z
You are teamwork_preview_auditor_m3_11_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m3_11_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m3_11_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md

Perform exhaustive forensic integrity verification across all code added or touched for Milestone M3:
- Inspect git diff and source code.
- Verify NO hardcoded test results, facade logic, or test-specific bypasses in production code.
- Verify genuine LeaveService transaction, atomic lockForUpdate, genuine balance restoration and attendance rollback.
- Verify genuine RegularizationService, VisitorSyncService (real camera de-provisioning), DetectOverstayVisitorsJob, ExpireNoShowVisitsJob, and routes.
- Run tests: php artisan test --filter="test_f1[3-9]", npm run build.

Deliver binary verdict (CLEAN or INTEGRITY VIOLATION) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m3_11_1/handoff.md and notify parent via send_message.
