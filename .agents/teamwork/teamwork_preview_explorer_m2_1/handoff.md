# Handoff Report: Milestone M2 Access Control Groups & Zone-Based Dispatching

## 1. Observation

### 1.1 Existing Migrations & Schemas
- **`devices` table**:
  - Defined in `database/migrations/2026_08_22_000001_create_devices_table.php` (line 12: `$table->id()`, line 13: `$table->string('device_id', 64)->unique()->index()`) and augmented in `database/migrations/2026_09_30_000010_add_organization_and_role_to_devices_table.php` (line 12: `$table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete()`, line 21: `$table->string('device_role', 32)->default('bidirectional')`).
  - Integer `id` is the primary key. String `device_id` is the camera hardware identifier.
- **`personnel` table**:
  - Defined in `database/migrations/2026_08_22_000002_create_personnel_table.php` (line 12: `$table->id()`, line 13: `$table->unsignedBigInteger('customize_id')->unique()->index()`, line 14: `$table->uuid('person_uuid')->unique()->index()`).
  - Integer `id` is the primary key.
- **`departments` table**:
  - Defined in `database/migrations/2026_09_30_000008_create_departments_table.php` (line 12: `$table->id()`, line 13: `$table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete()`).
  - `organization_id` has a PostgreSQL `NOT NULL` constraint without a column default.
  - Direct execution in PostgreSQL: `\App\Models\Department::create(['name' => 'Engineering', 'code' => 'ENG-01']);` fails with verbatim error:
    `SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "organization_id" of relation "departments" violates not-null constraint`.
- **`organizations` table**:
  - Defined in `database/migrations/2026_09_30_000005_create_organizations_table.php` (line 12: `$table->id()`, line 14: `$table->string('code', 64)->unique()`).

### 1.2 Test Assertions & Test Harness
- **`tests/Feature/E2E/Tier1FeatureCoverageTest.php`**:
  - Line 1004: `test_f05_access_group_entity_persists_with_code_uniqueness()`:
    - Calls `requireTable('access_groups', 'Milestone 2')` and `requireClass('App\Models\AccessGroup', 'Milestone 2')`.
    - Creates `AccessGroup::create(['name' => 'Data Center Secure Zone', 'code' => 'ZONE-DC-01', 'description' => '...', 'is_active' => true])` (notice: no `organization_id` passed).
    - Asserts `assertDatabaseHas('access_groups', ['id' => $group->id, 'code' => 'ZONE-DC-01', 'is_active' => true])`.
  - Line 1023: `test_f06_access_group_device_pivot_links_hardware()`:
    - Calls `requireTable('access_group_device', 'Milestone 2')`.
    - `$group->devices()->attach($device->id)`.
    - Asserts `$this->assertTrue($group->devices->contains($device->id))` and `assertDatabaseHas('access_group_device', ['access_group_id' => $group->id, 'device_id' => $device->id])`.
  - Line 1044: `test_f07_access_group_personnel_pivot_links_individuals()`:
    - Calls `requireTable('access_group_personnel', 'Milestone 2')`.
    - `$group->personnel()->attach($personnel->id)`.
    - Asserts `$this->assertTrue($group->personnel->contains($personnel->id))` and `assertDatabaseHas('access_group_personnel', ['access_group_id' => $group->id, 'personnel_id' => $personnel->id])`.
  - Line 1065: `test_f08_access_group_department_pivot_auto_grants_access()`:
    - Calls `requireTable('access_group_department', 'Milestone 2')`.
    - Line 1070: `$dept = \App\Models\Department::create(['name' => 'Engineering', 'code' => 'ENG-01']);` (no `organization_id` provided).
    - `$group->departments()->attach($dept->id)`.
    - Asserts `$this->assertTrue($group->departments->contains($dept->id))` and `assertDatabaseHas('access_group_department', ['access_group_id' => $group->id, 'department_id' => $dept->id])`.
  - Line 1086: `test_f09_access_control_service_resolves_authorized_devices()`:
    - Calls `app(\App\Services\AccessControlService::class)->getAuthorizedDevicesForPersonnel($personnel)`.
    - Asserts `$this->assertTrue($devices->contains('id', $device->id))`.
  - Line 1107: `test_f10_sync_personnel_job_dispatches_only_to_authorized_devices()`:
    - Dispatches `SyncPersonnelJob($personnel->id, 'EDIT')`.
    - Asserts `SyncDevicePersonnelJob` is pushed for authorized device and NOT pushed for unauthorized device.
  - Line 1136: `test_f11_access_group_zone_resync_endpoint_dispatches_roster()`:
    - Calls `POST /api/access-groups/{id}/sync-now`.
    - Asserts HTTP 200.
  - Line 1155: `test_f12_access_group_manager_vue_component_exists()`:
    - Asserts `resources/js/components/settings/AccessGroupManager.vue` exists.

