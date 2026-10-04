# Milestone 1: User Authentication & RBAC Implementation Blueprint

**Milestone**: M1 — Security Foundation, RBAC & Multi-Tenant Settings  
**Features**: Feature 1 (Sanctum / Session Auth API) & Feature 2 (Role-Based Access Control)  
**Author**: Explorer 1 (`m1_explorer_1`)  
**Target Audience**: M1 Implementer, Orchestrator, Reviewers  
**Date**: 2026-09-30  

---

## 1. Executive Summary & Architecture Overview

The Intelligent AI Camera Hub is evolving into an enterprise-grade Attendance and Visitor Management System. This requires transitioning from open API access to a robust security perimeter combining **Laravel Sanctum (v4.x)** token authentication with a granular **Role-Based Access Control (RBAC)** engine.

### Core Objectives & Deliverables
1. **Stateless Bearer Token Authentication (Sanctum)**: Secure user login, token generation (`plainTextToken`), logout token revocation, and user profile management (`/api/auth/*`).
2. **RBAC Engine**: Clean relational data model with four tables (`roles`, `permissions`, `role_permission`, `user_role`) and an extension migration for `users` (`phone`, `avatar`, `is_active`).
3. **Enterprise Seeding**: 7 standard system roles (`super-admin`, `admin`, `hr-manager`, `security`, `receptionist`, `manager`, `employee`) mapped across 12 domain permission groups.
4. **Route Guarding Middleware (`CheckPermission`)**: Attribute/parameter-based route authorization supporting `super-admin` bypass and wildcard matching (e.g. `attendance.*`).
5. **Hardware & Webhook Invariant Preservation**: Absolute exemption of edge camera push webhooks (`/Subscribe/heartbeat`, `/Subscribe/Verify`, `/Subscribe/Snap`, `/action/*`) and MQTT streams from authentication or CSRF requirements.
6. **Zero-Regression Test Strategy**: Maintaining 100% green status on all 37 existing tests while introducing comprehensive new auth/RBAC test suites.

---

## 2. Dependency & Authentication Setup

### 2.1 Package Installation
The application runs on **PHP 8.3+** and **Laravel Framework 13.26.1**. `laravel/sanctum` is currently not present in `composer.json`.

- Installation command:
  ```bash
  composer require laravel/sanctum
  ```
  *(Verified via dry-run: resolves to `laravel/sanctum v4.3.3` with zero dependency conflicts).*

### 2.2 Token-Based vs. Session-Based Authentication
- **Selected Mechanism**: **Bearer Token Authentication** using Laravel Sanctum personal access tokens.
- **Rationale**:
  - The Vue 3 SPA frontend (`resources/js/api/client.js`) uses Axios with centralized interceptors adding `Authorization: Bearer <token>` to request headers.
  - Avoids cross-origin cookie/CSRF issues during local development (Vite dev server on port 5173 vs Laravel backend on port 8000/8080).
  - Compatible with mobile apps, API integrations, and kiosk terminals.
  - Tokens are hashed using SHA-256 before storage in `personal_access_tokens`.

### 2.3 User Model Updates
Add `Laravel\Sanctum\HasApiTokens` to `App\Models\User`:
```php
namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    // ...
}
```

---

## 3. Database Schema & Migration Blueprint

The database is PostgreSQL 16+. Migrations must strictly avoid breaking existing tables (`devices`, `personnel`, `access_logs`, `stranger_snaps`, `sync_tasks`).

### 3.1 Migration 1: `personal_access_tokens` table
File: `database/migrations/2026_09_30_000001_create_personal_access_tokens_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
```

### 3.2 Migration 2: User profile & status extensions
File: `database/migrations/2026_09_30_000002_add_fields_to_users_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('avatar', 255)->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar', 'is_active']);
        });
    }
};
```

### 3.3 Migration 3: `roles` table
File: `database/migrations/2026_09_30_000003_create_roles_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false); // Prevents accidental deletion of default roles
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
```

