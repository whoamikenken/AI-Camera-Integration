# DISPATCH — Spec Miner Survey 1: Domain & Lifecycle Features

## Objective
Investigate and map the full specification, current codebase implementation, and gap analysis for:
- **R1: Granular Access Control Groups & Zone-Based Dispatching** (Feature 1 in `system-evo.md`):
  - Current personnel sync behavior (`SyncPersonnelJob`, `PersonnelObserver`, `Device` queries).
  - Current models, schemas, and pivots needed (`access_groups`, `access_group_device`, `access_group_personnel`, `access_group_department`).
  - Required services (`AccessControlService`), events, routes (`/api/access-groups`), and frontend requirements (`AccessGroupManager.vue`).
- **R2: Resilient Domain Lifecycle State Machines** (Feature 2 in `system-evo.md`):
  - Leaves: cancellation workflow, balance restoration in `LeaveService`, attendance status rollback/recalculation.
  - Regularizations: cancellation before approval.
  - Visits: cancellation, overstay detection (`DetectOverstayVisitorsJob`), no-show expiration (`ExpireNoShowVisitsJob`), schema expansions (`cancelled`, `no_show`, `overstayed`, cancellation reasons/timestamps).
  - Scheduled jobs, alerts, notifications, and UI entry points.
- **R3: Bulk Workforce Operations & Fleet Provisioning Campaigns** (Feature 5 in `system-evo.md`):
  - Bulk campaigns table schema (`bulk_campaigns`), status tracking, chunking, and batching.
  - Bulk device maintenance (reboot, MQTT config updates).
  - Bulk personnel sync (`AddPersons` batch payloads up to 50 persons) and bulk deletion.
  - Controllers, routes, and UI toolbar hooks in `DeviceManager.vue` and `PersonnelManager.vue`.

## Authoritative Inputs
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (read header ## 2026-10-07T01:57:58Z)
- `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`
- Existing codebase in `app/`, `database/migrations/`, `routes/api.php`, `resources/js/`.

## Output Requirements
Write your detailed report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/analysis.md`
and write your handoff to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/handoff.md`.
Enumerate all required features, concrete files to create/modify, existing code patterns to adhere to, dependencies, and risk areas.
Communicate completion back to orchestrator via `send_message`.


## 2026-10-07T02:01:59Z
You are spec_miner_survey_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1
Your task instructions are in: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/DISPATCH.md
You MUST read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (specifically the user request under header ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/DISPATCH.md

Investigate the codebase and authoritative specifications for R1 (Access Control Groups & Zone-Based Dispatching), R2 (Resilient Domain Lifecycle State Machines for Leaves, Regularizations & Visits), and R3 (Bulk Workforce Operations & Fleet Provisioning Campaigns).
Produce a comprehensive analysis in analysis.md and a self-contained handoff in handoff.md in your working directory.
Communicate completion back to caller via send_message.
