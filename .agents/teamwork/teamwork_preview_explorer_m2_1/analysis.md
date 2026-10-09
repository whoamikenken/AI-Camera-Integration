# Technical Exploration Report: Milestone M2 Access Control Groups & Zone-Based Dispatching

## Executive Summary
This report provides a comprehensive technical investigation of Milestone M2 (Access Control Groups & Zone-Based Dispatching) for the **Intelligent AI Camera Hub**. It examines existing database schemas, identifies exact migration and pivot requirements, specifies the Eloquent domain model `AccessGroup` and inverse relations on `Device`, `Personnel`, and `Department`, defines the model factory `AccessGroupFactory`, and details the assertions and boundary behaviors mandated by the test suite (Tier 1 `test_f05` through `test_f12`, Tier 2 boundary cases, Tier 3 cross-feature tests, and Tier 4 campus scenarios).

---

## 1. Existing Database Schema & Entity Relationships

### 1.1 `devices` Table
- **Migration**: `database/migrations/2026_08_22_000001_create_devices_table.php` and `database/migrations/2026_09_30_000010_add_organization_and_role_to_devices_table.php`
- **Key Columns**:
  - `id`: `BIGINT UNSIGNED` primary key (`$table->id()`)
  - `device_id`: `VARCHAR(64)` unique hardware identifier index (e.g. `'1299517'`, `'CAM-E2E-...'`)
  - `organization_id`: `BIGINT UNSIGNED` nullable foreign key to `organizations(id)`
  - `location_id`: `BIGINT UNSIGNED` nullable foreign key to `locations(id)`
  - `device_role`: `VARCHAR(32)` (`'entry'`, `'exit'`, `'bidirectional'`, `'visitor_kiosk'`)
  - `department_ids`: `JSON` nullable
  - `is_active`: `BOOLEAN` default `true`
  - `last_heartbeat_at`: `TIMESTAMPTZ` nullable
  - `timestampsTz`: created/updated timestamps
- **Model**: `App\Models\Device`
- **Architectural Criticality**:
  - The primary key is integer `id`. The string hardware identifier is `device_id`.
  - In pivot relationships (`access_group_device`), the foreign key `device_id` refers to `devices.id` (standard Laravel convention matching `$group->devices()->attach($device->id)`).
  - In telemetry/sync tasks (`SyncTask`, `AccessLog`), `device_id` stores the string identifier.

### 1.2 `personnel` Table
- **Migration**: `database/migrations/2026_08_22_000002_create_personnel_table.php` and `database/migrations/2026_09_17_000001_add_extra_fields_to_personnel_table.php`
- **Key Columns**:
  - `id`: `BIGINT UNSIGNED` primary key (`$table->id()`)
  - `customize_id`: `UNSIGNED BIGINT` unique sequence index
  - `person_uuid`: `UUID` unique index
  - `name`: `VARCHAR(64)`
  - `person_type`: `INTEGER` (0: Whitelist, 1: Blacklist)
  - `gender`: `INTEGER` (0: Male, 1: Female)
  - `temp_valid`: `INTEGER` (0: Permanent, 1: Temporary)
  - `valid_begin` / `valid_end`: `TIMESTAMPTZ` nullable
  - `effect_number`: `INTEGER` default 1 (-1: infinite)
  - `photo_path`: `VARCHAR(255)` nullable
  - `photo_base64`: `LONGTEXT` nullable
- **Model**: `App\Models\Personnel`
- **Linkage to Employee**:
  - `Personnel` has a `hasOne(Employee::class)` relationship.
  - Linked to `departments` via `employees.department_id`.

### 1.3 `departments` Table
- **Migration**: `database/migrations/2026_09_30_000008_create_departments_table.php`
- **Key Columns**:
  - `id`: `BIGINT UNSIGNED` primary key (`$table->id()`)
  - `organization_id`: `BIGINT UNSIGNED` foreign key to `organizations(id)` (currently NOT NULL in schema)
  - `name`: `VARCHAR(128)`
  - `code`: `VARCHAR(64)` nullable
  - `parent_id`: `BIGINT UNSIGNED` nullable foreign key to `departments(id)`
  - `head_id`: `UNSIGNED BIGINT` nullable index
  - `description`: `TEXT` nullable
  - `is_active`: `BOOLEAN` default `true`
  - `timestamps` and `softDeletes`