### 3.4 Migration 4: `permissions` table
File: `database/migrations/2026_09_30_000004_create_permissions_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->string('group', 64)->index(); // e.g., 'attendance', 'visitors', 'employees'
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
```

### 3.5 Migration 5: `role_permission` & `user_role` pivot tables
File: `database/migrations/2026_09_30_000005_create_rbac_pivot_tables.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
    }
};
```

---

## 4. Eloquent Models Blueprint

### 4.1 Role Model (`App\Models\Role`)
File: `app/Models/Role.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role');
    }

    public function givePermission(string|Permission $permission): void
    {
        $perm = is_string($permission)
            ? Permission::firstOrCreate(['slug' => $permission], ['name' => $permission, 'group' => 'general'])
            : $permission;

        $this->permissions()->syncWithoutDetaching([$perm->id]);
    }

    public function revokePermission(string|Permission $permission): void
    {
        $perm = is_string($permission)
            ? Permission::where('slug', $permission)->first()
            : $permission;

        if ($perm) {
            $this->permissions()->detach($perm->id);
        }
    }

    public function syncPermissions(array $permissions): void
    {
        $permissionIds = [];
        foreach ($permissions as $perm) {
            if ($perm instanceof Permission) {
                $permissionIds[] = $perm->id;
            } elseif (is_numeric($perm)) {
                $permissionIds[] = (int) $perm;
            } elseif (is_string($perm)) {
                $p = Permission::where('slug', $perm)->first();
                if ($p) {
                    $permissionIds[] = $p->id;
                }
            }
        }
        $this->permissions()->sync($permissionIds);
    }
}
```

### 4.2 Permission Model (`App\Models\Permission`)
File: `app/Models/Permission.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'group',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
```

### 4.3 User Model Extension (`App\Models\User`)
File: `app/Models/User.php`
```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function hasRole(string|array $roles): bool
    {
        $roleSlugs = $this->roles->pluck('slug')->toArray();

        if (is_array($roles)) {
            return !empty(array_intersect($roles, $roleSlugs));
        }

        return in_array($roles, $roleSlugs, true);
    }

    public function hasPermission(string|array $permissions): bool
    {
        // super-admin has full unconditional bypass
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $userPerms = $this->getAllPermissions();
        $checkList = is_array($permissions) ? $permissions : [$permissions];

        foreach ($checkList as $required) {
            // Direct exact match
            if ($userPerms->contains($required)) {
                return true;
            }

            // Wildcard matching: e.g. user permission 'attendance.*' grants 'attendance.view'
            foreach ($userPerms as $userPerm) {
                if (str_ends_with($userPerm, '.*')) {
                    $prefix = substr($userPerm, 0, -2);
                    if (str_starts_with($required, $prefix . '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getAllPermissions(): \Illuminate\Support\Collection
    {
        if ($this->hasRole('super-admin')) {
            return Permission::pluck('slug');
        }

        return $this->roles->flatMap(function ($role) {
            return $role->permissions->pluck('slug');
        })->unique()->values();
    }

    public function assignRole(string|Role $role): void
    {
        $roleModel = is_string($role)
            ? Role::where('slug', $role)->firstOrFail()
            : $role;

        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    public function removeRole(string|Role $role): void
    {
        $roleModel = is_string($role)
            ? Role::where('slug', $role)->first()
            : $role;

        if ($roleModel) {
            $this->roles()->detach($roleModel->id);
        }
    }
}
```

---

## 5. Seeding Strategy

