# Milestone 1 Forensic Audit Report: Access Control & Authorization Hardening (SEC-11, SEC-12, SEC-14)

**Auditor:** Forensic Auditor M1 (`teamwork_preview_auditor_m1_1`)  
**Work Product:** Milestone 1 implementation by Worker M1 (`.agents/teamwork/teamwork_preview_worker_m1`)  
**Profile:** General Project (Mode: development, per `ORIGINAL_REQUEST.md`)  
**Verdict:** **CLEAN**

---

## Forensic Audit Summary

| Check Item | Requirement | Result | Evidence |
|---|---|---|---|
| **Hardcoded Test Results** | No fake or hardcoded PASS strings/mock values | **PASS** | Source inspection confirms dynamic logic across all modified files |
| **Facade Implementations** | Genuine business logic, no dummy return constants | **PASS** | Real role/permission and ownership checks in `EmployeeController`; dynamic channel formulation in `NotificationCreated` |
| **Pre-populated Artifacts** | No pre-existing test result logs or fake attestation files | **PASS** | File discovery showed only standard `.phpunit.result.cache` and runtime logs |
| **BOLA/IDOR Enforcement (SEC-11)** | Non-managers restricted strictly to own attendance summary | **PASS** | `EmployeeController::attendanceSummary` checks `$canViewAny` or `$userEmployeeId === $id`, returns HTTP 403 on mismatch |
| **WebSocket Isolation (SEC-12)** | Notifications isolated strictly to target user's private channel | **PASS** | `NotificationCreated` broadcasts to `private-notifications.{userId}`; permissive global `notifications` channel removed from `routes/channels.php` |
| **Signed Media Streaming (SEC-14)** | Query-string auth removed; media routes protected via HMAC signatures | **PASS** | `AuthenticateQueryToken` removed from `bootstrap/app.php`; `media/{path}` checks `$request->hasValidSignature()`; `AccessLog` & `StrangerSnap` accessors generate signed URLs |
| **Token Leakage Remediation** | No query tokens in frontend image rendering | **PASS** | `resources/js/utils/media.js` does not append `?token=` parameter |
| **Test Suite Execution** | Automated unit & feature tests execute cleanly | **PASS** | `MediaAccessAndUnauthenticatedRouteTest` (8/8 passed); Full suite (386 passed, 63 skipped, 0 failed) |
| **Frontend Asset Compilation** | Vite compilation succeeds without error | **PASS** | `npm run build` completed in 807ms with exit code 0 |

---

## 1. Observation

Direct empirical observations conducted on the codebase:

### 1.1 Source Code Verification
1. **`app/Http/Controllers/EmployeeController.php` (Lines 252–266)**:
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
   *Finding*: Genuine authorization logic preventing BOLA/IDOR attacks. Does not return hardcoded values; continues to genuine attendance calculation on valid authorization.

2. **`app/Events/NotificationCreated.php` (Lines 21–30)**:
   ```php
   public function broadcastOn(): array
   {
       $userId = is_array($this->notification)
           ? ($this->notification['user_id'] ?? $this->notification['notifiable_id'] ?? null)
           : ($this->notification->user_id ?? $this->notification->notifiable_id ?? null);

       return [
           new PrivateChannel('notifications.' . $userId),
       ];
   }
   ```
   *Finding*: Dynamic user extraction from notification models and arrays. Channel is strictly per-user (`private-notifications.{userId}`).

3. **`routes/channels.php` (Lines 45–47)**:
   ```php
   Broadcast::channel('notifications.{userId}', function ($user, $userId) {
       return (int) $user->id === (int) $userId;
   }, ['guards' => ['web', 'sanctum']]);
   ```
   *Finding*: Permissive global channel `Broadcast::channel('notifications', ...)` was eliminated. Subscription to any user's channel requires `(int) $user->id === (int) $userId`.

4. **`bootstrap/app.php` (Lines 17–37)**:
   *Finding*: `$middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateQueryToken::class);` was completely removed from the global API middleware group.

5. **`app/Http/Middleware/AuthenticateQueryToken.php` (Lines 18–21)**:
   *Finding*: The middleware class was marked `@deprecated` and the query string extraction logic was removed, leaving only `return $next($request);`.