- **Model**: `App\Models\Department`
- **⚠️ Critical Schema Finding (Not-Null Trap in `test_f08`)**:
  - In `test_f08_access_group_department_pivot_auto_grants_access()`, line 1070 executes:
    `$dept = \App\Models\Department::create(['name' => 'Engineering', 'code' => 'ENG-01']);`
  - Notice that **no `organization_id` is supplied** in this test call.
  - Because `departments.organization_id` has a PostgreSQL `NOT NULL` constraint without a database default, direct invocation of `Department::create()` fails with:
    `SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "organization_id" of relation "departments" violates not-null constraint`.
  - **Remediation**:
    The `Department` model should include a `boot()` / `creating` lifecycle hook providing a fallback `organization_id` (e.g. `Organization::first() ?? Organization::create(['name' => 'Default Organization', 'code' => 'ORG-DEFAULT'])`), or a migration modifying `departments.organization_id` to be nullable. Adding the fallback hook in `Department::boot()` protects both existing production behavior and automated unit tests.

### 1.4 `organizations` Table
- **Migration**: `database/migrations/2026_09_30_000005_create_organizations_table.php`
- **Key Columns**:
  - `id`: `BIGINT UNSIGNED` primary key (`$table->id()`)
  - `name`: `VARCHAR(128)`
  - `code`: `VARCHAR(64)` unique
  - `timezone`: `VARCHAR(64)` default `'Asia/Manila'`
  - `is_active`: `BOOLEAN` default `true`
  - `timestamps` and `softDeletes`
- **Model**: `App\Models\Organization`

---

## 2. Requirements for Migrations and Pivot Tables

### 2.1 Table: `access_groups`
- **File**: `database/migrations/2026_10_07_000002_create_access_groups_table.php`
- **Required Columns**:
  | Column Name | Type | Modifiers / Constraints | Notes |
  |-------------|------|-------------------------|-------|
  | `id` | `id()` | Primary Key, Auto Increment | |
  | `organization_id` | `foreignId` | `nullable()`, `constrained('organizations')`, `nullOnDelete()` | **Must be nullable**: all test cases create groups without `organization_id` |
  | `name` | `string(128)` | Not Null | Human-readable name, e.g. "Data Center Secure Zone" |
  | `code` | `string(64)` | `unique()`, `index()` | Unique code identifier, e.g. "ZONE-DC-01" |
  | `description` | `text` | `nullable()` | Operational description |
  | `is_active` | `boolean` | `default(true)`, `index()` | Activation toggle |
  | `created_at` / `updated_at` | `timestamps()` | Nullable timestamps | Standard Laravel timestamps |

### 2.2 Pivot Table: `access_group_device`
- **Required Columns**:
  | Column Name | Type | Modifiers / Constraints |
  |-------------|------|-------------------------|
  | `access_group_id` | `foreignId` | `constrained('access_groups')->cascadeOnDelete()` |
  | `device_id` | `foreignId` | `constrained('devices')->cascadeOnDelete()` |
- **Indexes**:
  - Primary composite key: `$table->primary(['access_group_id', 'device_id']);`
  - Reverse lookup index: `$table->index(['device_id', 'access_group_id']);`

### 2.3 Pivot Table: `access_group_personnel`
- **Required Columns**:
  | Column Name | Type | Modifiers / Constraints |
  |-------------|------|-------------------------|
  | `access_group_id` | `foreignId` | `constrained('access_groups')->cascadeOnDelete()` |
  | `personnel_id` | `foreignId` | `constrained('personnel')->cascadeOnDelete()` |
  | `schedule_rule_id` | `unsignedBigInteger` | `nullable()` (optional time/shift rule) |
- **Indexes**:
  - Primary composite key: `$table->primary(['access_group_id', 'personnel_id']);`
  - Reverse lookup index: `$table->index(['personnel_id', 'access_group_id']);`

### 2.4 Pivot Table: `access_group_department`
- **Required Columns**:
  | Column Name | Type | Modifiers / Constraints |
  |-------------|------|-------------------------|
  | `access_group_id` | `foreignId` | `constrained('access_groups')->cascadeOnDelete()` |
  | `department_id` | `foreignId` | `constrained('departments')->cascadeOnDelete()` |
- **Indexes**:
  - Primary composite key: `$table->primary(['access_group_id', 'department_id']);`
  - Reverse lookup index: `$table->index(['department_id', 'access_group_id']);`

### 2.5 Migration Rollback (`down()`) Ordering
To respect foreign key constraints in PostgreSQL, the `down()` method must drop the child pivot tables before the parent table:
```php
Schema::dropIfExists('access_group_department');
Schema::dropIfExists('access_group_personnel');
Schema::dropIfExists('access_group_device');
Schema::dropIfExists('access_groups');
```

---

## 3. Models and Inverse Relationships

### 3.1 `App\Models\AccessGroup`
```php
namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessGroup extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'access_group_device', 'access_group_id', 'device_id');
    }

    public function personnel(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'access_group_personnel', 'access_group_id', 'personnel_id')
            ->withPivot('schedule_rule_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'access_group_department', 'access_group_id', 'department_id');
    }
}
```