### 5.1 The 7 Standard System Roles
| Role Slug | Role Name | System Role | Primary Scope |
|---|---|---|---|
| `super-admin` | Super Administrator | Yes | Complete, unconstrained access to entire system and configuration |
| `admin` | Administrator | Yes | Full business management across organization, sites, settings, and users |
| `hr-manager` | HR Manager | Yes | Employee management, shifts, attendance, leave approval, and payroll reports |
| `security` | Security Officer | Yes | Live camera streams, devices, face audit, stranger snaps, and visitor watchlist |
| `receptionist` | Receptionist | Yes | Visitor pre-registration, check-in, badges, and checkout |
| `manager` | Department Manager | Yes | Departmental attendance oversight, leave approval, and regularization approvals |
| `employee` | Employee | Yes | Self-service attendance records, leave requests, and visitor invitations |

### 5.2 Permission Catalog by Domain
```php
$permissions = [
    // Attendance Domain
    ['slug' => 'attendance.view',     'name' => 'View Attendance',     'group' => 'attendance', 'description' => 'View attendance roster, dashboard, and calendars'],
    ['slug' => 'attendance.manage',   'name' => 'Manage Attendance',   'group' => 'attendance', 'description' => 'Manual clock-in/out and attendance status override'],
    ['slug' => 'attendance.approve',  'name' => 'Approve Regularization','group' => 'attendance', 'description' => 'Approve or reject attendance regularization requests'],
    ['slug' => 'attendance.export',   'name' => 'Export Attendance',   'group' => 'attendance', 'description' => 'Export attendance rosters to CSV/PDF/Excel'],

    // Employee Domain
    ['slug' => 'employees.view',      'name' => 'View Employees',      'group' => 'employees',  'description' => 'View employee directory and profiles'],
    ['slug' => 'employees.create',    'name' => 'Create Employees',    'group' => 'employees',  'description' => 'Add new employee records'],
    ['slug' => 'employees.edit',      'name' => 'Edit Employees',      'group' => 'employees',  'description' => 'Update employee details and assignments'],
    ['slug' => 'employees.delete',    'name' => 'Delete Employees',    'group' => 'employees',  'description' => 'Terminate or soft-delete employees'],
    ['slug' => 'employees.import',    'name' => 'Import Employees',    'group' => 'employees',  'description' => 'Bulk import employees via CSV/Excel'],
    ['slug' => 'employees.export',    'name' => 'Export Employees',    'group' => 'employees',  'description' => 'Export employee list'],

    // Visitor Management Domain
    ['slug' => 'visitors.view',       'name' => 'View Visitors',       'group' => 'visitors',   'description' => 'View active visitors and visit logs'],
    ['slug' => 'visitors.checkin',    'name' => 'Check In Visitors',   'group' => 'visitors',   'description' => 'Perform visitor check-in, take photo, and issue badges'],
    ['slug' => 'visitors.checkout',   'name' => 'Check Out Visitors',  'group' => 'visitors',   'description' => 'Check out visitors and revoke camera biometric access'],
    ['slug' => 'visitors.preregister','name' => 'Pre-register Visitors','group' => 'visitors',  'description' => 'Invite and pre-register expected visitors'],
    ['slug' => 'visitors.manage',     'name' => 'Manage Visitors',     'group' => 'visitors',   'description' => 'Manage visitor profiles and watchlist'],

    // Devices & Hardware Domain
    ['slug' => 'devices.view',        'name' => 'View Devices',        'group' => 'devices',    'description' => 'View camera list, statuses, and live telemetry'],
    ['slug' => 'devices.manage',      'name' => 'Manage Devices',      'group' => 'devices',    'description' => 'Configure camera settings, reboot, probe, and sync'],
    ['slug' => 'devices.delete',      'name' => 'Delete Devices',      'group' => 'devices',    'description' => 'Remove devices from camera fleet'],
    ['slug' => 'devices.audit',       'name' => 'Audit Devices',       'group' => 'devices',    'description' => 'Perform camera face library audit and backfill'],

    // Biometric Face Library (Personnel)
    ['slug' => 'personnel.view',      'name' => 'View Personnel',      'group' => 'personnel',  'description' => 'View face library records'],
    ['slug' => 'personnel.create',    'name' => 'Create Personnel',    'group' => 'personnel',  'description' => 'Enroll face records and credentials'],
    ['slug' => 'personnel.edit',      'name' => 'Edit Personnel',      'group' => 'personnel',  'description' => 'Update face photos and schedules'],
    ['slug' => 'personnel.delete',    'name' => 'Delete Personnel',    'group' => 'personnel',  'description' => 'Delete personnel and push deletion to cameras'],
    ['slug' => 'personnel.sync',      'name' => 'Sync Personnel',      'group' => 'personnel',  'description' => 'Trigger immediate sync job to devices'],

    // Shifts & Schedules
    ['slug' => 'shifts.view',         'name' => 'View Shifts',         'group' => 'shifts',     'description' => 'View shifts and scheduling calendars'],
    ['slug' => 'shifts.manage',       'name' => 'Manage Shifts',       'group' => 'shifts',     'description' => 'Create, edit shifts, assignments, and holidays'],

    // Leaves Management
    ['slug' => 'leaves.view',         'name' => 'View Leaves',         'group' => 'leaves',     'description' => 'View leave requests and quotas'],
    ['slug' => 'leaves.apply',        'name' => 'Apply Leaves',        'group' => 'leaves',     'description' => 'Submit leave requests for self'],
    ['slug' => 'leaves.manage',       'name' => 'Manage Leaves',       'group' => 'leaves',     'description' => 'Configure leave types and policy balances'],
    ['slug' => 'leaves.approve',      'name' => 'Approve Leaves',      'group' => 'leaves',     'description' => 'Approve or reject leave applications'],

    // Reports & Analytics
    ['slug' => 'reports.view',        'name' => 'View Reports',        'group' => 'reports',    'description' => 'Access analytics dashboard and summaries'],
    ['slug' => 'reports.export',      'name' => 'Export Reports',      'group' => 'reports',    'description' => 'Export CSV, XLSX, and PDF reports'],
    ['slug' => 'reports.payroll',     'name' => 'Payroll Export',      'group' => 'reports',    'description' => 'Generate and export payroll-ready data'],

    // Organization Hierarchy
    ['slug' => 'organizations.view',  'name' => 'View Organizations',  'group' => 'organizations','description' => 'View departments, locations, and designations'],
    ['slug' => 'organizations.manage','name' => 'Manage Organizations','group' => 'organizations','description' => 'Create and modify organizational hierarchy'],

    // Global Settings & Audit
    ['slug' => 'settings.view',       'name' => 'View Settings',       'group' => 'settings',   'description' => 'View system configuration and audit logs'],
    ['slug' => 'settings.manage',     'name' => 'Manage Settings',     'group' => 'settings',   'description' => 'Update system settings'],

    // User & Role Administration
    ['slug' => 'users.view',          'name' => 'View Users',          'group' => 'users',      'description' => 'View system user accounts'],
    ['slug' => 'users.manage',        'name' => 'Manage Users',        'group' => 'users',      'description' => 'Create, edit, and deactivate user accounts'],
    ['slug' => 'roles.manage',        'name' => 'Manage Roles',        'group' => 'roles',      'description' => 'Manage RBAC roles and permissions'],

    // Self-Service Portal
    ['slug' => 'selfservice.view',    'name' => 'View Self-Service',   'group' => 'selfservice','description' => 'Access personal attendance, leaves, and punches'],
];
```

