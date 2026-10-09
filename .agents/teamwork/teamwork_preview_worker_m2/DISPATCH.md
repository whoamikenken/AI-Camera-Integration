# DISPATCH — Milestone M2 Worker

## Task
Implement Milestone M2: Granular Access Control Groups & Zone-Based Dispatching.

## Explorer Findings & Authoritative References
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_10/PROJECT.md`
- `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_1/handoff.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_2/handoff.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_3/handoff.md`

## File Ownership
- `database/migrations/2026_10_07_000002_create_access_groups_table.php` (new)
- `app/Models/AccessGroup.php` (new)
- `database/factories/AccessGroupFactory.php` (new)
- `app/Models/Department.php` (modify to add organization fallback hook & accessGroups relation)
- `app/Models/Device.php` (modify to add accessGroups relation)
- `app/Models/Personnel.php` (modify to add accessGroups relation)
- `app/Models/Organization.php` (modify to add accessGroups relation)
- `app/Services/AccessControlService.php` (new)
- `app/Jobs/SyncPersonnelJob.php` (modify to use AccessControlService with optional second param)
- `app/Http/Controllers/AccessGroupController.php` (new)
- `routes/api.php` (modify to register access-groups routes)
- `resources/js/components/settings/AccessGroupManager.vue` (new)
- `resources/js/components/settings/SettingsHub.vue` (modify to add Access Groups tab)

## Verification Commands
- `php artisan test --filter="test_f0[5-9]|test_f1[0-2]"`
- `php artisan test --filter=test_boundary_access_group`
- `php artisan test --filter=test_cross_access_control`
- `php artisan test --filter=PersonnelSyncTest`
- `npm run build`

## 2026-10-07T07:09:39Z
You are teamwork_preview_worker_m2 implementing Milestone M2: Granular Access Control Groups & Zone-Based Dispatching.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m2

Authoritative references:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_10/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/system-evo.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m2/DISPATCH.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_1/handoff.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_2/handoff.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_3/handoff.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your implementation deliverables:
1. Migration database/migrations/2026_10_07_000002_create_access_groups_table.php
2. Update app/Models/Department.php
3. Create app/Models/AccessGroup.php, update Device.php, Personnel.php, Organization.php
4. Create database/factories/AccessGroupFactory.php
5. Create app/Services/AccessControlService.php
6. Update app/Jobs/SyncPersonnelJob.php
7. Register routes in routes/api.php
8. Create app/Http/Controllers/AccessGroupController.php
9. Create resources/js/components/settings/AccessGroupManager.vue and update SettingsHub.vue
