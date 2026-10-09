# DISPATCH DIRECTIVE — Challenger 1 (M3 Invariants & Concurrency)

## Identity & Role
- **Agent**: `teamwork_preview_challenger_m3_11_1`
- **Archetype**: `teamwork_preview_challenger`
- **Role**: State Machine Invariants & Adversarial Challenger for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md`

## Challenge Objective
Empirically stress-test and challenge Milestone M3 implementations:
1. **Leave State Invariants**:
   - Double-cancellation attack: Attempting to cancel an already-cancelled leave must fail (HTTP 422).
   - Rejected leave cancellation attack: Attempting to cancel a rejected leave must fail (HTTP 422).
   - Balance conservation invariant: Total balance (`allocated + carried_over = used + pending + available`) must hold before and after cancellation.
   - Partial-day leave cancellation (0.5 day): Balance must restore exactly 0.5.
   - Attendance rollback: Ensure non-leave days / punches on the cancelled leave dates are accurately re-evaluated.
2. **Regularization Invariants**:
   - Cancel approved regularization: Must fail (HTTP 422).
   - Cancel rejected regularization: Must fail (HTTP 422).
3. **Execute and Author Challenge Tests**:
   - Run existing challenge suites:
     `php artisan test tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/AdversarialMilestone3CspDependencyTest.php tests/Feature/Phase6Milestone3Challenger2Test.php`
   - Write any edge-case stress probes if necessary to empirically verify invariants.

Deliver confirmation of correctness (verdict `APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_1/handoff.md` and notify parent via `send_message`.

## 2026-10-08T06:44:40Z
[Message] timestamp=2026-10-08T06:44:40Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH content=You are teamwork_preview_challenger_m3_11_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1/handoff.md

Empirically challenge state machine invariants and concurrency:
- Double cancellation attacks (422)
- Cancellation of rejected requests (422)
- Balance conservation invariant (allocated + carried_over = used + pending + available)
- Run tests: php artisan test tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/AdversarialMilestone3CspDependencyTest.php tests/Feature/Phase6Milestone3Challenger2Test.php

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_challenger_m3_11_1/handoff.md and notify parent via send_message.