### 5.3 Role-Permission Assignment Matrix
- **`super-admin`**: All permissions (bypassed in code, plus all assigned).
- **`admin`**: All permissions across `attendance.*`, `employees.*`, `visitors.*`, `devices.*`, `personnel.*`, `shifts.*`, `leaves.*`, `reports.*`, `organizations.*`, `settings.*`, `users.*`, `roles.*`, `selfservice.*`.
- **`hr-manager`**:
  - `attendance.*`, `employees.*`, `shifts.*`, `leaves.*`, `reports.*`
  - `organizations.view`, `organizations.manage`
  - `personnel.view`, `personnel.sync`
  - `selfservice.view`
- **`security`**:
  - `devices.*`, `personnel.view`
  - `visitors.view`, `visitors.manage`
  - `attendance.view`
- **`receptionist`**:
  - `visitors.*`
  - `employees.view`
  - `selfservice.view`
- **`manager`**:
  - `employees.view`
  - `attendance.view`, `attendance.approve`
  - `leaves.view`, `leaves.approve`, `leaves.apply`
  - `visitors.preregister`
  - `reports.view`
  - `selfservice.view`
- **`employee`**:
  - `selfservice.view`
  - `leaves.apply`
  - `visitors.preregister`

### 5.4 Default Seeded Accounts
File: `database/seeders/UserSeeder.php`
| Email | Password | Assigned Role | Display Name |
|---|---|---|---|
| `admin@camera.hub` | `password` | `super-admin` | System Administrator |
| `hr@camera.hub` | `password` | `hr-manager` | HR Manager |
| `security@camera.hub` | `password` | `security` | Chief Security Officer |
| `reception@camera.hub` | `password` | `receptionist` | Front Desk Receptionist |
| `manager@camera.hub` | `password` | `manager` | Operations Manager |
| `employee@camera.hub` | `password` | `employee` | John Employee |

