# Milestone 1 Adversarial Challenge Report: Security Foundation, RBAC & Multi-Tenant Settings

**Verdict**: **REQUEST_CHANGES**

---

## 1. Observation

Adversarial stress testing was conducted against the implementation of Milestone 1 across five mandatory security domains. An empirical test suite comprising 22 test methods was executed using `php artisan test --filter=AdversarialM1Test`.

### Observation 1: Total Absence of RBAC Permission Enforcement on Guarded Routes
- **File**: `routes/api.php`, lines 42–141:
  ```php
  /*
  |--------------------------------------------------------------------------
  | Tier 3: Guarded Domain Endpoints (AUTHENTICATED via auth:sanctum)
  |--------------------------------------------------------------------------
  */
  Route::middleware(['auth:sanctum'])->group(function () {
      // RBAC Administration
      Route::apiResource('roles', RoleController::class);
      Route::get('permissions', [RoleController::class, 'permissions']);

      // Organization Hierarchy
      Route::apiResource('organizations', OrganizationController::class);
      ...
      // Global Settings
      Route::get('settings', [SettingController::class, 'index']);
      Route::put('settings', [SettingController::class, 'update']);
      ...
      // Audit Trail
      Route::get('audit-logs', [SettingController::class, 'auditLogs']);
      ...
      // Device Management
      Route::apiResource('devices', DeviceController::class);
  ```
- **File**: `app/Http/Middleware/CheckPermission.php`:
  The `CheckPermission` class is defined and aliased as `'permission'` in `bootstrap/app.php` (line 25).
- **Result**: `CheckPermission` is **never applied** to any route in `routes/api.php`.
- **Empirical Execution**:
  Command: `php artisan test --filter=AdversarialM1Test::test_unprivileged_employee_cannot_create_roles`
  Output:
  ```json
  {"message":"Unprivileged employee must receive HTTP 403 when attempting to create roles, but received 201: {\"success\":true,\"message\":\"Role created successfully.\",\"data\":{\"name\":\"Escalated Super Admin\",\"slug\":\"escalated-super-admin\",\"description\":\"Created by low-privilege employee\",\"is_system\":false,\"updated_at\":\"2026-09-29T16:23:15.000000Z\",\"created_at\":\"2026-09-29T16:23:15.000000Z\",\"id\":8,\"permissions\":[]}}"}
  ```
  Additional tests confirmed:
  - `DELETE /api/roles/{id}`: Returned **200 OK** for employee role.
  - `PUT /api/settings`: Returned **200 OK** for employee role.
  - `GET /api/audit-logs`: Returned **200 OK** for employee role.
  - `DELETE /api/devices/{id}`: Returned **200 OK** for employee role.
  - `POST /api/organizations`: Returned **201 Created** for employee role.

### Observation 2: Arbitrary File Write (.php) via Camera Webhook
- **File**: `app/Services/ImageStorageService.php`, lines 25–45:
  ```php
  if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
      $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
      $extension = strtolower($type[1]);
      if ($extension === 'jpeg') {
          $extension = 'jpg';
      }
  } else {
      $extension = 'jpg';
  }
  ...
  $fileName = "{$folder}/{$datePath}/" . Str::random(24) . ".{$extension}";
  Storage::disk('public')->put($fileName, $decodedBinary);
  ```
- **Result**: Any string matched in `\w+` becomes the file extension. Passing `data:image/php;base64,...` causes the service to write a `.php` file directly to `public` disk storage.
- **Empirical Execution**:
  Command: `php artisan test --filter=AdversarialM1Test::test_camera_webhook_arbitrary_file_extension_upload`
  Output:
  ```
  Arbitrary .php file must NOT be written to public storage via base64 upload, but found: stranger_snaps/2026/09/30/LKo74hTlsJrHwCdSCVTGwDfE.php
  Failed asserting that an array is empty.
  ```

### Observation 3: Deactivated User Lockout Bypass with Active Bearer Token
- **File**: `app/Http/Controllers/AuthController.php`, lines 47–52:
  ```php
  if (! $user->is_active) {
      return response()->json([
          'success' => false,
          'message' => 'Your account has been deactivated. Please contact an administrator.',
      ], 403);
  }
  ```
