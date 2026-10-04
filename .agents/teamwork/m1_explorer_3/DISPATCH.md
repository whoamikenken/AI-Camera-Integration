# Task Assignment: Milestone 1 — Frontend Auth, API Client & Settings Exploration

## Identity & Context
- Agent: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_3
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Investigate and design the implementation blueprint for Features 3, 4, 6 of Milestone 1:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.1, §1.3, §10.1, §10.2
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/frontend_explorer_survey_3/survey_frontend_report.md

Investigate:
1. Centralized API Client: Implementation of `resources/js/api/client.js` with Bearer token injection, CSRF handling, 401 redirection to login, 403 permission warnings, and error notifications.
2. Pinia Auth Store: `resources/js/stores/authStore.js` with login, logout, current user, roles, permissions, `hasRole()`, `can()`.
3. Login & Authentication Flow: `resources/js/views/LoginPage.vue` and auth gate in `resources/js/App.vue`.
4. Management Views:
   - Organization/Department/Designation/Location management (`resources/js/components/settings/DepartmentManager.vue` or integrated settings)
   - `resources/js/components/settings/SystemSettings.vue` (admin settings toggles)
   - `resources/js/components/settings/AuditLogViewer.vue` (filterable audit trail)
5. Navigation Update: Integrating Settings tab into `App.vue` and user profile dropdown in header.
6. Build Verification: Ensuring `npm run build` succeeds cleanly.

Write your detailed design and recommendation to `m1_frontend_design.md` and a self-contained `handoff.md`. Notify parent when complete.

## 2026-09-29T15:57:00Z
You are assigned as Explorer 3 for Milestone 1: Frontend Auth, API Client & Settings UI.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_3

Your detailed instructions are in your DISPATCH.md file. Read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.1, §1.3, §10.1, §10.2
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/frontend_explorer_survey_3/survey_frontend_report.md

Investigate and produce the implementation blueprint for:
- Centralized API client (resources/js/api/client.js) with Bearer token & interceptors
- Pinia auth store (resources/js/stores/authStore.js)
- Login page (resources/js/views/LoginPage.vue) and App.vue auth gate
- Settings UI views: DepartmentManager.vue, SystemSettings.vue, AuditLogViewer.vue
- App.vue navigation update and user profile header

Write findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_3/m1_frontend_design.md
Write a self-contained handoff to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_3/handoff.md
Send a completion message to parent when done.