---

## 6. Route Guarding & Middleware Architecture

### 6.1 `CheckPermission` Middleware
File: `app/Http/Middleware/CheckPermission.php`
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permissions
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact an administrator.',
            ], 403);
        }

        // If no permissions specified on route, being authenticated is sufficient
        if (empty($permissions)) {
            return $next($request);
        }

        // Super-admin always bypasses
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        // Verify if user possesses required permission (OR logic between args)
        if ($user->hasPermission($permissions)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Missing required permission: ' . implode(', ', $permissions),
        ], 403);
    }
}
```

### 6.2 Middleware Registration in `bootstrap/app.php`
Register the middleware alias `'permission'` and configure CSRF exemptions:
```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserved CSRF exemptions for camera hardware and webhooks
        $middleware->validateCsrfTokens(except: [
            'action/*',
            'api/*',
            'Subscribe/*',
        ]);

        // Register custom route middleware aliases
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

---

## 7. Camera Hardware & Webhook Invariant Preservation

### 7.1 Critical Exemption Rules
1. **Camera HTTP Webhooks**:
   Edge cameras automatically deliver verification logs, stranger snaps, and heartbeats via HTTP POST to:
   - `POST /Subscribe/heartbeat` & `POST /api/Subscribe/heartbeat`
   - `POST /Subscribe/Verify` & `POST /api/Subscribe/Verify`
   - `POST /Subscribe/Snap` & `POST /api/Subscribe/Snap`
   **THESE ENDPOINTS MUST NEVER BE PLACED BEHIND `auth:sanctum` OR PERMISSION CHECKS.**
   Camera firmware does not support authentication headers or session cookies.
2. **Mock Hardware Camera API**:
   - `POST /action/{operator}` in `routes/web.php` simulates camera responses for testing. It must remain completely unauthenticated and exempt from CSRF.
3. **MQTT Telemetry Ingestion**:
   - `php artisan mqtt:listen` runs as a CLI worker, subscribing directly to Mosquitto/EMQX. It is completely unaffected by HTTP middleware.

---

## 8. Authentication Controller (`AuthController`) Specification

File: `app/Http/Controllers/AuthController.php`

### 8.1 API Endpoints
| HTTP Method | Route | Middleware | Purpose |
|---|---|---|---|
| `POST` | `/api/auth/login` | `throttle:10,1` | Validate credentials, issue Sanctum Bearer token |
| `POST` | `/api/auth/logout` | `auth:sanctum` | Revoke active access token |
| `GET` | `/api/auth/profile` / `/api/auth/me` | `auth:sanctum` | Retrieve authenticated user profile, roles, and permissions |
| `PUT` | `/api/auth/profile` | `auth:sanctum` | Update name, phone, avatar, or change password |
| `GET` | `/api/auth/permissions` | `auth:sanctum` | Retrieve list of authorized permission slugs for frontend gating |

