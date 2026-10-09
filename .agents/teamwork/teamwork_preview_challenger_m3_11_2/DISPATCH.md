# DISPATCH DIRECTIVE — Challenger 2 (M3 Boundary & Overstay Thresholds)

## Identity & Role
- **Agent**: `teamwork_preview_challenger_m3_11_2`
- **Archetype**: `teamwork_preview_challenger`
- **Role**: Boundary, Overstay & Security Challenger for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_2`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md`

## Challenge Objective
Empirically challenge Visitor lifecycle and background jobs:
1. **Visitor Overstay Grace Boundary**:
   - Visit with `expected_departure = now() - 14m`: Must NOT be flagged as overstayed.
   - Visit with `expected_departure = now() - 16m`: MUST be flagged as overstayed with `DeviceAlert` created.
   - Duplicate alert suppression: Running `DetectOverstayVisitorsJob` repeatedly must NOT create duplicate alerts for the same visit once alerted.
2. **Visitor Cancellation Face Revocation**:
   - Verify that cancelling an expected or checked-in visit immediately dispatches camera de-provisioning (`revokeVisitorFace()` targeting the device turnstiles).
   - Cancelling an already-cancelled or checked-out visit must fail (HTTP 422).
3. **Route Precedence & Endpoint Tests**:
   - Request `GET /api/visits/overstayed`: Verify it does not hit `GET /api/visits/{id}` route binding.
4. **Execute Verification Suites**:
   - `php artisan test --filter="test_boundary_.*visit"`
   - `php artisan test --filter=VisitorManagementTest`
   - `php artisan test --filter=Phase6Milestone3Challenger2Test`

Deliver confirmation of correctness (verdict `APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_2/handoff.md` and notify parent via `send_message`.


## 2026-10-08T06:44:40Z
You are teamwork_preview_challenger_m3_11_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md

Empirically challenge boundary conditions, overstay thresholds, and edge hardware sync:
- Overstay grace threshold: exactly 15 minutes
- Duplicate alert suppression
- Camera face whitelist de-provisioning on cancellation
- Route precedence: GET /api/visits/overstayed
- Run tests: php artisan test --filter="test_boundary_.*visit", php artisan test --filter="Phase6Milestone3Challenger2Test"

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_2/handoff.md and notify parent via send_message.
