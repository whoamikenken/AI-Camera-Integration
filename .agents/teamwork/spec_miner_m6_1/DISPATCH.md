# DISPATCH DIRECTIVE — spec_miner_m6_1

## Identity
- **Agent:** `spec_miner_m6_1`
- **Role:** Specification Miner (Milestone M6: API Uniformity, Form Requests, Scramble OpenAPI & Composables)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Authoritative Inputs to Read
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 4 & 5: API Uniformity & Documentation, and Frontend Composables & Reactive Architecture)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6: Features #34 through #41)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (Milestone 6 section)
5. Test Suites:
   - `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (inspect `test_f34` through `test_f41`)
   - `tests/Feature/E2E/Tier2BoundaryTest.php`
   - `tests/Feature/E2E/Tier3CrossFeatureTest.php`
   - `tests/Feature/E2E/Tier4RealWorldScenariosTest.php`

---

## Specification Mining Scope
Extract exhaustive specifications, constraints, method signatures, status codes, route paths, and contracts for:
1. **Feature 34 (Standard API Response Envelope)**:
   - Helper class/trait `ApiResponse` (`success`, `data`, `message`, `meta`, `status`).
   - How tests expect both envelope keys (`'data'`, `'success'`) and backward-compatible direct attributes.
2. **Feature 35 (Hardware Webhook Protocol Exemption)**:
   - Which endpoints (e.g. `/Subscribe/*`, `api/devices/subscribe`, etc.) must strictly preserve raw camera protocol JSON and bypass envelope wrapping.
3. **Feature 36 (Dedicated Form Requests)**:
   - Which 24 Form Request classes exist vs need to be created across entities (Employee, Device, Shift, Visitor, Leave, Attendance, AccessGroup).
4. **Feature 37 (Automated OpenAPI Documentation)**:
   - Dedoc Scramble configuration, route `/docs/api`, Bearer token security definition.
5. **Features 38, 39, 40 (Frontend Composables)**:
   - `usePaginatedResource.js`: params, return properties, debounce, universal response parsing.
   - `useLiveTelemetryStream.js`: Echo channel listener, audio chime integration, notification hooks.
   - `useBiometricCapture.js`: webcam stream, 1:1 square crop, aspect ratio, base64 data URL.
6. **Feature 41 (Frontend View Refactoring)**:
   - Which views consume the composables and specific UI requirements.

Write findings to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1/handoff.md` and notify parent via `send_message`.


## 2026-10-09T00:37:34Z
You are spec_miner_m6_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Areas 4 & 5)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M6: Features #34 through #41)
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md (Milestone 6 section)
5. Inspect the test suites:
   - tests/Feature/E2E/Tier1FeatureCoverageTest.php (test_f34 through test_f41)
   - tests/Feature/E2E/Tier2BoundaryTest.php
   - tests/Feature/E2E/Tier3CrossFeatureTest.php
   - tests/Feature/E2E/Tier4RealWorldScenariosTest.php

Extract exhaustive specifications, constraints, method signatures, return structures, and contracts for Milestone M6.
Write your findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1/handoff.md and notify parent via send_message.
