# Comprehensive Security Investigation Report: Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14)

**Investigator:** Explorer Survey 1  
**Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1`  
**Assessment Date:** 2026-10-07  
**Scope:** Milestone 1 — SEC-11 (Critical BOLA/IDOR), SEC-12 (Critical WebSocket Notification Isolation), SEC-14 (High Query-Token Auth Deprecation & Signed Media Routes)

---

## 1. Observation

### 1.1 SEC-11: BOLA/IDOR on Attendance Summary Endpoint

- **Route Configuration (`routes/api.php`, lines 191–192):**
  ```php
  Route::get('employees/{id}/attendance-summary', [EmployeeController::class, 'attendanceSummary'])
      ->middleware('permission:employees.view,attendance.view,selfservice.view,employees.manage');
  ```
- **Permission Middleware (`app/Http/Middleware/CheckPermission.php`, lines 44–47):**
  ```php
  // Verify if user possesses required permission (OR logic between args)
  if ($user->hasPermission($permissions)) {
      return $next($request);
  }
  ```
- **Role Permission Seeding (`database/seeders/RolesAndPermissionsSeeder.php`, lines 168–174):**
  ```php
  // employee
  $empPerms = [
      'selfservice.view',
      'leaves.apply',
      'visitors.preregister',
  ];
  $roles['employee']->syncPermissions($empPerms);
  ```
- **Controller Implementation (`app/Http/Controllers/EmployeeController.php`, lines 250–253):**
  ```php
  public function attendanceSummary(Request $request, int $id): JsonResponse
  {
      $employee = Employee::with('personnel')->findOrFail($id);
  ```
  *(Lines 254–340 compute working days, present/absent/late counts, work hours, overtime hours, and fetch raw punches via `attendance_punches` or `access_logs` without checking `$request->user()` or verifying if `$user->employee?->id === $id`).*
- **User-Employee Relation (`app/Models/User.php`, lines 55–58):**
  ```php
  public function employee(): HasOne
  {
      return $this->hasOne(Employee::class, 'user_id');
  }
  ```

### 1.2 SEC-12: Real-Time WebSocket Notification Broadcasting Exposure

- **Broadcast Event (`app/Events/NotificationCreated.php`, lines 21–26):**
  ```php
  public function broadcastOn(): array
  {
      return [
          new PrivateChannel('notifications'),
      ];
  }
  ```
- **Broadcast Channel Authorization (`routes/channels.php`, lines 45–51):**
  ```php
  Broadcast::channel('notifications', function ($user) {
      return $user !== null;
  }, ['guards' => ['web', 'sanctum']]);

  Broadcast::channel('notifications.{userId}', function ($user, $userId) {
      return (int) $user->id === (int) $userId;
  }, ['guards' => ['web', 'sanctum']]);
  ```
- **Client Subscription in SPA (`resources/js/App.vue`, lines 851–854 and 890):**
  ```javascript
  echo.private("notifications")
      .listen(".NotificationCreated", (e) =>
          notificationStore.handleLiveNotification(e),
      );
  ...
  echo.leave("notifications");
  ```
  *(Notice: `routes/channels.php` defines both `notifications` and `notifications.{userId}`. However, `NotificationCreated` broadcasts exclusively to `notifications`, and `App.vue` listens exclusively to `notifications`. Any authenticated user can subscribe to `private-notifications` and receive all confidential notifications for all users).*

### 1.3 SEC-14: URL Query-String Token Authentication & Media Exposure

- **Global API Middleware Registration (`bootstrap/app.php`, line 36):**
  ```php
  $middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateQueryToken::class);
  ```
- **Query Token Middleware (`app/Http/Middleware/AuthenticateQueryToken.php`, lines 15–22):**
  ```php
  public function handle(Request $request, Closure $next): Response
  {
      if (!$request->headers->has('Authorization')) {
          $token = $request->query('token') ?: $request->query('api_token');
          if ($token && is_string($token)) {
              $request->headers->set('Authorization', 'Bearer ' . trim($token));
          }
      }
      return $next($request);
  }
  ```
- **Frontend Media Formatter (`resources/js/utils/media.js`, lines 16–22):**
  ```javascript
  const token = localStorage.getItem('auth_token');
  if (token && url.includes('/api/media/')) {
      const separator = url.includes('?') ? '&' : '?';
      if (!url.includes('token=')) {
          return `${url}${separator}token=${encodeURIComponent(token)}`;
      }
  }
  ```
- **Media Route Definition (`routes/api.php`, lines 283–293):**
  ```php
  Route::get('media/{path}', function (\Illuminate\Http\Request $request, string $path, \App\Services\ImageStorageService $storage) {
      $media = $storage->getMedia($path);
      if (!$media) {
          abort(404, 'Media not found.');
      }
      return response($media['content'], 200, [
          'Content-Type' => $media['mime_type'],
          'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
          'X-Content-Type-Options' => 'nosniff',
      ]);
  })->where('path', '.*')->middleware('permission:personnel.view,devices.view,attendance.view,visitors.view');
  ```
  *(Currently inside `Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(...)`)*.
- **Existing Test in `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php` (lines 50–54):**
  ```php
  $response = $this->get('/api/media/strangers/2026/10/04/snap.jpg?token=' . $token);
  $response->assertStatus(200);
  ```
  *(Explicitly asserts query-string token authentication functionality).*

---

## 2. Logic Chain

### 2.1 Trace of SEC-11 (BOLA/IDOR on Attendance Summary)
1. **Observation 1.1** shows that `routes/api.php` guards `employees/{id}/attendance-summary` with `permission:employees.view,attendance.view,selfservice.view,employees.manage`.
2. **Observation 1.1** shows that `CheckPermission::handle` uses OR logic across its arguments. Any user possessing at least one of these permissions passes through to the controller.
3. In `RolesAndPermissionsSeeder.php`, every standard employee is granted `selfservice.view` so they can access their personal portal.
4. When a standard employee user requests `GET /api/employees/{id}/attendance-summary` for an arbitrary ID (e.g., ID 42 belonging to a peer), `CheckPermission` evaluates `hasPermission('selfservice.view')` as `true`.
5. In `EmployeeController::attendanceSummary`, line 252 executes `Employee::with('personnel')->findOrFail($id)`. The controller does not check whether `$id` belongs to the requesting user.
6. The controller then aggregates and returns the peer's working days, present/absent days, late occurrences, total work hours, overtime hours, and recent access/punch telemetry logs in JSON.
7. **Conclusion:** Any standard employee can exfiltrate any other employee's confidential attendance history and biometric punch times simply by changing the ID parameter in the URL.

### 2.2 Trace of SEC-12 (WebSocket Notification Isolation)
1. In `app/Events/NotificationCreated.php`, `broadcastOn()` returns `[new PrivateChannel('notifications')]`.
2. In `routes/channels.php`, the channel authorization callback for `notifications` returns `$user !== null`. Any user authenticated with a valid Sanctum token is authorized to join `private-notifications`.
3. In `resources/js/App.vue`, the Echo client joins `echo.private("notifications")` and listens for `.NotificationCreated`.
4. When a notification is generated for User A (e.g., a disciplinary alert, leave approval/rejection, visitor arrival notice), `NotificationCreated` broadcasts to the shared `private-notifications` channel.
5. All connected users (User B, User C, etc.) receive the event payload containing User A's notification ID, title, message, and sensitive event data in real time.
6. **Conclusion:** Cross-user data leakage occurs over WebSockets because broadcasts are not partitioned into per-user channels.

### 2.3 Trace of SEC-14 (Query-Token Auth Deprecation & Signed Media Routes)
1. In `bootstrap/app.php`, `AuthenticateQueryToken` is prepended to the global `api` middleware group.
2. In `AuthenticateQueryToken.php`, if `?token=` or `?api_token=` is present in the query string and no `Authorization` header exists, the token is extracted and injected as `Authorization: Bearer <token>`.
3. Because this is global on `api`, EVERY route under `/api/*` accepts query string tokens, not just media streaming.
4. In `resources/js/utils/media.js`, `formatMediaUrl` appends `?token=<SANCTUM_TOKEN>` to every `/api/media/` URL used in `<img>` tags throughout `LiveTelemetry.vue`, `AccessLogsHistory.vue`, and `StrangerSnapsMonitor.vue`.
5. Sanctum tokens are long-lived user credentials. Passing them in URL query strings exposes them in:
   - Server web logs (`access.log`)
   - Browser navigation and download history
   - External `Referer` headers when external assets or links are clicked
   - Proxy logs and local client caching
6. In `routes/api.php`, `/api/media/{path}` is currently wrapped inside the global `auth:sanctum` group. Browser `<img>` tags cannot natively provide `Authorization: Bearer` headers. Without query token auth, browser image tags will fail unless the route supports signed URLs (`$request->hasValidSignature()`).
7. **Conclusion:** Removing `AuthenticateQueryToken` from global middleware and migrating `/api/media/{path}` to accept temporary cryptographically signed routes (`URL::temporarySignedRoute`) eliminates token leakage while maintaining seamless `<img>` rendering and authenticated Bearer API access.

---

## 3. Caveats

1. **No Code Written (Read-Only Guarantee):** In strict accordance with explorer constraints, no production files were edited. All proposals below are provided as drop-in blueprints for the implementing worker.
2. **Backward Compatibility of Existing Tests:** `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php` has a test (`test_authenticated_user_can_access_stranger_snap_media_with_token_in_query`) that explicitly relies on `?token=`. When SEC-14 deprecates query-token auth, this test must be updated to test temporary signed route access and Authorization Bearer header access, asserting that `?token=` without signature returns 401.
3. **Image Cache Lifespan:** Temporary signed URLs generated by `URL::temporarySignedRoute` include an `expires` timestamp. Setting an expiration of 2 hours (`now()->addHours(2)`) or 1 day is sufficient for web session inspection while ensuring URLs expire gracefully.

---

## 4. Conclusion & Actionable Remediation Design

### 4.1 Remediation Blueprint for SEC-11 (EmployeeController)

**Target File:** `app/Http/Controllers/EmployeeController.php` (method `attendanceSummary`)

**Design:**
Check if the user is a manager or possesses directory management permissions (`super-admin`, `admin`, `hr-manager`, `manager`, or permissions `employees.manage`, `employees.view`). If not, strictly verify that `$user->employee?->id === (int) $id`. If mismatched or the user has no linked employee profile, return HTTP 403 Forbidden.

**Proposed Code:**
```php
public function attendanceSummary(Request $request, int $id): JsonResponse
{
    $user = $request->user();
    if ($user) {
        $canViewAny = $user->hasRole(['super-admin', 'admin', 'hr-manager', 'manager'])
            || $user->hasPermission(['employees.manage', 'employees.view']);

        if (!$canViewAny) {
            $userEmployeeId = $user->employee?->id;
            if (!$userEmployeeId || (int) $userEmployeeId !== (int) $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You may only view your own attendance summary.',
                ], 403);
            }
        }
    }

    $employee = Employee::with('personnel')->findOrFail($id);
    ...
```

---

### 4.2 Remediation Blueprint for SEC-12 (Notification Isolation)

**Target Files:**
1. `app/Events/NotificationCreated.php`
2. `routes/channels.php`
3. `resources/js/App.vue`

**Design:**
1. In `app/Events/NotificationCreated.php`:
   ```php
   public function __construct(public mixed $notification)
   {
   }

   public function broadcastOn(): array
   {
       $userId = $this->notification->user_id ?? $this->notification->notifiable_id ?? null;
       return [
           new PrivateChannel('notifications.' . $userId),
       ];
   }
   ```
2. In `routes/channels.php`:
   Remove or reject the global `Broadcast::channel('notifications', ...)` channel, preserving strictly:
   ```php
   Broadcast::channel('notifications.{userId}', function ($user, $userId) {
       return (int) $user->id === (int) $userId;
   }, ['guards' => ['web', 'sanctum']]);
   ```
3. In `resources/js/App.vue`:
   Update lines 851–854 and line 890:
   ```javascript
   if (authStore.user?.id) {
       echo.private(`notifications.${authStore.user.id}`)
           .listen(".NotificationCreated", (e) =>
               notificationStore.handleLiveNotification(e),
           );
   }
   ...
   if (authStore.user?.id) {
       echo.leave(`notifications.${authStore.user.id}`);
   }
   ```

---

### 4.3 Remediation Blueprint for SEC-14 (Query-Token Deprecation & Signed Media Routes)

**Target Files:**
1. `bootstrap/app.php`
2. `app/Http/Middleware/AuthenticateQueryToken.php`
3. `routes/api.php`
4. `app/Services/ImageStorageService.php`
5. `app/Models/AccessLog.php`, `app/Models/StrangerSnap.php`
6. `resources/js/utils/media.js`

**Design:**
1. In `bootstrap/app.php`: Remove `$middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateQueryToken::class);`.
2. In `app/Http/Middleware/AuthenticateQueryToken.php`: Mark class as `@deprecated` with explanation that query token auth is removed for security compliance (CWE-598).
3. In `routes/api.php`:
   Define the media streaming route with `media.show` name outside the unconditional `auth:sanctum` group, verifying EITHER `$request->hasValidSignature()` OR `auth('sanctum')->user()` with required permissions:
   ```php
   Route::get('media/{path}', function (\Illuminate\Http\Request $request, string $path, \App\Services\ImageStorageService $storage) {
       $hasValidSignature = $request->hasValidSignature();
       $user = auth('sanctum')->user();

       if (!$hasValidSignature) {
           if (!$user) {
               return response()->json([
                   'success' => false,
                   'message' => 'Unauthenticated.',
               ], 401);
           }

           if (!$user->is_active) {
               return response()->json([
                   'success' => false,
                   'message' => 'Your account has been deactivated.',
               ], 403);
           }

           if (!$user->hasPermission(['personnel.view', 'devices.view', 'attendance.view', 'visitors.view'])) {
               return response()->json([
                   'success' => false,
                   'message' => 'Unauthorized.',
               ], 403);
           }
       }

       $media = $storage->getMedia($path);
       if (!$media) {
           abort(404, 'Media not found.');
       }

       return response($media['content'], 200, [
           'Content-Type' => $media['mime_type'],
           'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
           'X-Content-Type-Options' => 'nosniff',
       ]);
   })->where('path', '.*')->name('media.show')->middleware('throttle:api');
   ```
4. In `app/Services/ImageStorageService.php`:
   Add a signed URL generator helper:
   ```php
   public function signedMediaUrl(string $path, int $minutes = 120): string
   {
       $cleanPath = ltrim(preg_replace('#^.*?/api/media/#', '', preg_replace('#^.*?/storage/#', '', $path)), '/');
       return \Illuminate\Support\Facades\URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), ['path' => $cleanPath]);
   }
   ```
5. In `app/Models/AccessLog.php` & `app/Models/StrangerSnap.php`:
   Add accessors for `snap_pic_url` and `scene_pic_url` that dynamically produce temporary signed URLs when serialized for API responses:
   ```php
   public function getSnapPicUrlAttribute($value): ?string
   {
       if (empty($value) || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:') || str_contains($value, 'signature=')) {
           return $value;
       }
       $path = ltrim(preg_replace('#^.*?/api/media/#', '', preg_replace('#^.*?/storage/#', '', $value)), '/');
       try {
           return \Illuminate\Support\Facades\URL::temporarySignedRoute('media.show', now()->addHours(2), ['path' => $path]);
       } catch (\Throwable $e) {
           return $value;
       }
   }
   ```
6. In `resources/js/utils/media.js`:
   Remove appending `?token=`:
   ```javascript
   export function formatMediaUrl(url) {
       if (!url) return '';
       // Base64 data URI, blob URI, or pre-signed URL from server
       return url;
   }
   ```

---

## 5. Verification Method

To independently verify after implementation:

### 5.1 SEC-11 Verification
Run feature test verifying:
1. Standard Employee 1 with only `selfservice.view` queries `GET /api/employees/{employee2_id}/attendance-summary` -> asserts HTTP 403 Forbidden (`Unauthorized. You may only view your own attendance summary.`).
2. Standard Employee 1 queries `GET /api/employees/{employee1_id}/attendance-summary` -> asserts HTTP 200 OK.
3. User with no linked employee record queries `GET /api/employees/{employee1_id}/attendance-summary` -> asserts HTTP 403 Forbidden.
4. Manager / Admin user queries `GET /api/employees/{employee2_id}/attendance-summary` -> asserts HTTP 200 OK.

Command:
```bash
php artisan test --filter=SecurityAdversarialGateTest
```

### 5.2 SEC-12 Verification
Run test verifying:
1. `NotificationCreated` event has `broadcastOn()` returning `private-notifications.{user_id}` and NOT `private-notifications`.
2. Channel authorization for `notifications.{userId}` allows matching user and denies non-matching user.
3. Subscription to legacy `private-notifications` channel is rejected.

### 5.3 SEC-14 Verification
Run feature test verifying:
1. `GET /api/media/{path}` with valid signed URL (`URL::temporarySignedRoute('media.show', ...)`) returns HTTP 200 with media content.
2. `GET /api/media/{path}` with `Authorization: Bearer <valid_token>` header returns HTTP 200 with media content.
3. `GET /api/media/{path}?token=<token>` WITHOUT Authorization header returns HTTP 401 Unauthenticated (proving query token auth has been completely deactivated).
4. `GET /api/media/{path}` with expired or tampered signed URL returns HTTP 401/403.
5. Frontend compiles cleanly:
```bash
npm run build
```

Full automated regression test command:
```bash
php artisan test
```

---
*Report completed and stored at: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_1/handoff.md`*
