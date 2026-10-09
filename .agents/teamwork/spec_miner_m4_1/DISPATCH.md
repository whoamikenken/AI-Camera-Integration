# DISPATCH DIRECTIVE — spec_miner_m4_1

## Identity
- Archetype: teamwork_preview_spec_miner
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Extract exhaustive behavioral specifications, API contracts, database schema requirements, and test expectations for Milestone M4 (Bulk Workforce Operations & Fleet Provisioning Campaigns: Features #20 through #26).

## Mandatory First Step
Read the following authoritative documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5: Bulk Workforce Operations & Fleet Provisioning Campaigns)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Features 20–26, Milestone M4)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (Section 5 item 4: M4 test filters)
5. `/home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md` (Feature mapping and test methodology)
6. Inspect the existing E2E test files:
   - `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (`test_f20` through `test_f26`)
   - `tests/Feature/E2E/Tier2BoundaryTest.php` (tests covering 50-person chunk boundary, empty device lists)
   - `tests/Feature/E2E/Tier3CrossFeatureTest.php` (tests covering bulk campaigns)
   - `tests/Feature/E2E/Tier4RealWorldScenariosTest.php` (`test_scenario_8`: 120-person onboarding campaign [50, 50, 20])

## Investigation Requirements
1. **Bulk Campaign Tracking Entity (`bulk_campaigns` & `BulkCampaign`)**:
   - Table columns, data types, nullability, defaults, indexes.
   - Model attributes, fillables, casts, status enums (`pending`, `processing`, `completed`, `failed`).
   - Campaign types (`sync_personnel`, `reboot_fleet`, `update_mqtt_config`, `delete_personnel`, `shift_assignment`).
2. **API Routes & Contract Specifications**:
   - `POST /api/devices/bulk-reboot`: request payload (`device_ids`), response, validation (reject empty array with 422), campaign ticket response.
   - `POST /api/devices/bulk-sync-mqtt`: request payload (`device_ids`, `config`), response, validation.
   - `POST /api/personnel/bulk-sync`: request payload (`personnel_ids`, `device_ids` or target resolution), response, chunking requirement (max 50 persons per packet).
   - `POST /api/personnel/bulk-delete`: request payload (`personnel_ids`), response, edge hardware deletion commands.
   - `GET /api/bulk-campaigns/{id}`: progress response (`total_items`, `processed_items`, `failed_items`, `status`, `progress_percent`).
3. **Camera Protocol Downlink Payloads**:
   - Batch face add: `operator: "AddPersons"`, `PersonNum: N`, `Personinfo_0: {...}`, up to 50 persons per command.
   - Batch face delete: `operator: "DelPerson"` / `"DeletePersons"`.
   - Fleet reboot: `operator: "RebootDevice"`.
   - MQTT config update: `operator: "UpMQTTconfig"`.
4. **UI Integration Specifications**:
   - `DeviceManager.vue`: selection checkboxes, select-all, batch actions toolbar.
   - `PersonnelManager.vue`: selection checkboxes, select-all, batch sync & delete toolbar.

## Output
Write your comprehensive specification report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1/handoff.md`

When complete, notify parent via `send_message` with summary and path.


## 2026-10-08T18:40:46Z
You are spec_miner_m4_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1/DISPATCH.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under ## 2026-10-07T01:57:58Z)
3. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 5: Bulk Workforce Operations & Fleet Provisioning Campaigns)
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M4, Features #20-#26)
5. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md (Milestone 4 section)
6. /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md
7. Inspect the test suites:
   - tests/Feature/E2E/Tier1FeatureCoverageTest.php (test_f20 through test_f26)
   - tests/Feature/E2E/Tier2BoundaryTest.php (50-person chunk boundary, empty device lists)
   - tests/Feature/E2E/Tier3CrossFeatureTest.php
   - tests/Feature/E2E/Tier4RealWorldScenariosTest.php (test_scenario_8: 120-person onboarding campaign [50, 50, 20])

Extract exhaustive behavioral specifications, states, columns, routes, payloads, status codes, chunking requirements (max 50 per AddPersons command), and error conditions.
Write your findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message with a summary and link to handoff.md.
