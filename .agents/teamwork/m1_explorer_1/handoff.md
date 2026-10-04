# Handoff Report: Milestone 1 — Auth & RBAC Architecture Exploration

**Agent**: Explorer 1 (`m1_explorer_1`)  
**Mission**: Milestone 1: Security Foundation & RBAC (Features 1 & 2 Blueprint)  
**Date**: 2026-09-30  
**Status**: Complete  

---

## 1. Observation

1. **Composer Dependencies & Sanctum Availability**:
   - `composer.json` (lines 8–16) requires `php: ^8.3`, `laravel/framework: ^13.17`, `laravel/horizon: ^5.48`, `laravel/reverb: ^1.11`, `laravel/tinker: ^3.0`, `opcodesio/log-viewer: ^3.24`, `php-mqtt/client: ^2.3`. `laravel/sanctum` is not present.
   - Command `php artisan --version` returned `Laravel Framework 13.26.1`.
   - Command `composer require --dry-run laravel/sanctum` exited with code 0:
     ```
     Lock file operations: 1 install, 0 updates, 0 removals
       - Locking laravel/sanctum (v4.3.3)
     Package operations: 1 install, 0 updates, 0 removals
       - Installing laravel/sanctum (v4.3.3)
     No security vulnerability advisories found.
     ```

