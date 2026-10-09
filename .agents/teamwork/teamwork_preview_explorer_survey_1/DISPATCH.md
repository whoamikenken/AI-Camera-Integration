# Dispatch Directive: Explorer Survey 1 (Access Control & Authorization)

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1`

## Authoritative Reference
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
`/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`

## Assignment
Investigate the current codebase for SEC-11, SEC-12, and SEC-14:
1. **SEC-11**: Check `app/Http/Controllers/EmployeeController.php` (`attendanceSummary`) and `routes/api.php`. Analyze how BOLA/IDOR can occur, how `$user->hasPermission` and managerial roles work, and how to restrict queries to authenticated user's employee ID.
2. **SEC-12**: Check `app/Events/NotificationCreated.php`, `routes/channels.php`, and `resources/js/App.vue`. Analyze how notifications are currently broadcast, how channel authorization is defined, and what changes are required to isolate broadcasting to `private-notifications.{user_id}` and update Echo client listeners.
3. **SEC-14**: Check `app/Http/Middleware/AuthenticateQueryToken.php`, `bootstrap/app.php`, `routes/api.php`, and `resources/js/utils/media.js`. Analyze where query-token authentication is registered, how `/api/media/{path}` is handled, how to replace query token auth with Laravel signed route (`URL::temporarySignedRoute` or `signed` middleware) and/or standard Authorization Bearer header, and how frontend media loading works.
4. Check existing tests in `tests/Feature/` that exercise these areas, note any existing failures or gaps.

## Output
Write your findings and evidence chain to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/handoff.md`.
Report back when finished.


## 2026-10-07T01:50:26Z
You are Explorer Survey 1 investigating Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14).
Your assigned working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1

MANDATORY: You MUST read the authoritative user request at:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Also read:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/DISPATCH.md

Investigate the following security findings in the codebase:
1. SEC-11 (Critical): BOLA/IDOR on Attendance Summary Endpoint
   - Inspect app/Http/Controllers/EmployeeController.php (specifically attendanceSummary) and routes/api.php.
   - Trace how selfservice.view and employees.view permissions interact.
   - Determine how to restrict access: non-manager employees must be restricted to their own employee ID ($user->employee?->id === (int) $id); unauthorized requests must receive 403 Forbidden.
2. SEC-12 (Critical): Isolate Real-Time WebSocket Notification Broadcasting to Per-User Channels
   - Inspect app/Events/NotificationCreated.php, routes/channels.php, and resources/js/App.vue.
   - Trace the broadcast channel definition (PrivateChannel('notifications') vs PrivateChannel('notifications.' . $user_id)).
   - Determine channel authorization requirements and frontend echo.private listener updates.
3. SEC-14 (High): Deprecate URL Query-String Token Auth in Favor of Signed Media Routes
   - Inspect app/Http/Middleware/AuthenticateQueryToken.php, bootstrap/app.php, routes/api.php, and resources/js/utils/media.js.
   - Trace how media URLs are generated and authenticated.
   - Design the transition to temporary signed routes (URL::temporarySignedRoute / signed middleware) or Authorization Bearer header, and deprecate query token middleware.

Also check relevant test files in tests/Feature/.
Write your comprehensive investigation report to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/handoff.md
When finished, notify your parent with send_message.