- **Result**: `AuthController::login` rejects deactivated users when issuing a new token. However, Sanctum does not inspect `$user->is_active` during token authentication. Because `CheckPermission` is omitted from the route pipeline, users deactivated after receiving a token can make authenticated requests indefinitely.
- **Empirical Execution**:
  Command: `php artisan test --filter=AdversarialM1Test::test_deactivated_user_with_existing_valid_token_is_immediately_locked_out`
  Output:
  ```
  Deactivated user with active token must be locked out of /api/auth/me with 403
  Failed asserting that 200 matches expected 403.
  ```

### Observation 4: Camera Webhook Unhandled Foreign Key Crash (500 DoS)
- **File**: `app/Http/Controllers/HttpWebhookController.php`, lines 59–82:
  ```php
  $deviceId = trim((string) ($info['DeviceID'] ?? $payload['DeviceID'] ?? ''));
  ...
  $log = AccessLog::create([
      'device_id' => $deviceId,
  ```
- **Database Schema**: `database/migrations/2026_08_22_000003_create_access_logs_table.php`, line 29:
  ```php
  $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
  ```
- **Result**: When an unauthenticated webhook arrives with an unregistered or invalid `DeviceID`, inserting into `access_logs` or `stranger_snaps` triggers an unhandled database exception (`QueryException: FOREIGN KEY constraint failed`), returning HTTP 500.
- **Empirical Execution**:
  Command: `php artisan test --filter=AdversarialM1Test::test_camera_webhook_with_nonexistent_device_id_causes_foreign_key_crash`
  Output: Test confirmed HTTP 500 crash upon receipt of unverified device ID.

### Observation 5: Broken Validation Rule on `RoleController`
- **File**: `app/Http/Controllers/RoleController.php`, line 36 and line 86:
  ```php
  'permissions.*' => 'string|integer',
  ```
- **Result**: In Laravel validator syntax, pipe `|` applies multiple simultaneous rules (AND logic). A value must be both a `string` AND an `integer`.
- **Empirical Execution**:
  Command: `php artisan test --filter=AdversarialM1Test::test_role_controller_validation_defect_makes_permissions_assignment_impossible`
  - Passing strings `['selfservice.view']` yields: `"The permissions.0 field must be an integer."` (422)
  - Passing integers `[1]` yields: `"The permissions.0 field must be a string."` (422)

### Observation 6: Passing Controls Verified
- **Login Rate-Limiting**:
  - Single account/IP: 5 failed attempts locks out on 6th with HTTP 429 (`test_login_rate_limiting_enforces_lockout_after_five_failed_attempts` passed).
  - Casing normalization: Case variations increment the same throttle counter (`test_login_rate_limiting_is_case_insensitive` passed).
  - Successful login clears rate limit counter (`test_successful_login_clears_rate_limiter_attempts` passed).
  - Route throttle: 10 requests from same IP blocks 11th request across different accounts (`test_login_ip_wide_rate_limiting_across_different_emails` passed).
- **Bearer Token Security**:
  - Forged token string returns 401 (`test_request_with_forged_bearer_token_is_rejected_with_401` passed).
  - Expired token (`expires_at` past) returns 401 (`test_request_with_expired_bearer_token_is_rejected_with_401` passed).
  - Revoked/deleted token returns 401 (`test_request_with_revoked_bearer_token_is_rejected_with_401` passed).
  - Malformed Authorization headers return 401 (`test_request_with_malformed_auth_header_is_rejected_with_401` passed).

---

## 2. Logic Chain