### 8.2 Controller Implementation Details
```php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate user and issue Bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:64',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->email) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact an administrator.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $deviceName = $request->input('device_name', 'web-spa');
        $token = $user->createToken($deviceName)->plainTextToken;

        $user->load('roles.permissions');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'roles' => $user->roles->pluck('slug'),
                'permissions' => $user->getAllPermissions(),
            ],
        ]);
    }

    /**
     * Revoke active access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get current user profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles.permissions');

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('slug'),
                'permissions' => $user->getAllPermissions(),
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Update user profile or password.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'current_password' => 'required_with:password|string',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if (!empty($validated['password'])) {
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The provided current password does not match our records.',
                    'errors' => ['current_password' => ['Incorrect current password.']],
                ], 422);
            }
            $user->password = Hash::make($validated['password']);
        }

        if (isset($validated['name'])) $user->name = $validated['name'];
        if (array_key_exists('phone', $validated)) $user->phone = $validated['phone'];
        if (array_key_exists('avatar', $validated)) $user->avatar = $validated['avatar'];

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * List user permissions.
     */
    public function permissions(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'roles' => $request->user()->roles->pluck('slug'),
            'permissions' => $request->user()->getAllPermissions(),
        ]);
    }
}
```

---

## 9. Protected Routes Architecture (`routes/api.php`)

Restructure `routes/api.php` into three distinct architectural tiers:

