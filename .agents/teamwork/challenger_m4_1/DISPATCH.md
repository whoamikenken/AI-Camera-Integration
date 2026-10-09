# DISPATCH DIRECTIVE — challenger_m4_1

## Identity
- Archetype: teamwork_preview_challenger
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Empirically verify and stress-test the state invariants, boundary limits, and mathematical calculations of Milestone M4 (Bulk Workforce Operations & Fleet Provisioning Campaigns).

## Authoritative Inputs
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/handoff.md`

## Invariant Challenges
1. **50-Person Chunk Boundary Invariant**:
   - Verify that 50 items partition into 1 chunk of 50.
   - Verify that 51 items partition into 2 chunks: [50, 1].
   - Verify that 100 items partition into 2 chunks of 50.
   - Verify that 120 items partition into 3 chunks: [50, 50, 20].
2. **Empty Input Rejection Invariant**:
   - `POST /api/devices/bulk-reboot` with `device_ids: []` -> strictly HTTP 422.
   - `POST /api/personnel/bulk-delete` with `personnel_ids: []` -> strictly HTTP 422.
   - `POST /api/personnel/bulk-sync` with `personnel_ids: []` -> strictly HTTP 422.
3. **Progress Calculation Clamping Invariant**:
   - `total_items = 0` -> strictly 0% (no division by zero).
   - `processed_items = 0, total_items = 10` -> 0%.
   - `processed_items = 5, total_items = 10` -> 50%.
   - `processed_items = 10, total_items = 10` -> 100%.
   - Clamped strictly between 0 and 100.
4. **Run Verification Commands**:
   - `php artisan test --filter="test_boundary_bulk"`
   - `php artisan test --filter="test_scenario_8"`
   - `php artisan test --filter="test_f2[0-5]"`

Deliver your empirical verdict (`APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1/handoff.md` and notify parent via `send_message`.

## 2026-10-08T22:45:59Z
You are challenger_m4_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 5)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/handoff.md
5. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1/DISPATCH.md

Empirically challenge state invariants, 50-chunking partitioning, 422 validations on empty inputs, and progress calculation clamping.
Run tests:
php artisan test --filter="test_boundary_bulk"
php artisan test --filter="test_scenario_8"
php artisan test --filter="test_f2[0-5]"

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_1/handoff.md and notify parent via send_message.