- **`tests/Feature/E2E/Tier2BoundaryTest.php`**:
  - Line 321: `test_boundary_access_group_with_empty_membership_handles_resolution_cleanly()`: asserts returning an empty `Collection` when groups exist but member has none.
  - Line 339: `test_boundary_system_with_zero_access_groups_falls_back_to_all_active_devices()`: asserts fallback to all active devices when `AccessGroup::count() === 0`.
  - Line 354: `test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices()`: asserts deduplication of devices across multiple groups.

- **`tests/Feature/E2E/Tier3CrossFeatureTest.php`**:
  - Lines 150-180: `test_cross_access_control_scopes_personnel_synchronization_to_zone()`
  - Lines 346-366: `test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices()`

- **`tests/Feature/E2E/Tier4RealWorldScenariosTest.php`**:
  - Lines 164-200: `test_scenario_6_multi_building_campus_access_zones_and_diff_synchronization()`

---

## 2. Logic Chain

1. **Schema & Nullability (Observation 1.1 & 1.2)**:
   All test cases (`test_f05`, `test_f06`, `test_f07`, `test_f08`, `test_f09`, `test_f10`, `test_f11`, `Tier2BoundaryTest`, `Tier3CrossFeatureTest`, `Tier4RealWorldScenariosTest`) instantiate `AccessGroup::create()` without passing `organization_id`. Therefore, `access_groups.organization_id` **must be nullable** (`$table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete()`).
2. **Department Creation Trap (Observation 1.1 & 1.2)**:
   In `test_f08`, line 1070 directly executes `Department::create(['name' => 'Engineering', 'code' => 'ENG-01'])` without an `organization_id`. Currently in PostgreSQL, this triggers a `NOT NULL` constraint violation. Therefore, `App\Models\Department` must be augmented with a `boot()` `creating` lifecycle hook that automatically defaults to the first available organization or generates a fallback organization if `organization_id` is empty.
3. **Foreign Keys on Pivots (Observation 1.1 & 1.2)**:
   In `test_f06`, `test_f07`, `test_f08`, attachments use `$group->devices()->attach($device->id)` and `$group->personnel()->attach($personnel->id)`. The pivot tables must use foreign keys to the integer `id` columns of `devices`, `personnel`, and `departments`.
4. **Resolution Logic in AccessControlService (Observation 1.2)**:
   - When `AccessGroup::count() === 0`, return `Device::where('is_active', true)->get()`.
   - When `AccessGroup::count() > 0`:
     - Query active access groups directly assigned to the personnel (`$personnel->accessGroups`).
     - Query active access groups assigned to the personnel's department via linked employee (`$personnel->employee->department->accessGroups`).
     - Extract all attached active devices (`where('is_active', true)`).
     - Deduplicate by `id`: `$devices->unique('id')->values()`.
5. **SyncPersonnelJob Scoping (Observation 1.2)**:
   Currently `SyncPersonnelJob` defaults to `Device::where('is_active', true)->get()`. To pass `test_f10`, `Tier3CrossFeatureTest`, and `Tier4RealWorldScenariosTest`, `SyncPersonnelJob::handle()` must invoke `app(AccessControlService::class)->getAuthorizedDevicesForPersonnel($person)` when no explicit `targetDeviceId` is passed.
