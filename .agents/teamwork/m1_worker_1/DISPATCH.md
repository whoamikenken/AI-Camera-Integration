# Task Assignment: Milestone 1 — Security Foundation, RBAC & Multi-Tenant Settings Implementation

## Identity & Context
- Agent: teamwork_preview_worker
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_worker_1
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Input Documents
Read the authoritative specifications and explorer blueprints:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md
- /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/m1_auth_rbac_design.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_2/m1_org_settings_design.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_3/m1_frontend_design.md

## Scope & File Ownership
You have exclusive write ownership of:
- `composer.json` (for adding `laravel/sanctum`)
- `database/migrations/` (new migrations for Sanctum, users, roles, permissions, pivots, orgs, locations, departments, designations, settings, audit_logs, device extension)
- `database/seeders/` (`RolesAndPermissionsSeeder.php`, `SettingsSeeder.php`, `DatabaseSeeder.php`)
- `app/Models/` (`User.php`, `Role.php`, `Permission.php`, `Organization.php`, `Location.php`, `Department.php`, `Designation.php`, `Setting.php`, `AuditLog.php`, `Device.php`)
- `app/Traits/Auditable.php`, `app/Services/AuditService.php`, `app/Services/SettingService.php`
- `app/Http/Middleware/CheckPermission.php`, `bootstrap/app.php`
- `app/Http/Controllers/AuthController.php`, `OrganizationController.php`, `SettingController.php`
- `routes/api.php` (public webhooks `/Subscribe/*` and mock `/action/*` preserved; auth and domain endpoints protected)
- `tests/TestCase.php` (auto-auth for existing tests) and `tests/Feature/AuthenticationAndRbacTest.php`
- `resources/js/api/client.js`
- `resources/js/stores/authStore.js`
- `resources/js/views/LoginPage.vue`
- `resources/js/components/settings/` (`SettingsHub.vue`, `DepartmentManager.vue`, `SystemSettings.vue`, `AuditLogViewer.vue`)
- `resources/js/App.vue`

## Verification Requirements
Before reporting completion, you MUST execute:
1. `php artisan migrate:fresh --seed` (ensure clean execution without errors)
2. `php artisan test` (verify all 37 existing tests pass + new M1 tests pass + E2E M1 tests pass with 0 failures)
3. `npm run build` (verify frontend SPA builds cleanly with exit code 0)


## 2026-09-29T16:04:30Z
[Message] timestamp=2026-09-29T16:04:30Z sender=ef2bd8f1-92a2-4f35-b75e-cfb5ce7f56ae priority=MESSAGE_PRIORITY_HIGH content=You are assigned as the Implementation Worker for Milestone 1: Security Foundation, RBAC & Multi-Tenant Settings.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_worker_1