### 3.2 Inverse Relations on Existing Models
1. **`App\Models\Device`**:
   ```php
   public function accessGroups(): BelongsToMany
   {
       return $this->belongsToMany(AccessGroup::class, 'access_group_device', 'device_id', 'access_group_id');
   }
   ```
2. **`App\Models\Personnel`**:
   ```php
   public function accessGroups(): BelongsToMany
   {
       return $this->belongsToMany(AccessGroup::class, 'access_group_personnel', 'personnel_id', 'access_group_id')
           ->withPivot('schedule_rule_id');
   }
   ```
3. **`App\Models\Department`**:
   ```php
   public function accessGroups(): BelongsToMany
   {
       return $this->belongsToMany(AccessGroup::class, 'access_group_department', 'department_id', 'access_group_id');
   }
   ```
4. **`App\Models\Organization`**:
   ```php
   public function accessGroups(): HasMany
   {
       return $this->hasMany(AccessGroup::class);
   }
   ```

---

## 4. Eloquent Model Factory Specification

### 4.1 `database/factories/AccessGroupFactory.php`
```php
namespace Database\Factories;

use App\Models\AccessGroup;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGroup>
 */
class AccessGroupFactory extends Factory
{
    protected $model = AccessGroup::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => 'Zone ' . fake()->unique()->words(2, true),
            'code' => strtoupper('ZONE-' . fake()->unique()->bothify('??-##')),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }
}
```

---

## 5. Test Suite Analysis & Exact Contract Assertions

### 5.1 Tier 1 Tests (`Tier1FeatureCoverageTest.php`)
- **`test_f05_access_group_entity_persists_with_code_uniqueness`**:
  - Gated by: `requireTable('access_groups', 'Milestone 2')`, `requireClass('App\Models\AccessGroup', 'Milestone 2')`
  - Asserts: `AccessGroup::create(['name' => ..., 'code' => 'ZONE-DC-01', ...])` persists to `access_groups` with matching `id`, `code`, and `is_active = true`.
- **`test_f06_access_group_device_pivot_links_hardware`**:
  - Gated by: `requireTable('access_group_device', 'Milestone 2')`
  - Asserts: `$group->devices()->attach($device->id)` links records in `access_group_device`, and `$group->devices->contains($device->id)` evaluates to `true`.
- **`test_f07_access_group_personnel_pivot_links_individuals`**:
  - Gated by: `requireTable('access_group_personnel', 'Milestone 2')`
  - Asserts: `$group->personnel()->attach($personnel->id)` links records in `access_group_personnel`, and `$group->personnel->contains($personnel->id)` evaluates to `true`.
- **`test_f08_access_group_department_pivot_auto_grants_access`**:
  - Gated by: `requireTable('access_group_department', 'Milestone 2')`
  - Asserts: `$dept = Department::create(['name' => 'Engineering', 'code' => 'ENG-01'])` and `$group->departments()->attach($dept->id)` persists to `access_group_department` and `$group->departments->contains($dept->id)` is `true`.
- **`test_f09_access_control_service_resolves_authorized_devices`**:
  - Gated by: `requireClass('App\Services\AccessControlService', 'Milestone 2')`
  - Asserts: `app(AccessControlService::class)->getAuthorizedDevicesForPersonnel($personnel)` returns a `Collection` containing `$device`.
- **`test_f10_sync_personnel_job_dispatches_only_to_authorized_devices`**:
  - Asserts: Dispatching `SyncPersonnelJob($personnel->id, 'EDIT')` only pushes `SyncDevicePersonnelJob` for devices belonging to the authorized access group (`$authDevice->id`), and explicitly **not** for unauthorized devices (`$unauthDevice->id`).
- **`test_f11_access_group_zone_resync_endpoint_dispatches_roster`**:
  - Gated by: `requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2')`
  - Asserts: `POST /api/access-groups/{id}/sync-now` returns HTTP `200`.
- **`test_f12_access_group_manager_vue_component_exists`**:
  - Gated by: `requireFile('resources/js/components/settings/AccessGroupManager.vue', 'Milestone 2')`

### 5.2 Tier 2 Boundary Tests (`Tier2BoundaryTest.php`)
- **`test_boundary_access_group_with_empty_membership_handles_resolution_cleanly`**:
  - When access groups exist in the database, but a given personnel belongs to none, `getAuthorizedDevicesForPersonnel()` must return an empty `Collection` (not null, not error).
- **`test_boundary_system_with_zero_access_groups_falls_back_to_all_active_devices`**:
  - When `AccessGroup::count() === 0`, `getAuthorizedDevicesForPersonnel()` falls back to `Device::where('is_active', true)->get()`. Inactive devices must NOT be included.