6. **API & UI Surfaces (Observation 1.2)**:
   `test_f11` and `test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices` call `POST /api/access-groups/{id}/sync-now`. This endpoint requires route registration in `routes/api.php` and an `AccessGroupController` method. `test_f12` checks the physical existence of `resources/js/components/settings/AccessGroupManager.vue`.

---

## 3. Caveats

1. **Read-Only Explorer Scope**: As an explorer subagent, no project application code or test files were altered during this investigation. All findings and concrete specifications are documented for the implementer agent.
2. **Department Hierarchy Inheritance**: The specification supports direct department matching (`departments.id = employee.department_id`). Ancestor department inheritance (`getAncestors()`) can be supported as an enhancement, but direct department matching is what is explicitly asserted in the tests.
3. **Queue Drivers in Test Environment**: In automated tests, `Queue::fake()` is used, and jobs are asserted with `Queue::assertPushed`. In production, jobs run on the Redis `camera-sync` queue.

---

## 4. Conclusion

Milestone M2 requires 7 concrete deliverables:
1. **Migration**: `database/migrations/2026_10_07_000002_create_access_groups_table.php` defining `access_groups`, `access_group_device`, `access_group_personnel`, and `access_group_department`.
2. **Department Lifecycle Hook**: In `app/Models/Department.php`, add `static::creating` fallback for `organization_id` to prevent PostgreSQL not-null failures during `test_f08`.
3. **Domain Model & Relations**: `app/Models/AccessGroup.php`, plus inverse relationships in `app/Models/Device.php` (`accessGroups()`), `app/Models/Personnel.php` (`accessGroups()`), `app/Models/Department.php` (`accessGroups()`), and `app/Models/Organization.php` (`accessGroups()`).
4. **Model Factory**: `database/factories/AccessGroupFactory.php` with `active()`, `inactive()`, and `withOrganization()` states.
5. **Domain Service**: `app/Services/AccessControlService.php` with `getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection` handling direct membership, departmental membership, deduplication, and the 0-groups fallback.
6. **Job Dispatch Refactor**: Update `app/Jobs/SyncPersonnelJob.php` to resolve target devices via `AccessControlService`.
7. **API Route, Controller & UI**:
   - `app/Http/Controllers/AccessGroupController.php` (CRUD + `syncNow`).
   - Routes in `routes/api.php` (`/api/access-groups`, `/api/access-groups/{accessGroup}/sync-now`).
   - `resources/js/components/settings/AccessGroupManager.vue` and integration into `SettingsHub.vue`.

---

## 5. Verification Method

Once implemented, run the following verification commands:

```bash
# 1. Run isolated Milestone 2 Tier 1 tests (Features 5 through 12)
php artisan test --filter="test_f0[5-9]|test_f1[0-2]"

# 2. Run Tier 2 boundary cases for access groups
php artisan test --filter=test_boundary_access_group

# 3. Run Tier 3 cross-feature access control tests
php artisan test --filter=test_cross_access_control

# 4. Run Tier 4 multi-zone campus scenario
php artisan test --filter=test_scenario_6_multi_building_campus_access_zones_and_diff_synchronization

# 5. Full regression check
php artisan test --filter=Tier1FeatureCoverageTest
```

### Invalidation Conditions
- If `access_groups.organization_id` is NOT NULL, `test_f05` will fail.
- If `Department::boot()` does not provide a default `organization_id`, `test_f08` will fail with PostgreSQL error 23502.
- If `AccessControlService` does not fallback to active devices when `AccessGroup::count() === 0`, `Tier2BoundaryTest` will fail.
- If `SyncPersonnelJob` is not updated to call `AccessControlService`, `test_f10`, `Tier3CrossFeatureTest`, and `Tier4RealWorldScenariosTest` will fail.