```php
<?php

use App\Http\Controllers\AccessLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardStatsController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HttpWebhookController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StrangerSnapController;
use App\Http\Controllers\SyncTaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tier 1: Hardware Webhooks (UNAUTHENTICATED)
|--------------------------------------------------------------------------
| AI Cameras push real-time events without HTTP Bearer tokens.
*/
Route::post('/Subscribe/heartbeat', [HttpWebhookController::class, 'handleHeartbeat']);
Route::post('/Subscribe/Verify', [HttpWebhookController::class, 'handleVerify']);
Route::post('/Subscribe/Snap', [HttpWebhookController::class, 'handleSnap']);

/*
|--------------------------------------------------------------------------
| Tier 2: Public Authentication Endpoints (UNAUTHENTICATED)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Tier 3: Guarded Domain Endpoints (AUTHENTICATED via auth:sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    // Current user session & profile
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'profile']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::get('/permissions', [AuthController::class, 'permissions']);
    });

    // Dashboard Metrics
    Route::get('/stats', [DashboardStatsController::class, 'index']);

    // RBAC Administration (Admin only)
    Route::middleware(['permission:roles.manage'])->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']);
    });

    // Device Management
    Route::middleware(['permission:devices.view'])->group(function () {
        Route::get('devices', [DeviceController::class, 'index']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::get('devices/{device}/sys-param', [DeviceController::class, 'getSysParam']);
        Route::get('devices/{device}/mqtt-param', [DeviceController::class, 'getMqttParam']);
        Route::get('devices/{device}/search-camera-list', [DeviceController::class, 'searchCameraList']);
        Route::get('devices/{device}/subscribe', [DeviceController::class, 'getSubscribe']);
        Route::get('devices/{device}/device-info', [DeviceController::class, 'getDeviceInformation']);
        Route::get('devices/{device}/search-person-num', [DeviceController::class, 'searchPersonNum']);
        Route::get('devices/{device}/handshake-data', [DeviceController::class, 'getHandSharkData']);
        Route::get('devices/{device}/flow-count', [DeviceController::class, 'getCount']);
        Route::get('devices/{device}/audit', [DeviceController::class, 'audit']);
    });

    Route::middleware(['permission:devices.manage'])->group(function () {
        Route::post('devices/probe', [DeviceController::class, 'probe']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::patch('devices/{device}', [DeviceController::class, 'update']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:devices.delete');
        Route::post('devices/{device}/test-connection', [DeviceController::class, 'testConnection']);
        Route::post('devices/{device}/reboot', [DeviceController::class, 'reboot']);
        Route::post('devices/{device}/sys-param', [DeviceController::class, 'setSysParam']);
        Route::post('devices/{device}/sync-mqtt', [DeviceController::class, 'syncMqtt']);
        Route::post('devices/{device}/sync-time', [DeviceController::class, 'setSysTime']);
        Route::post('devices/{device}/manual-push-records', [DeviceController::class, 'manualPushRecords']);
        Route::post('devices/{device}/manual-push-snaps', [DeviceController::class, 'manualPushSnaps']);
        Route::post('devices/{device}/factory-reset', [DeviceController::class, 'factoryReset']);
        Route::post('devices/{device}/clear-face-database', [DeviceController::class, 'deleteAllPersons']);
        Route::post('devices/{device}/import-personnel', [DeviceController::class, 'importPersonnel']);
        Route::post('devices/{device}/subscribe', [DeviceController::class, 'subscribe']);
        Route::post('devices/{device}/unsubscribe', [DeviceController::class, 'unsubscribe']);
        Route::post('devices/{device}/search-person', [DeviceController::class, 'searchPerson']);
        Route::post('devices/{device}/handshake-data', [DeviceController::class, 'setHandSharkData']);
        Route::post('devices/{device}/upgrade', [DeviceController::class, 'upgradeFirmware']);
    });

    // Personnel / Face Library
    Route::middleware(['permission:personnel.view'])->group(function () {
        Route::get('personnel', [PersonnelController::class, 'index']);
        Route::get('personnel/{personnel}', [PersonnelController::class, 'show']);
    });

    Route::middleware(['permission:personnel.manage'])->group(function () {
        Route::post('personnel', [PersonnelController::class, 'store'])->middleware('permission:personnel.create');
        Route::put('personnel/{personnel}', [PersonnelController::class, 'update'])->middleware('permission:personnel.edit');
        Route::post('personnel/{personnel}', [PersonnelController::class, 'update'])->middleware('permission:personnel.edit');
        Route::delete('personnel/{personnel}', [PersonnelController::class, 'destroy'])->middleware('permission:personnel.delete');
        Route::post('personnel/{personnel}/sync-now', [PersonnelController::class, 'syncNow'])->middleware('permission:personnel.sync');
    });

    // Access Logs & Stranger Captures
    Route::middleware(['permission:attendance.view'])->group(function () {
        Route::get('access-logs', [AccessLogController::class, 'index']);
        Route::get('access-logs/{accessLog}', [AccessLogController::class, 'show']);
    });

    Route::middleware(['permission:devices.view'])->group(function () {
        Route::get('stranger-snaps', [StrangerSnapController::class, 'index']);
        Route::get('stranger-snaps/{strangerSnap}', [StrangerSnapController::class, 'show']);
    });

    // Sync Tasks Outbox
    Route::middleware(['permission:devices.manage'])->group(function () {
        Route::get('sync-tasks', [SyncTaskController::class, 'index']);
        Route::post('sync-tasks/{syncTask}/retry', [SyncTaskController::class, 'retry']);
    });
});
```

---

## 10. Non-Regression & Automated Test Suite Strategy

### 10.1 Preserving Existing 37 Tests
Currently, 6 existing test files call `/api/devices`, `/api/personnel`, `/api/sync-tasks` without passing authentication tokens:
1. `DeviceManagementTest.php`
2. `PersonnelSyncTest.php`
3. `HttpProtocolV113Test.php`
4. `HistoricalBackfillTest.php`
5. `DeviceProbeAndHttpsTest.php`
6. `CameraImportPersonnelTest.php`

To guarantee **zero regressions** and satisfy the acceptance criteria (`Complete automated test suite passes via php artisan test`), the implementer must adopt one of the following two verified patterns:

