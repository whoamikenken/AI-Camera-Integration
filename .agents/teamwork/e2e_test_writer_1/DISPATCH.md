# Task Assignment: E2E Testing Track

## Identity & Context
- Agent: teamwork_preview_test_writer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/e2e_test_writer_1
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Design and implement the comprehensive opaque-box E2E test suite for the Intelligent AI Camera Hub to Attendance & Visitor Management System transformation.

Read the authoritative requirements:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/tasks.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

## Methodology (4-Tier Requirement-Driven)
1. **Tier 1 — Feature Coverage (>=5 per feature)**: Isolated happy-path tests for every feature in `PROJECT.md § Feature Inventory`.
2. **Tier 2 — Boundary & Corner Cases (>=5 per feature)**: Limits, empty inputs, max sizes, invalid transitions, zero/negative, edge times.
3. **Tier 3 — Cross-Feature Combinations (Pairwise)**: Interaction tests (e.g. employee shift change during active attendance day; visitor check-in with temporary camera face sync; leave approval overriding attendance status; blocked visitor detection).
4. **Tier 4 — Real-World Application Scenarios**: Multi-step workflows (e.g. complete workday cycle from camera punch to attendance record to overtime to monthly payroll export; complete visitor lifecycle from pre-registration to check-in to badge to checkout with camera revocation).

## Deliverables
1. Create `/home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md` at project root matching the template in the Project Pattern.
2. Implement test files under `tests/Feature/E2E/` (e.g. `Tier1FeatureCoverageTest.php`, `Tier2BoundaryTest.php`, `Tier3CrossFeatureTest.php`, `Tier4RealWorldScenariosTest.php`).
3. Run tests using `php artisan test --filter=E2E` to verify test harness structure.
4. When complete, publish `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` summarizing total test counts, tier breakdown, and runner command.
5. Write your handoff to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/e2e_test_writer_1/handoff.md` and message parent when complete.

## 2026-09-29T15:56:59Z
You are assigned as the E2E Test Writer for the Dual Track E2E Testing Track of the Intelligent AI Camera Hub to Attendance & Visitor Management System transformation.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/e2e_test_writer_1

Your detailed instructions are in your DISPATCH.md file. Read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md
4. /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

Deliverables:
- Create /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md based on the Project Pattern template.
- Implement comprehensive requirement-driven test cases across Tiers 1-4 under tests/Feature/E2E/.
- Run php artisan test --filter=E2E to verify the suite.
- Publish /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md when complete.
- Write a self-contained handoff to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/e2e_test_writer_1/handoff.md.
- Send a completion message to parent when done.