6. **`routes/api.php` (Lines 292–329)**:
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
   *Finding*: Named route `media.show` properly checks Laravel's `$request->hasValidSignature()`. If unsigned, requires an active authenticated Bearer user with specific permissions. Unauthenticated/unsigned requests fail with 401.

7. **`app/Services/ImageStorageService.php` (Lines 284–288)**:
   ```php
   public function signedMediaUrl(string $path, int $minutes = 120): string
   {
       $cleanPath = ltrim(preg_replace('#^.*?/api/media/#', '', preg_replace('#^.*?/storage/#', '', $path)), '/');
       $cleanPath = explode('?', $cleanPath)[0];
       return URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), ['path' => $cleanPath]);
   }
   ```
   *Finding*: Generates genuine temporary HMAC-signed URLs via Laravel's `URL::temporarySignedRoute`.

8. **`app/Models/AccessLog.php` & `app/Models/StrangerSnap.php`**:
   *Finding*: Both models implement `getSnapPicUrlAttribute` and `getScenePicUrlAttribute` accessors using `URL::temporarySignedRoute('media.show', now()->addHours(2), ['path' => $path])`, ensuring all serialized API payloads deliver signed URLs.

9. **`resources/js/App.vue` (Lines 851–856, 892–894)**:
   *Finding*: Updated to `echo.private('notifications.' + authStore.user.id)` and `echo.leave('notifications.' + authStore.user.id)`.

10. **`resources/js/utils/media.js` (Lines 9–12)**:
    *Finding*: Removed appending `?token=` parameter. URLs are returned cleanly.

### 1.2 Independent Execution Evidence

1. **Specific Feature Tests:**
   ```bash
   php artisan test --filter=MediaAccessAndUnauthenticatedRouteTest
   ```
   *Raw Tool Output*:
   ```json
   {"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":43,"duration_ms":480}
   ```
   *Exit code*: 0. All 8 tests passed, asserting 43 distinct conditions including tampered signatures (401), expired signatures (401), query-token rejection (401), valid signed URLs (200), BOLA peer access prevention (403), and WebSocket channel scoping (403).

2. **Security Regression Tests:**
   ```bash
   php artisan test --filter=SecurityRemediationTest
   ```
   *Raw Tool Output*:
   ```json
   {"tool":"phpunit","result":"passed","tests":20,"passed":20,"assertions":73,"duration_ms":1032}
   ```
   *Exit code*: 0.

   ```bash
   php artisan test --filter=SecurityAdversarialGateTest
   ```
   *Raw Tool Output*:
   ```json
   {"tool":"phpunit","result":"passed","tests":16,"passed":16,"assertions":167,"duration_ms":53999}
   ```
   *Exit code*: 0.

3. **Full Project Test Suite:**
   ```bash
   php artisan test
   ```
   *Raw Tool Output*:
   ```json
   {"tool":"phpunit","result":"passed","tests":449,"passed":386,"assertions":1644,"duration_ms":68450,"skipped":63}
   ```
   *Exit code*: 0. Zero test failures.

4. **Frontend Build:**
   ```bash
   npm run build
   ```
   *Raw Tool Output*:
   ```
   vite v8.2.2 building client environment for production...
   ✓ 137 modules transformed.
   ✓ built in 807ms
   ```
   *Exit code*: 0.

---

## 2. Logic Chain

1. **Integrity Mode Assessment**:
   `ORIGINAL_REQUEST.md` (Section `2026-10-07T01:46:02Z`) defines the project mode as **development**. Under Development Mode, the forensic audit requires verifying that no hardcoded test results, facade implementations, or fabricated verification outputs exist, and that all business and security logic is authentic.

2. **Authenticity of SEC-11 Remediation**:
   - `EmployeeController::attendanceSummary` intercepts incoming requests and extracts `$request->user()`.
   - If the user has managerial roles or permissions, access to any employee ID is permitted.
   - If the user is a non-managerial employee, `$user->employee?->id` is compared against the target `$id`. Mismatched IDs or users without linked employee profiles immediately receive HTTP 403 Forbidden with a clear authorization error.
   - This prevents IDOR/BOLA attacks empirically verified by `test_employee_attendance_summary_bola_protection_sec_11`.

