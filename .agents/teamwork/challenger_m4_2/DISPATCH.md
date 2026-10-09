# DISPATCH DIRECTIVE — challenger_m4_2

## Identity
- Archetype: teamwork_preview_challenger
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Adversarially challenge hardware failure modes, offline device handling, access control scoping, and cross-feature interactions of Milestone M4 (Bulk Workforce Operations & Fleet Provisioning Campaigns).

## Authoritative Inputs
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/handoff.md`

## Adversarial Challenges
1. **Offline & Inactive Device Resilience**:
   - Inactive device in `BulkDeviceCampaignJob`: does it fail gracefully without crashing the whole campaign?
   - Missing device in `BulkDeviceCampaignJob`: does it increment `failed_items` and continue?
2. **Access Control Scoping on Bulk Sync**:
   - Verify that `BulkPersonnelSyncJob` properly invokes `AccessControlService::getAuthorizedDevicesForPersonnel()` when no specific target device is passed.
   - Verify that personnel are only provisioned to authorized devices.
3. **Audit Trail Verification**:
   - Verify that `SyncTask` records are created for each personnel-device synchronization.
4. **Cross-Feature Tests**:
   - `php artisan test --filter="Tier3CrossFeatureTest"`
   - `php artisan test --filter="Tier4RealWorldScenariosTest"`
   - `php artisan test --filter=E2E`

Deliver your empirical verdict (`APPROVE` or `REQUEST_CHANGES`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2/handoff.md` and notify parent via `send_message`.


## 2026-10-08T22:45:59Z
You are challenger_m4_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 5)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/handoff.md
5. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2/DISPATCH.md

Empirically challenge hardware failure modes, offline device handling, access control scoping, and cross-feature interactions.
Run tests:
php artisan test --filter="Tier3CrossFeatureTest"
php artisan test --filter="Tier4RealWorldScenariosTest"
php artisan test --filter=E2E

Deliver verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m4_2/handoff.md and notify parent via send_message.