- **`test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices`**:
  - When a person is assigned to Group 1 (Dev A, Dev B) and Group 2 (Dev B, Dev C), `getAuthorizedDevicesForPersonnel()` returns exactly 3 devices with deduplicated IDs (`$devices->unique('id')->count() === 3`).

### 5.3 Tier 3 & Tier 4 Cross-Feature and Real-World Workflows
- **`Tier3CrossFeatureTest::test_cross_access_control_scopes_personnel_synchronization_to_zone`**:
  - Multiple cameras, one person assigned to a zone. `SyncPersonnelJob` only queues jobs for zone cameras.
- **`Tier3CrossFeatureTest::test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices`**:
  - Admin calls `POST /api/access-groups/{id}/sync-now`, returns HTTP 200.
- **`Tier4RealWorldScenariosTest::test_scenario_6_multi_building_campus_access_zones_and_diff_synchronization`**:
  - Engineering Zone vs Finance Zone cameras. Engineer synced only to Engineering turnstiles.

---

## 6. AccessControlService Logic Specification

```php
namespace App\Services;

use App\Models\AccessGroup;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Personnel;
use Illuminate\Support\Collection;

class AccessControlService
{
    /**
     * Resolve the deduplicated collection of active devices authorized for the given personnel.
     */
    public function getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection
    {
        // Fallback: If no access groups exist in the system, fall back to all active devices
        if (AccessGroup::count() === 0) {
            return Device::where('is_active', true)->get();
        }

        $devices = collect();

        // 1. Direct personnel group assignments
        $directGroups = $personnel->accessGroups()
            ->where('access_groups.is_active', true)
            ->with(['devices' => fn ($q) => $q->where('is_active', true)])
            ->get();

        foreach ($directGroups as $group) {
            $devices = $devices->concat($group->devices);
        }

        // 2. Departmental group assignments (via linked employee)
        $employee = $personnel->employee ?? Employee::where('personnel_id', $personnel->id)->first();
        if ($employee && $employee->department_id) {
            $deptGroups = AccessGroup::where('is_active', true)
                ->whereHas('departments', fn ($q) => $q->where('departments.id', $employee->department_id))
                ->with(['devices' => fn ($q) => $q->where('is_active', true)])
                ->get();

            foreach ($deptGroups as $group) {
                $devices = $devices->concat($group->devices);
            }
        }

        // Return deduplicated collection of active devices
        return $devices->unique('id')->values();
    }
}
```

---

## 7. SyncPersonnelJob Integration

In `App\Jobs\SyncPersonnelJob::handle()`:
```php
public function handle(CameraMqttService $cameraService): void
{
    $person = $this->personnelId ? Personnel::find($this->personnelId) : null;

    if ($this->targetDeviceId) {
        $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
    } elseif ($person) {
        $accessService = app(\App\Services\AccessControlService::class);
        $devices = $accessService->getAuthorizedDevicesForPersonnel($person);
    } else {
        $devices = Device::where('is_active', true)->get();
    }

    if ($devices->isEmpty()) {
        Log::info("SyncPersonnelJob: No active authorized devices found for personnel {$this->personnelId}");
        return;
    }

    $cId = (int) ($this->customizeIdToDelete ?? ($person ? $person->customize_id : $this->personnelId));

    foreach ($devices as $device) {
        SyncDevicePersonnelJob::dispatch(
            $device->id,
            $this->personnelId,
            $this->action,
            $cId
        );
    }
}
```

---

## 8. Summary Checklist for Implementer
1. **Migration**: Create `database/migrations/2026_10_07_000002_create_access_groups_table.php` containing `access_groups`, `access_group_device`, `access_group_personnel`, `access_group_department`.
2. **Department Fallback**: Ensure `Department` creation without `organization_id` does not fail PostgreSQL `NOT NULL` constraint (add `Department::boot()` fallback).
3. **Models**: Create `App\Models\AccessGroup`, update `Device`, `Personnel`, `Department`, and `Organization` with inverse relationships.
4. **Factory**: Create `database/factories/AccessGroupFactory.php`.
5. **Service**: Create `App\Services\AccessControlService` implementing `getAuthorizedDevicesForPersonnel`.
6. **Job Update**: Refactor `App\Jobs\SyncPersonnelJob` to resolve authorized devices via `AccessControlService`.
7. **Controller & Routes**: Implement `App\Http\Controllers\AccessGroupController` and register `/api/access-groups` resource and `/api/access-groups/{id}/sync-now`.
8. **Frontend**: Create `resources/js/components/settings/AccessGroupManager.vue` and register tab in `SettingsHub.vue`.
