# Milestone 1 Handoff Report: Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14)

**Worker ID:** Worker M1  
**Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m1`  
**Date:** 2026-10-07  
**Milestone:** Milestone 1 (SEC-11, SEC-12, SEC-14)  
**Parent Conversation ID:** `8e495d40-6bfb-4f59-aa93-c010dd3bbc37`  

---

## 1. Observation

### 1.1 SEC-11 (BOLA/IDOR on Attendance Summary)
- Prior to remediation, `routes/api.php` registered:
  ```php
  Route::get('employees/{id}/attendance-summary', [EmployeeController::class, 'attendanceSummary'])
      ->middleware('permission:employees.view,attendance.view,selfservice.view,employees.manage');
  ```
  `CheckPermission` middleware uses `OR` logic between permissions. In `database/seeders/RolesAndPermissionsSeeder.php`, every standard employee possesses `selfservice.view`.
- In `app/Http/Controllers/EmployeeController.php` (line 252), the `attendanceSummary` method directly queried `Employee::with('personnel')->findOrFail($id)` without inspecting `$request->user()` or ensuring `$user->employee?->id === (int) $id`. Non-managerial employees could query any peer employee's biometric attendance history and summary.

### 1.2 SEC-12 (WebSocket Notification Isolation)
- In `app/Events/NotificationCreated.php`, `broadcastOn()` previously broadcast to a single shared channel:
  ```php
  return [ new PrivateChannel('notifications') ];
  ```
- In `routes/channels.php`, the global channel authorized any authenticated user:
  ```php
  Broadcast::channel('notifications', function ($user) { return $user !== null; });
  ```
- In `resources/js/App.vue`, Echo subscribed globally via `echo.private("notifications")`. Any user connected via WebSockets received all broadcast notifications (including sensitive disciplinary and security notices) meant for any user.

### 1.3 SEC-14 (Query-Token Auth Deprecation & Signed Media Routes)
- In `bootstrap/app.php`, line 36 registered:
  ```php
  $middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateQueryToken::class);
  ```
  This automatically converted any `?token=` query parameter across all API endpoints into an `Authorization: Bearer` header.
- In `resources/js/utils/media.js`, `formatMediaUrl` appended `?token=${encodeURIComponent(token)}` to every `/api/media/` URL rendered in Vue image tags (`<img>`), exposing long-lived user credentials in query strings, server logs, and HTTP Referer headers (CWE-598).
- In `routes/api.php`, the `media/{path}` route was wrapped inside the global `auth:sanctum` group and lacked route naming for temporary signed route generation.

---

## 2. Logic Chain

### 2.1 SEC-11 Remediation Chain
1. Enforced ownership and managerial role checks in `app/Http/Controllers/EmployeeController.php` at the beginning of `attendanceSummary`:
   ```php
   $user = $request->user();
   if ($user) {
       $canViewAny = $user->hasRole(['super-admin', 'admin', 'hr-manager', 'manager'])
           || $user->hasPermission(['employees.manage', 'employees.view', 'attendance.view']);

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
   ```
2. Any standard employee requesting a summary for an ID other than their own linked employee ID is rejected with HTTP 403 Forbidden.
3. Users with managerial roles (`super-admin`, `admin`, `hr-manager`, `manager`) or permissions (`employees.manage`, `employees.view`, `attendance.view`) retain access to view any employee's attendance summary.

### 2.2 SEC-12 Remediation Chain
1. Updated `app/Events/NotificationCreated.php` so `broadcastOn()` scopes the event channel to the specific recipient:
   ```php
   $userId = is_array($this->notification)
       ? ($this->notification['user_id'] ?? $this->notification['notifiable_id'] ?? null)
       : ($this->notification->user_id ?? $this->notification->notifiable_id ?? null);

   return [
       new PrivateChannel('notifications.' . $userId),
   ];
   ```
2. In `routes/channels.php`, removed the un-scoped `Broadcast::channel('notifications', ...)`. Kept strictly:
   ```php
   Broadcast::channel('notifications.{userId}', function ($user, $userId) {
       return (int) $user->id === (int) $userId;
   }, ['guards' => ['web', 'sanctum']]);
   ```
   Now, any client attempting to subscribe to `private-notifications` receives HTTP 403 Forbidden. Subscribing to `private-notifications.{userId}` requires `(int) $user->id === (int) $userId`.
3. In `resources/js/App.vue`, updated Echo initialization to listen on `notifications.${authStore.user.id}` and leave `notifications.${authStore.user.id}` on cleanup/unmount.

### 2.3 SEC-14 Remediation Chain
1. Removed `AuthenticateQueryToken` from the global `api` middleware group in `bootstrap/app.php`.
2. Deprecated `AuthenticateQueryToken.php` and disabled query token extraction so that query string tokens (`?token=`) are no longer promoted to `Authorization` headers.
3. Moved `media/{path}` in `routes/api.php` outside the unconditional `auth:sanctum` group into Tier 4, naming it `media.show`. Configured route logic to check EITHER `$request->hasValidSignature()` OR authenticated user `auth('sanctum')->user()` with required permissions (`personnel.view`, `devices.view`, `attendance.view`, `visitors.view`). If neither valid signature nor valid bearer session exists, returns HTTP 401 Unauthenticated.
4. Added `signedMediaUrl(string $path, int $minutes = 120): string` in `app/Services/ImageStorageService.php` using `URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), ['path' => $cleanPath])`. Cleaned query string parameters in `getMedia` and `storeFromUrlOrPath`.
5. Added `getSnapPicUrlAttribute` and `getScenePicUrlAttribute` accessors to both `app/Models/AccessLog.php` and `app/Models/StrangerSnap.php` so that Eloquent serialization produces signed URLs with cryptographic HMAC signatures and expiration timestamps.
6. In `resources/js/utils/media.js`, removed appending `?token=` from `formatMediaUrl`, returning URLs cleanly without credential leakage.

---

## 3. Caveats

- **Exclusive Write Boundaries Respected:** In strict adherence to write boundary guidelines, only the 12 assigned files were modified. Files assigned to subsequent milestones (e.g. `app/Http/Controllers/PersonnelController.php`, `config/cors.php`, `SecurityHeaders.php`) were not modified.
- **Signed URL Expiration Window:** Temporary signed URLs generated by `AccessLog` and `StrangerSnap` accessors use a 2-hour validity window (`now()->addHours(2)`), which is ideal for real-time monitoring sessions without permanent URL replayability.

---

## 4. Conclusion

All tasks for Milestone 1 have been implemented with genuine business logic, robust authorization guards, and cryptographic URL signatures:
- **SEC-11:** BOLA/IDOR vulnerability in `EmployeeController::attendanceSummary` completely eliminated with HTTP 403 enforcement.
- **SEC-12:** Real-time WebSocket notifications isolated to per-user private channels (`notifications.{userId}`); permissive global channel removed.
- **SEC-14:** Query-token authentication deprecated and decommissioned from global API middleware; media streaming secured with temporary signed routes and authenticated Bearer support; token leakage in frontend URL formatting eliminated.

---

## 5. Verification Method

### 5.1 Verification Commands and Results

1. **Feature & Unit Test Suite:**
   ```bash
   php artisan test
   ```
   **Result:**
   ```
   Tests:    362 passed, 2 skipped (1504 assertions)
   Duration: 63.02s
   Exit Code: 0
   ```

2. **Specific Milestone 1 Feature Tests:**
   ```bash
   php artisan test --filter=MediaAccessAndUnauthenticatedRouteTest
   ```
   **Result:**
   ```
   Tests:    8 passed (43 assertions)
   Duration: 0.30s
   Exit Code: 0
   ```
   *Verified test cases:*
   - `test_unauthenticated_api_request_returns_401_and_does_not_throw_route_not_found` (200 OK -> 401)
   - `test_unauthenticated_img_request_without_accept_json_returns_401_instead_of_route_login_not_defined` (401)
   - `test_authenticated_user_can_access_stranger_snap_media_with_token_in_query` (Valid signed URL = 200; Bearer header = 200; unsigned `?token=` = 401)
   - `test_expired_and_tampered_signed_media_urls_return_401` (401 on expired and tampered signatures)
   - `test_access_log_and_stranger_snap_models_generate_temporary_signed_urls` (AccessLog & StrangerSnap accessors produce signed URLs that fetch media directly with 200)
   - `test_stranger_snap_and_scene_subfolders_are_allowed_in_get_media` (200)
   - `test_employee_attendance_summary_bola_protection_sec_11` (Non-manager peer = 403 Forbidden; own profile = 200 OK; unlinked user = 403; Super-Admin = 200 OK)
   - `test_websocket_notification_broadcast_channel_isolation_sec_12` (Broadcasts to `private-notifications.{userId}`; authorized user = 200; foreign user = 403; global channel = 403)

3. **Frontend Asset Build:**
   ```bash
   npm run build
   ```
   **Result:**
   ```
   vite v8.2.2 building client environment for production...
   ✓ 137 modules transformed.
   ✓ built in 731ms
   Exit Code: 0
   ```

4. **Regression Verification:**
   ```bash
   php artisan test --filter=EmployeeAndShiftManagementTest
   php artisan test --filter=SecurityRemediationTest
   php artisan test --filter=SecurityAdversarialGateTest
   ```
   **Result:**
   All regression suites passed with 0 failures and 0 errors.

### 5.2 Modified Files Manifest

| File | Change Summary |
|---|---|
| `app/Http/Controllers/EmployeeController.php` | Enforced managerial role/permission or owner ID check returning 403 Forbidden (SEC-11) |
| `app/Events/NotificationCreated.php` | Scoped broadcast channel to `private-notifications.{userId}` (SEC-12) |
| `routes/channels.php` | Removed global `notifications` channel; preserved strictly `notifications.{userId}` (SEC-12) |
| `resources/js/App.vue` | Updated Echo subscription and teardown to `notifications.${authStore.user.id}` (SEC-12) |
| `bootstrap/app.php` | Removed `AuthenticateQueryToken` from global `api` group (SEC-14) |
| `app/Http/Middleware/AuthenticateQueryToken.php` | Deprecated middleware & disabled query parameter extraction (SEC-14) |
| `routes/api.php` | Moved `media/{path}` to Tier 4 named `media.show` with signature verification OR Bearer auth (SEC-14) |
| `app/Services/ImageStorageService.php` | Added `signedMediaUrl()` helper and query-string stripping in `getMedia` and `storeFromUrlOrPath` (SEC-14) |
| `app/Models/AccessLog.php` | Added signed URL accessors for `snap_pic_url` and `scene_pic_url` (SEC-14) |
| `app/Models/StrangerSnap.php` | Added signed URL accessors for `snap_pic_url` and `scene_pic_url` (SEC-14) |
| `resources/js/utils/media.js` | Removed `?token=` query parameter injection from `formatMediaUrl` (SEC-14) |
| `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php` | Added comprehensive automated test suite for SEC-11, SEC-12, and SEC-14 |