#### Recommended Pattern: Base `TestCase` Authentication Helper / Auto-ActingAs
In `tests/TestCase.php`:
```php
namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected ?User $authenticatedUser = null;

    /**
     * Authenticate a test user with a specific role (default: super-admin).
     */
    protected function authenticateTestUser(string $roleSlug = 'super-admin'): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], [
            'name' => ucwords(str_replace('-', ' ', $roleSlug)),
            'is_system' => true,
        ]);

        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        Sanctum::actingAs($user, ['*']);
        $this->authenticatedUser = $user;

        return $user;
    }

    /**
     * Auto-authenticate for existing tests if no user is authenticated.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Automatically authenticate a super-admin for feature test requests
        // unless specifically disabled by a test flag
        if (!property_exists($this, 'disableAutoAuth') || !$this->disableAutoAuth) {
            $this->authenticateTestUser('super-admin');
        }
    }
}
```
*Benefits*:
- All 37 existing tests immediately pass without needing modifications to individual legacy test files.
- Dedicated authentication/RBAC tests can simply set `$this->disableAutoAuth = true;` or call `$this->withoutMiddleware()` to test unauthenticated 401 and unauthorized 403 scenarios.

### 10.2 New Unit & Feature Tests to Implement
Create `tests/Feature/AuthenticationAndRbacTest.php`:
1. `test_user_can_login_with_valid_credentials_and_receive_token()`
2. `test_login_fails_with_invalid_credentials()`
3. `test_login_rate_limiting_triggers_after_5_failed_attempts()`
4. `test_deactivated_user_cannot_login()`
5. `test_authenticated_user_can_view_profile_and_permissions()`
6. `test_authenticated_user_can_logout_and_token_is_revoked()`
7. `test_unauthenticated_request_to_protected_routes_returns_401()`
8. `test_user_without_required_permission_returns_403()`
9. `test_user_with_required_permission_can_access_route()`
10. `test_super_admin_bypasses_all_permission_checks()`
11. `test_wildcard_permission_grants_access_to_sub_actions()`
12. `test_camera_webhooks_remain_accessible_without_authentication()`
13. `test_database_seeder_creates_all_roles_permissions_and_default_users()`

---

## 11. Implementation Action Plan for Implementer

| Step | Action | Files / Commands |
|---|---|---|
| 1 | Require `laravel/sanctum` | `composer require laravel/sanctum` |
| 2 | Create migrations | `database/migrations/2026_09_30_000001_create_personal_access_tokens_table.php`<br>`database/migrations/2026_09_30_000002_add_fields_to_users_table.php`<br>`database/migrations/2026_09_30_000003_create_roles_table.php`<br>`database/migrations/2026_09_30_000004_create_permissions_table.php`<br>`database/migrations/2026_09_30_000005_create_rbac_pivot_tables.php` |
| 3 | Create models | `app/Models/Role.php`<br>`app/Models/Permission.php`<br>Update `app/Models/User.php` |
| 4 | Create seeders | `database/seeders/RolePermissionSeeder.php`<br>`database/seeders/UserSeeder.php`<br>Update `database/seeders/DatabaseSeeder.php` |
| 5 | Create middleware | `app/Http/Middleware/CheckPermission.php`<br>Register in `bootstrap/app.php` |
| 6 | Create controller | `app/Http/Controllers/AuthController.php`<br>`app/Http/Controllers/RoleController.php` |
| 7 | Update routes | `routes/api.php` (grouping webhooks, auth, and protected domain routes) |
| 8 | Update `tests/TestCase.php` | Add auto-auth for super-admin |
| 9 | Add Auth & RBAC test suite | `tests/Feature/AuthenticationAndRbacTest.php` |
| 10 | Run migrations & tests | `php artisan migrate:fresh --seed && php artisan test` |

---
*End of Architectural Design Blueprint.*