2. **Existing Database Migrations**:
   - `database/migrations/` contains 11 migration files.
   - `0001_01_01_000000_create_users_table.php` defines table `users` with columns `id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `timestamps`.
   - `php artisan migrate:status` shows all 11 existing migrations ran cleanly in batches [1], [2], [3].
   - No tables exist yet for `personal_access_tokens`, `roles`, `permissions`, `role_permission`, or `user_role`.

3. **Current User Model**:
   - `app/Models/User.php` uses `HasFactory` and `Notifiable`, but lacks `HasApiTokens` and RBAC relationship methods (`roles()`, `hasRole()`, `hasPermission()`).

4. **Existing API Routes & Webhook Contracts**:
   - `routes/api.php` lines 42–45:
     ```php
     // Camera HTTP Webhook Event Push Endpoints (HTTP Protocol V1.13 Section 3)
     Route::post('/Subscribe/heartbeat', [\App\Http\Controllers\HttpWebhookController::class, 'handleHeartbeat']);
     Route::post('/Subscribe/Verify', [\App\Http\Controllers\HttpWebhookController::class, 'handleVerify']);
     Route::post('/Subscribe/Snap', [\App\Http\Controllers\HttpWebhookController::class, 'handleSnap']);
     ```
   - `routes/web.php` lines 10–32 defines mock hardware camera API `POST /action/{operator}` for local testing.
   - `bootstrap/app.php` line 17 defines `$middleware->validateCsrfTokens(except: ['action/*']);`.

5. **Existing Test Suite Baseline**:
   - Running `php artisan test` executed 37 tests (119 assertions) in 21.36s with 100% pass (`{"tool":"phpunit","result":"passed","tests":37,"passed":37,"assertions":119}`).
   - Grep search revealed that existing tests (`DeviceManagementTest`, `PersonnelSyncTest`, `HttpProtocolV113Test`, `HistoricalBackfillTest`, `DeviceProbeAndHttpsTest`, `CameraImportPersonnelTest`) issue unauthenticated HTTP calls (`getJson('/api/devices')`, `postJson('/api/personnel')`, etc.).

---

## 2. Logic Chain

1. **Authentication Mechanism Choice** (Supported by Observation 1):
   - Because `composer require laravel/sanctum` installs version `v4.3.3` without conflicts on Laravel 13, and the frontend specification (`PROJECT.md` Feature 3) calls for `resources/js/api/client.js` with Bearer authorization headers and Axios interceptors, Bearer token authentication via Sanctum's personal access tokens is the ideal, stateless, and cross-origin-safe standard for both SPA and external API consumers.

2. **Database Schema Design** (Supported by Observation 2):
   - To support multi-role RBAC without mutating existing camera tables, 5 new migrations are required:
     1. `personal_access_tokens`: Standard Sanctum token storage.
     2. `add_fields_to_users_table`: Adds nullable `phone`, `avatar`, and boolean `is_active` (default true) to `users`.
     3. `roles`: Core roles table with `id`, `name`, `slug` (unique), `description`, `is_system` (boolean).
     4. `permissions`: Core permissions table with `id`, `name`, `slug` (unique), `group`, `description`.
     5. `role_permission` and `user_role`: Many-to-many pivot tables with composite primary keys cascading on delete.

3. **User Model Extension** (Supported by Observation 3):
   - Adding `HasApiTokens` enables `$user->createToken('auth-token')->plainTextToken`.
   - Adding `hasRole($roles)` and `hasPermission($permissions)` enables authorization checks.
   - `hasPermission()` must provide an automatic bypass if `$user->hasRole('super-admin')` and support wildcard matching (e.g. `attendance.*` matching `attendance.view`).

4. **Hardware & Webhook Protection Invariant** (Supported by Observation 4):
   - Edge cameras (X40Y hardware) submit telemetry packets to `/Subscribe/*` without Bearer tokens or browser cookies. Placing `auth:sanctum` across all routes indiscriminately would break camera event streaming.
   - Therefore, `routes/api.php` must be structured into three isolated tiers:
     - Tier 1: Hardware Webhooks (public, unauthenticated).
     - Tier 2: Public Authentication (`/api/auth/login`, throttle: 10/min).
     - Tier 3: Guarded Domain Endpoints (`auth:sanctum` + `permission:...`).

5. **Test Suite Non-Regression Strategy** (Supported by Observation 5):
   - If domain routes (`/api/devices`, `/api/personnel`) are guarded with `auth:sanctum`, the 37 existing tests would fail with 401 Unauthorized unless authenticated.
   - By enhancing base `Tests\TestCase` to authenticate a test user (with `super-admin` role) in `setUp()`, all 37 existing tests will continue passing with zero regressions, while new auth/RBAC tests can selectively disable auto-authentication to verify 401/403 security controls.

---

## 3. Caveats

1. **Session vs. Token SPA Consideration**: If the frontend strictly requires cookie-based session auth instead of Bearer tokens, Sanctum's `EnsureFrontendRequestsAreStateful` middleware can be enabled. However, the blueprint specifies Bearer tokens because `PROJECT.md` dictates Bearer token management in `resources/js/api/client.js` and `authStore.js`.
2. **Organization Scope**: Organization hierarchy (Features 5 & 6) is scheduled for parallel or subsequent implementation within Milestone 1. The RBAC blueprint includes permission definitions (`organizations.view`, `organizations.manage`) ready for immediate integration.
3. **Multi-Tenancy Scoping**: If tenant/organization-level permission filtering is introduced in later milestones, the `CheckPermission` middleware can be easily extended to check `$request->route('organization')` against `$user->organization_id`.

---

## 4. Conclusion

The technical path for Milestone 1 Features 1 & 2 is fully analyzed and validated.
1. The implementation blueprint has been written to:
   `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/m1_auth_rbac_design.md`.
2. The design delivers:
   - Complete migration files and Eloquent models for Sanctum tokens, `roles`, `permissions`, `role_permission`, `user_role`, and `users` extensions.
   - Full seeder specifications for 7 default roles and 12 permission groups with full role-permission mapping.
   - Production-grade `CheckPermission` middleware with super-admin bypass and wildcard resolution.
   - Robust `AuthController` with rate-limited login, logout, profile update, and permission listing.
   - Preserved exemptions for camera webhooks (`/Subscribe/*`) and mock camera endpoints (`/action/*`).
   - A non-regression test strategy in `TestCase` ensuring all 37 existing tests pass.

---

## 5. Verification Method

To verify the implementation once executed by the implementer:

1. **Verify Migrations & Seeding**:
   ```bash
   php artisan migrate:fresh --seed
   ```
   *Expected Result*: Tables `personal_access_tokens`, `roles`, `permissions`, `role_permission`, `user_role` exist, 7 roles and all permissions are seeded, and default users are populated.

2. **Verify Full Automated Test Suite**:
   ```bash
   php artisan test
   ```
   *Expected Result*: All 37 existing tests pass, plus new tests in `tests/Feature/AuthenticationAndRbacTest.php` pass (100% green).

3. **Verify Camera Webhook Public Accessibility**:
   ```bash
   curl -s -X POST http://localhost:8080/api/Subscribe/heartbeat \
     -H "Content-Type: application/json" \
     -d '{"facesluiceId": "CAM-TEST", "time": "2026-09-30 00:00:00"}'
   ```
   *Expected Result*: Returns HTTP 200 `{"code": 200, "desc": "OK"}` without requiring Bearer token.

4. **Verify Authentication & RBAC Enforcement**:
   - `POST /api/auth/login` with `{"email": "admin@camera.hub", "password": "password"}` returns Bearer token.
   - Request to `/api/devices` with invalid token returns HTTP 401.
   - Request to `/api/devices` with token from `employee@camera.hub` returns HTTP 403 (missing `devices.view`).
   - Request to `/api/devices` with token from `security@camera.hub` or `admin@camera.hub` returns HTTP 200.

5. **Files to Inspect**:
   - Blueprint: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/m1_auth_rbac_design.md`
   - Progress: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/progress.md`
   - Briefing: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/BRIEFING.md`