1. **Premise 1**: Security requirement R1 explicitly mandates Role-Based Access Control (RBAC) protecting endpoints according to roles and permissions.
2. **Observation 1 Link**: `routes/api.php` applies only `auth:sanctum` without attaching `permission` middleware or verifying permissions in controllers. Consequently, any authenticated user possessing any valid token (including the lowest privilege `employee` role) can invoke destructive administrative operations (`POST/DELETE /api/roles`, `PUT /api/settings`, `DELETE /api/devices`, `GET /api/audit-logs`).
3. **Observation 2 Link**: `ImageStorageService::storeBase64Image` extracts arbitrary regex matches (`\w+`) from the MIME type substring and saves files with that extension. An unauthenticated attacker sending `POST /api/Subscribe/Snap` with a PHP payload in Base64 writes executable `.php` files to public storage (`storage/app/public/...`), creating a critical Remote Code Execution (RCE) / Unrestricted File Upload exposure.
4. **Observation 3 Link**: Deactivation enforcement is isolated exclusively to `AuthController::login`. Sanctum's token authentication pipeline does not inspect `$user->is_active`. Without middleware in `routes/api.php` enforcing account activity, deactivated users retain API access until tokens expire or are manually revoked.
5. **Observation 4 Link**: Edge camera hardware push endpoints (`/api/Subscribe/Verify`, `/api/Subscribe/Snap`) lack validation verifying that `DeviceID` exists in `devices` before attempting foreign-key-constrained inserts, causing unhandled 500 exceptions upon receiving arbitrary device identifiers.
6. **Observation 5 Link**: The validation rule `'permissions.*' => 'string|integer'` in `RoleController` represents contradictory constraints, preventing any user from assigning or updating role permissions via the REST API.
7. **Deduction**: Because the platform permits complete privilege escalation, arbitrary code execution file writes, deactivated user access persistence, and unhandled webhook crashes, Milestone 1 cannot be approved in its current state.

---

## 3. Caveats

- **MQTT Broker Authentication**: Camera telemetry was tested via HTTP webhooks (`/api/Subscribe/*`) and synthetic controller invocations. Direct MQTT broker TCP authentication was not tested against a physical hardware device or running EMQX instance.
- **Frontend Interceptors**: The review focused on backend enforcement. Frontend client guards in `resources/js/api/client.js` or Vue components were not considered sufficient mitigations for absent backend authorization.

---

## 4. Conclusion

Milestone 1 satisfies login rate limiting and bearer token validity constraints. However, it fails critical security requirements in authorization enforcement, file upload sanitation, account lockout persistence, and input validation.

**Verdict**: **REQUEST_CHANGES**

### Actionable Remediation Items Required:
1. **Apply Route-Level RBAC Middleware**:
   Attach the `permission` middleware to guarded route definitions in `routes/api.php` using designated permission slugs (e.g., `middleware(['permission:roles.manage'])`, `middleware(['permission:settings.manage'])`, `middleware(['permission:devices.manage'])`, `middleware(['permission:organizations.manage'])`, etc.).
2. **Enforce Deactivated User Lockout Globally**:
   Add an active account check to the global authenticated pipeline in `routes/api.php` or `bootstrap/app.php` so that tokens belonging to users with `is_active === false` are immediately rejected with HTTP 403.
3. **Restrict Allowed File Extensions in `ImageStorageService`**:
   Sanitize the parsed image extension against a strict allowlist:
   `$allowed = ['jpg', 'jpeg', 'png', 'webp'];`
   If the extension is not in `$allowed`, default strictly to `'jpg'`. Never trust raw client-supplied MIME subtypes.
4. **Validate Webhook Device Identifiers**:
   In `HttpWebhookController::handleVerify` and `handleSnap`, validate that `DeviceID` is non-empty, matches `max:64`, and exists in the `devices` table before attempting database inserts; return appropriate HTTP 400/404/422 responses instead of triggering 500 DB exceptions.
5. **Fix RoleController Permissions Validation**:
   In `RoleController::store` and `update`, change `'permissions.*' => 'string|integer'` to allow either strings or integers (e.g. `'permissions.*' => 'string'` if using slugs, or custom rule).

---

## 5. Verification Method

To independently verify all findings and test passes:

```bash
# Run the adversarial stress test suite:
php artisan test --filter=AdversarialM1Test
```

Expected output:
- **Passing tests (13)**: Rate limiting (attempts, casing, IP throttle, reset), token verification (forged, expired, revoked, malformed headers), and deactivated user login prevention.
- **Failing tests (9)**: Demonstrating privilege escalation bypasses (roles, settings, devices, audit logs), `.php` file write to storage, deactivated token lockout failure, and broken permission validation.

To verify positive controls individually:
```bash
# Rate limiting
php artisan test --filter=test_login_rate_limiting_enforces_lockout_after_five_failed_attempts
php artisan test --filter=test_login_ip_wide_rate_limiting_across_different_emails

# Token validation
php artisan test --filter=test_request_with_forged_bearer_token_is_rejected_with_401
php artisan test --filter=test_request_with_expired_bearer_token_is_rejected_with_401
```
