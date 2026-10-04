# Stage 2 Worker Dispatch: Security Remediation (SEC-01 through SEC-10)

## Objectives
You are the Security Pipeline Worker. Your role is to formulate explicit Jules briefs, dispatch them via `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`, track the remote sessions, pull/teleport the proposed patches (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`), validate with `php artisan test` and `npm run build`, and mark the corresponding tasks in `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` from `- [ ]` to `- [x]`.

## Mandatory Reading
First read:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
and
`/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`

## Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Detailed Tasks to Dispatch via Jules

### 1. SEC-01
- Target: `app/Http/Controllers/HttpWebhookController.php`
- Brief:
```
[SEC-01] Remove Hardcoded Backdoor Secret & Enforce Mandatory Authentication in Camera Webhooks
Objective: Eliminate backdoor credentials and prevent unauthenticated creation of rogue camera devices.
Target File: app/Http/Controllers/HttpWebhookController.php
Actions:
1. In authenticateWebhook(), remove the hardcoded fallback check '|| $headerSecret === \'valid-camera-secret\''.
2. In handleHeartbeat(), ensure requests are rejected with 401/403 if CAMERA_WEBHOOK_SECRET is unset or if credentials fail or if device is not pre-registered.
3. Prohibit automatic Device::create from unverified webhook heartbeats. Ensure all tests pass.
```

### 2. SEC-02 & SEC-10
- Target: `routes/api.php`, `app/Http/Controllers/AccessLogController.php`, `app/Http/Controllers/StrangerSnapController.php`, `app/Http/Controllers/SyncTaskController.php`
- Brief:
```
[SEC-02 & SEC-10] Add Permission Middleware to Telemetry Endpoints and Rate Limit Public Settings
Objective: Align REST API access control with Reverb WebSocket channel policies and rate limit public endpoints.
Target Files: routes/api.php, app/Http/Controllers/AccessLogController.php, app/Http/Controllers/StrangerSnapController.php, app/Http/Controllers/SyncTaskController.php
Actions:
1. Attach 'permission:attendance.view,devices.view' to GET /api/access-logs and GET /api/access-logs/{accessLog}.
2. Attach 'permission:devices.view' (or 'role:security,admin,super-admin') to GET /api/stranger-snaps and GET /api/stranger-snaps/{strangerSnap}.
3. Attach 'permission:devices.manage' to GET /api/sync-tasks.
4. Attach 'permission:devices.view,attendance.view' to GET /api/stats.
5. Attach 'throttle:60,1' to Route::get('settings/public', ...).
Ensure all routes and tests pass.
```

### 3. SEC-03
- Target: `app/Http/Controllers/LeaveController.php`, `app/Http/Controllers/RegularizationController.php`
- Brief:
```
[SEC-03] Enforce Tenant/User Scoping on Leave and Regularization Listing (BOLA/IDOR)
Objective: Prevent non-manager employees from viewing company-wide leave requests and regularization entries.
Target Files: app/Http/Controllers/LeaveController.php, app/Http/Controllers/RegularizationController.php
Actions:
1. In LeaveController::listRequests(), check if user has 'leaves.manage' or management roles ('admin', 'hr-manager', 'super-admin'). If not, force $query->where('employee_id', $user->employee?->id).
2. In LeaveController::listBalances(), constrain queries for non-managers to their own employee ID ($user->employee?->id).
3. In RegularizationController::index(), check if user has 'attendance.manage' or management roles. If not, force $query->where('employee_id', $user->employee?->id).
Ensure leave and regularization tests pass.
```

### 4. SEC-04 & SEC-07
- Target: `app/Services/ImageStorageService.php`, `config/filesystems.php`, `routes/api.php`
- Brief:
```
[SEC-04 & SEC-07] Migrate Biometric Image Ingestion to Private Disk and Constrain Media Route Path Traversal
Objective: Prevent unauthenticated access to facial snapshots and directory traversal in media streaming.
Target Files: app/Services/ImageStorageService.php, config/filesystems.php, routes/api.php
Actions:
1. Update default disk in ImageStorageService from 'public' to the configured biometric disk ($this->getDisk(), defaulting to 'biometrics').
2. Ensure storeBase64Image(), storeFromBase64(), storeUploadedImage(), and storeFromUrlOrPath() write to $this->getDisk().
3. In ImageStorageService::getMedia() (or controller for /api/media/{path}), strictly prevent directory traversal sequences ('..') and ensure clean path matches allowed subdirectory prefixes ('personnel/', 'snaps/', 'scenes/', 'verification_snaps/', 'verification_scenes/', 'visitors/').
Ensure media and storage tests pass.
```

### 5. SEC-05, SEC-06 & SEC-08
- Target: `app/Http/Controllers/VisitorController.php`, `app/Http/Controllers/AuthController.php`, `routes/web.php`, `bootstrap/app.php`
- Brief:
```
[SEC-05, SEC-06 & SEC-08] Remove Visitor Mock Creation, Revoke Tokens on Password Change, Decommission Dev Mock Route
Objective: Eliminate mock entity creation, revoke tokens on password change, disable dev mock route in production.
Target Files: app/Http/Controllers/VisitorController.php, app/Http/Controllers/AuthController.php, routes/web.php, bootstrap/app.php
Actions:
1. SEC-05: In VisitorController::block(), replace Visitor::create(['id' => $id, ...]) with Visitor::findOrFail($id).
2. SEC-06: In AuthController::changePassword() and updateProfile() (when password changed), revoke active personal access tokens: $user->tokens()->delete().
3. SEC-08: In routes/web.php, wrap Route::post('/action/{operator}', ...) in if (app()->environment('local', 'testing')). In bootstrap/app.php, restrict CSRF exception for 'action/*' to non-production environments.
Ensure auth, visitor, and web route tests pass.
```

### 6. SEC-09
- Target: `package.json`, `package-lock.json`, `composer.json`, `composer.lock`
- Brief:
```
[SEC-09] Patch Vulnerabilities in NPM and Composer Dependencies
Objective: Mitigate known CVEs in NPM and Composer packages (axios, league/commonmark, etc.).
Target Files: package.json, package-lock.json, composer.json, composer.lock
Actions:
1. In package.json, upgrade axios to ^1.21.0 or safe patched version, run npm install or audit fix.
2. In composer.json, upgrade league/commonmark and dependencies to patched versions.
3. Run npm run build and php artisan test to ensure zero regressions.
```

## Workflow Execution Steps
1. Dispatch Jules sessions using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
   (You can dispatch in batches or all together, recording the returned session IDs).
2. Monitor session status via `jules remote list --session`.
3. When sessions complete, pull or inspect each patch:
   `jules remote pull --session <ID> --apply` (or `jules teleport <ID>`).
4. Validate changes:
   Run `php artisan test` and `npm run build`.
   If any patch causes conflicts or test failures, fix the conflict or adjust cleanly without leaving working tree dirty.
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`:
   Mark verified completed tasks from `- [ ]` to `- [x]`.
6. Write a detailed handoff report in:
   `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage2_security/handoff.md`
   including:
   - Manifest of all Jules session IDs, brief summaries, and execution statuses.
   - Verification command outputs (`php artisan test`, `npm run build`).
   - List of all checkboxes updated in `tasks-security.md`.
7. Send a message to orchestrator upon completion.

## 2026-10-04T01:37:42Z
[Message] timestamp=2026-10-04T01:37:42Z sender=d38180be-e3f6-470b-a1ae-6855a7f08869 priority=MESSAGE_PRIORITY_HIGH
Content:
You are the Worker subagent responsible for executing Stage 2: Security Remediation (SEC-01 through SEC-10) using autonomous Jules CLI sessions.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage2_security

Read your dispatch instructions carefully:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage2_security/DISPATCH.md

MANDATORY: Read the original user request before starting:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your responsibilities:
1. Formulate and dispatch Jules sessions for SEC-01 through SEC-10 using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
2. Monitor session progress using `jules remote list --session`.
3. Retrieve / apply patches using `jules remote pull --session <ID> --apply` (or `jules teleport <ID>`).
4. Validate changes with `php artisan test` and `npm run build`.
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` by checking off verified items `- [x]`.
6. Document all Jules session IDs, brief summaries, pull statuses, test results, and verified tasks in:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage2_security/handoff.md
Once complete, send a message to your parent with your findings and results.
