# DISPATCH — E2E Test Writer: Requirement-Driven Opaque-Box Test Track

## Objective
Design and implement the comprehensive, requirement-driven E2E test suite for the Intelligent AI Camera Hub platform evolution per the Project Pattern Dual Track:
1. Create `TEST_INFRA.md` at project root (`/home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md`) following the template in Project Pattern:
   - Test philosophy: Opaque-box, requirement-driven.
   - Feature inventory: Mapping all 43 features across Tiers 1–4.
   - Test architecture and runner instructions.
2. Implement systematic test suites in `tests/Feature/E2E/`:
   - **Tier 1 - Feature Coverage (>=5 per feature)**: Equivalence class representative tests verifying each feature in isolation.
   - **Tier 2 - Boundary & Corner Cases (>=5 per feature)**: Boundary conditions, empty inputs, extreme durations, invalid states, max-size chunks.
   - **Tier 3 - Cross-Feature Combinations (pairwise coverage)**: Access control + telemetry, leave cancellation + attendance recalculation, visitor overstay + security alerts, bulk campaigns + downlink correlation.
   - **Tier 4 - Real-World Application Scenarios**: Multi-building facility with access zones, emergency shifts with leave cancellations, large workforce bulk onboarding.
3. Once all tests and infrastructure are in place, publish `TEST_READY.md` at `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md`.

## Mandatory Files to Read First
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (read header ## 2026-10-07T01:57:58Z)
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8/PROJECT.md`
- `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`

## File Ownership
You exclusively own:
- `/home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md`
- `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md`
- `tests/Feature/E2E/*`

## Completion Criteria
1. `TEST_INFRA.md` created with full 4-tier coverage methodology.
2. E2E test cases created in `tests/Feature/E2E/`.
3. `TEST_READY.md` published at project root.
4. Write handoff report in your working directory and notify parent via `send_message`.


## 2026-10-07T02:14:44Z
You are test_writer_e2e.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/test_writer_e2e
Your instructions are in: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/test_writer_e2e/DISPATCH.md

You MUST read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (specifically the user request under header ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/system-evo.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/test_writer_e2e/DISPATCH.md

Design the opaque-box, requirement-driven E2E test track: create TEST_INFRA.md, write E2E test cases across Tiers 1-4 covering all 43 features in tests/Feature/E2E/, and publish TEST_READY.md when complete.
Write a full handoff report in handoff.md and notify parent via send_message.
