# Dispatch Directive: Challenger 1 (Milestone 1)

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m1_1`

## Authoritative Reference
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (Section ## 2026-10-07T01:46:02Z)
- `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1/handoff.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`

## Assignment
Perform adversarial verification and stress testing against Milestone 1 (SEC-11, SEC-12, SEC-14):
1. **Adversarial Probing on SEC-11**:
   - Probe `/api/employees/{id}/attendance-summary` with varied roles (super-admin, admin, manager, employee, guest, unlinked user).
   - Test parameter tampering (negative IDs, string IDs, non-existent employee IDs, own employee ID, peer employee ID).
   - Confirm non-managers receive HTTP 403 when requesting other users' summaries.
2. **Adversarial Probing on SEC-12**:
   - Probe WebSocket channel authorization via HTTP post to `/broadcasting/auth`.
   - Test channel names: `private-notifications`, `private-notifications.1`, `private-notifications.2`.
   - Confirm user 1 cannot authenticate for user 2's channel, and no user can authenticate for legacy global channel.
3. **Adversarial Probing on SEC-14**:
   - Probe `/api/media/{path}` with:
     * Valid temporary signed URL (expect 200)
     * Tampered signature (expect 401)
     * Expired timestamp (expect 401)
     * Raw query token `?token=...` with valid token but no signature and no Authorization header (expect 401)
     * Authorization: Bearer <valid_token> header (expect 200)
     * Authorization: Bearer <invalid_token> header (expect 401)
4. **Execute Empirical Verification**:
   - Run tests and empirical scripts as needed.
   - Confirm whether solution withstands adversarial probing.

Write your report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m1_1/handoff.md`.
Report back when finished using `send_message`.


## 2026-10-07T02:27:59Z
Sender: 8e495d40-6bfb-4f59-aa93-c010dd3bbc37
Priority: MESSAGE_PRIORITY_HIGH
Content:
You are Challenger 1 for Milestone 1: Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14).
Your assigned working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m1_1

MANDATORY USER REQUEST:
Read /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Also read:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1/handoff.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m1_1/DISPATCH.md

Perform adversarial verification and stress testing against SEC-11, SEC-12, and SEC-14:
- Test BOLA/IDOR on attendance summaries with non-manager users (expect 403)
- Test WebSocket channel auth on notifications.{userId} and rejection of legacy global channel
- Test signed media routes (/api/media/{path}) with valid, tampered, expired signatures, Bearer headers, and plain ?token= parameters (expect 401 on unsigned query token)
- Run php artisan test

Provide your empirical findings and confirmation of correctness.
Write your report to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m1_1/handoff.md
Notify parent with send_message when complete.