3. **Authenticity of SEC-12 Remediation**:
   - `NotificationCreated::broadcastOn()` scopes the event channel to `notifications.{userId}` based on the notification recipient.
   - `routes/channels.php` contains an authorization callback verifying `(int) $user->id === (int) $userId`.
   - The permissive global `notifications` channel was completely deleted.
   - `resources/js/App.vue` was updated to subscribe strictly to `notifications.${authStore.user.id}`.
   - Cross-user notification leakage is completely eliminated, empirically verified by `test_websocket_notification_broadcast_channel_isolation_sec_12`.

4. **Authenticity of SEC-14 Remediation**:
   - `AuthenticateQueryToken` is no longer prepended to the global `api` middleware stack in `bootstrap/app.php`.
   - Media streaming route `/api/media/{path}` checks `$request->hasValidSignature()`.
   - Expired signatures, tampered signatures, or query tokens (`?token=`) return HTTP 401 Unauthenticated, empirically verified by `test_expired_and_tampered_signed_media_urls_return_401` and `test_authenticated_user_can_access_stranger_snap_media_with_token_in_query`.
   - `AccessLog` and `StrangerSnap` models automatically generate signed URLs via accessors with HMAC signatures and a 2-hour validity window.
   - `resources/js/utils/media.js` no longer injects `?token=` into media URLs. Credential leakage in URL query parameters (CWE-598) is remediated.

5. **Absence of Evasion Techniques**:
   - No mock test shortcuts or hardcoded test returns were found in any modified file.
   - All tests run against live database tables using `RefreshDatabase` and assert actual HTTP status codes, headers, and response payloads.

---

## 3. Caveats

- **Signed URL Replay Window**: Signed URLs generated by `AccessLog` and `StrangerSnap` have a validity window of 2 hours (`now()->addHours(2)`). While appropriate for interactive dashboard monitoring sessions, consumers must request refreshed log listings for long-running sessions exceeding 2 hours.
- **Scope Boundary**: This audit exclusively covers Milestone 1 (SEC-11, SEC-12, SEC-14). Subsequent security items (SEC-13, SEC-15 through SEC-19) are part of later milestones and were not modified by Worker M1.

---

## 4. Conclusion

Worker M1's work product adheres to all security and integrity standards:
- No hardcoded test responses or facade implementations exist.
- No fabricated logs or verification outputs exist.
- Authorization checks, HMAC signatures, and channel scoping are authentic and robust.
- All unit and feature tests pass (`449 total, 386 passed, 63 skipped, 0 failures`).
- The frontend build compiles cleanly with zero errors.

**Definitive Verdict:** **CLEAN**

---

## 5. Verification Method

To independently reproduce this forensic audit:

1. **Verify Source Code & Absence of Facades**:
   ```bash
   git diff main -- app/Http/Controllers/EmployeeController.php app/Events/NotificationCreated.php routes/channels.php routes/api.php bootstrap/app.php app/Http/Middleware/AuthenticateQueryToken.php app/Services/ImageStorageService.php app/Models/AccessLog.php app/Models/StrangerSnap.php resources/js/App.vue resources/js/utils/media.js
   ```

2. **Run Dedicated Milestone 1 Feature Tests**:
   ```bash
   php artisan test --filter=MediaAccessAndUnauthenticatedRouteTest
   ```
   *Expected outcome*: 8 passed, 43 assertions, exit code 0.

3. **Run Regression Security Tests**:
   ```bash
   php artisan test --filter=SecurityRemediationTest
   php artisan test --filter=SecurityAdversarialGateTest
   ```
   *Expected outcome*: 36 passed, 0 failures, exit code 0.

4. **Run Full Test Suite**:
   ```bash
   php artisan test
   ```
   *Expected outcome*: 386 passed, 63 skipped, 0 failed, exit code 0.

5. **Run Frontend Production Build**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Vite build succeeds with exit code 0.
