# Dispatch Directive: Worker Milestone 1 (Access Control & Authorization Hardening)

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1`

## Authoritative User Request & Investigation Blueprint
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (Section ## 2026-10-07T01:46:02Z)
- `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/handoff.md` (Detailed implementation blueprints for SEC-11, SEC-12, SEC-14)
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`

## Mandatory Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Owned Files (Exclusive Write Boundaries)
- `app/Http/Controllers/EmployeeController.php`
- `app/Events/NotificationCreated.php`
- `routes/channels.php`
- `routes/api.php`
- `bootstrap/app.php`
- `app/Http/Middleware/AuthenticateQueryToken.php`
- `app/Services/ImageStorageService.php`
- `app/Models/AccessLog.php`
- `app/Models/StrangerSnap.php`
- `resources/js/App.vue`
- `resources/js/utils/media.js`
- `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php`

## Implementation Tasks

### 1. SEC-11 (BOLA/IDOR on Attendance Summary)
In `app/Http/Controllers/EmployeeController.php` (`attendanceSummary` method):
- Inspect user roles and permissions:
  ```php
  $canViewAny = $user->hasRole(['super-admin', 'admin', 'hr-manager', 'manager'])
      || $user->hasPermission(['employees.manage', 'employees.view']);
  ```
- If `$canViewAny` is false:
  - Verify `$user->employee?->id === (int) $id`.
  - If `$user->employee` is null or ID does not match `$id`, return HTTP 403:
    ```php
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized. You may only view your own attendance summary.',
    ], 403);
    ```

### 2. SEC-12 (WebSocket Notification Isolation)
- In `app/Events/NotificationCreated.php`:
  Update `broadcastOn()`:
  ```php
  $userId = $this->notification->user_id ?? $this->notification->notifiable_id ?? null;
  return [
      new PrivateChannel('notifications.' . $userId),
  ];
  ```
- In `routes/channels.php`:
  Remove/reject the global `notifications` channel:
  ```php
  Broadcast::channel('notifications.{userId}', function ($user, $userId) {
      return (int) $user->id === (int) $userId;
  }, ['guards' => ['web', 'sanctum']]);
  ```
- In `resources/js/App.vue`:
  Update Echo subscription to listen on `notifications.${authStore.user.id}` and leave `notifications.${authStore.user.id}`.

### 3. SEC-14 (Query-Token Auth Deprecation & Signed Media Routes)
- In `bootstrap/app.php`:
  Remove `$middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateQueryToken::class);`.
- In `app/Http/Middleware/AuthenticateQueryToken.php`:
  Deprecate query token extraction.
- In `routes/api.php`:
  Define `Route::get('media/{path}', ...)->name('media.show')->middleware('throttle:api');`:
  Verify `$request->hasValidSignature()` OR `auth('sanctum')->user()` with required permissions (`personnel.view,devices.view,attendance.view,visitors.view`).
  If neither signature nor valid authenticated user is present, return 401. Query tokens without signature must return 401.
- In `app/Services/ImageStorageService.php`:
  Add `signedMediaUrl(string $path, int $minutes = 120): string` helper generating `URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), ['path' => $cleanPath])`.
- In `app/Models/AccessLog.php` and `app/Models/StrangerSnap.php`:
  Update `getSnapPicUrlAttribute` / `getScenePicUrlAttribute` to generate temporary signed URLs for storage paths.
- In `resources/js/utils/media.js`:
  Remove appending `?token=`. Return URLs cleanly.
- In `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php`:
  Update `test_authenticated_user_can_access_stranger_snap_media_with_token_in_query` to assert that:
  - Valid signed URL works (200).
  - Bearer token header works (200).
  - Unsigned `?token=` query parameter fails with 401.

## Verification Requirements
1. Run PHPUnit test suite: `php artisan test`
2. Run frontend build check: `npm run build`
3. Document all modified files, test outputs, and verification results in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1/handoff.md`.
4. Report back when finished using `send_message`.


## 2026-10-07T02:05:28Z
From: 8e495d40-6bfb-4f59-aa93-c010dd3bbc37
Content:
You are Worker M1 implementing Milestone 1: Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14).
Your assigned working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1

MANDATORY USER REQUEST:
Read /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Also read:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/handoff.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1/DISPATCH.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your owned files (exclusive write boundary):
- app/Http/Controllers/EmployeeController.php
- app/Events/NotificationCreated.php
- routes/channels.php
- routes/api.php
- bootstrap/app.php
- app/Http/Middleware/AuthenticateQueryToken.php
- app/Services/ImageStorageService.php
- app/Models/AccessLog.php
- app/Models/StrangerSnap.php
- resources/js/App.vue
- resources/js/utils/media.js
- tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php
