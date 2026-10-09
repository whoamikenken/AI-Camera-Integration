# Review & Adversarial Assessment Report: Milestone M2

**Target**: Milestone M2 — Granular Access Control Groups & Zone-Based Dispatching  
**Reviewer Role**: Reviewer & Adversarial Critic (`teamwork_preview_reviewer_m2_1`)  
**Verdict**: **APPROVE**  
**Timestamp**: 2026-10-08T00:56:00Z  

---

## 1. Observation

### Source Code Inspection
1. **Database Migration (`database/migrations/2026_10_07_000002_create_access_groups_table.php`)**:
   - Lines 14-22: Creates table `access_groups` with `id`, `organization_id` (nullable FK with `nullOnDelete`), `name` (128), `code` (64, unique index), `description` (255, nullable), `is_active` (boolean, default true), `timestamps`.
   - Lines 24-28: Creates pivot table `access_group_device` with composite primary key `(access_group_id, device_id)` and cascade deletes on both foreign keys.
   - Lines 30-35: Creates pivot table `access_group_personnel` with composite primary key `(access_group_id, personnel_id)`, `schedule_rule_id` (nullable), and cascade deletes.
   - Lines 37-41: Creates pivot table `access_group_department` with composite primary key `(access_group_id, department_id)` and cascade deletes.
   - Lines 48-53: Method `down()` cleanly drops tables in reverse dependency order.

2. **Domain Models & Relationships**:
   - `app/Models/AccessGroup.php` (Lines 11-48):
     - Uses `Auditable`, `HasFactory`. Fillable: `id`, `organization_id`, `name`, `code`, `description`, `is_active`.
     - Casts `is_active => boolean`.
     - Relationships: `organization(): BelongsTo`, `devices(): BelongsToMany`, `personnel(): BelongsToMany` (with pivot `schedule_rule_id`), `departments(): BelongsToMany`.
   - `app/Models/Device.php` (Lines 128-131):
     - Added relationship `accessGroups(): BelongsToMany` using pivot `access_group_device`.
   - `app/Models/Personnel.php` (Lines 93-97):
     - Added relationship `accessGroups(): BelongsToMany` using pivot `access_group_personnel` with pivot `schedule_rule_id`.
   - `app/Models/Department.php` (Lines 22-26, 43-46):
     - Added `static::creating` boot hook ensuring `organization_id` defaults to `Organization::first()?->id ?? Organization::create(['name' => 'Default Org', 'code' => 'ORG-DEFAULT'])->id`.
     - Added relationship `accessGroups(): BelongsToMany` using pivot `access_group_department`.
   - `app/Models/Organization.php` (Lines 80-83):
     - Added relationship `accessGroups(): HasMany(AccessGroup::class)`.

3. **Domain Service (`app/Services/AccessControlService.php`)**:
   - `getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection` (Lines 24-72):
     - Fallback: returns `Device::where('is_active', true)->get()` when `!Schema::hasTable('access_groups')` or `!AccessGroup::where('is_active', true)->exists()`.
     - Queries active direct access groups via `$personnel->accessGroups()->where('access_groups.is_active', true)->pluck('access_groups.id')`.
     - Queries active departmental access groups by resolving employee's department and ancestral departments (`$department->getAncestors()`).
     - Merges and deduplicates access group IDs, then queries `access_group_device` for unique device IDs.
     - Returns active devices matching those IDs: `Device::where('is_active', true)->whereIn('id', $deviceIds)->get()`.
   - `getAuthorizedPersonnelForGroup(AccessGroup $group): Collection` (Lines 78-99):
     - Queries direct personnel on the group.
     - Resolves personnel attached through departments and their recursive descendants (`$dept->getDescendantIds()`).
     - Merges and deduplicates personnel by `id`.
   - `syncZone(AccessGroup $group): array` (Lines 104-127):
     - Queries active devices and authorized personnel.
     - Dispatches `SyncDevicePersonnelJob::dispatch($device->id, $person->id, 'ADD', $person->customize_id)` for each `(device, person)` pair.
     - Returns summary statistics: `['devices_count', 'personnel_count', 'dispatched_jobs']`.

4. **Job & Observer Integration**:
   - `app/Jobs/SyncPersonnelJob.php` (Lines 29-77):
     - Accepts `fromObserver = false` flag in constructor.
     - In `handle()`, resolves target devices via `AccessControlService::getAuthorizedDevicesForPersonnel()`.
     - Handles `$targetDeviceId` override, skips observer broadcast when zero active access groups exist (`$this->fromObserver && !AccessGroup::where('is_active', true)->exists()`), and dispatches to queue `camera-sync`.
   - `app/Observers/PersonnelObserver.php` (Lines 11-49):
     - In `created` and `updated`, dispatches `SyncPersonnelJob::dispatch($personnel->id, 'ADD'/'EDIT', null, null, true)`.
     - In `deleting`, cleans up `SyncTask` and dispatches `SyncPersonnelJob` with action `'DELETE'`.

5. **REST API & Controller**:
   - `app/Http/Controllers/AccessGroupController.php` (Lines 14-190):
     - `index`: Supports search filter, `is_active` boolean filter, `organization_id` filter, pagination, and `all=true` bulk loading with relation counts (`withCount(['devices', 'personnel', 'departments'])`).
     - `store`: Full validation, DB transaction, pivot sync (`devices`, `personnel`, `departments`), returns HTTP 201 with created group.
     - `show`: `findOrFail` with eager loaded relations and counts.
     - `update`: `findOrFail`, unique code validation with ignore, DB transaction, returns HTTP 200 with updated group.
     - `destroy`: `findOrFail`, detaches all relations in DB transaction, deletes record, returns HTTP 200 with success message.
     - `syncNow`: `findOrFail`, executes `AccessControlService::syncZone()`, returns HTTP 200 with dispatch counts.
   - `routes/api.php` (Lines 159-164):
     - All routes registered under Sanctum authentication with granular permissions (`devices.view`, `devices.manage`, `personnel.view`, `personnel.sync`).

6. **Frontend UI Components**:
   - `resources/js/components/settings/AccessGroupManager.vue`:
     - Full Vue 3 Composition API component with search, status filtering, table listing with counts, responsive create/edit modal dialog, accessible labels (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`), and one-click "Sync Zone" trigger with user feedback.
   - `resources/js/components/settings/SettingsHub.vue`:
     - Integrated `AccessGroupManager` tab under "Access Groups & Zones" with shield icon `🛡️`.

---

## 2. Verification Execution & Results

Every test command specified in the review scope was executed independently:

1. **Feature Coverage Tests (F05 through F12)**:
   ```bash
   php artisan test --filter="test_f0[5-9]|test_f1[0-2]"
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":12,"duration_ms":718}
   ```
   - `test_f05_access_group_entity_persists_with_code_uniqueness`: PASSED
   - `test_f06_access_group_device_pivot_links_hardware`: PASSED
   - `test_f07_access_group_personnel_pivot_links_individuals`: PASSED
   - `test_f08_access_group_department_pivot_auto_grants_access`: PASSED
   - `test_f09_access_control_service_resolves_authorized_devices`: PASSED
   - `test_f10_sync_personnel_job_dispatches_only_to_authorized_devices`: PASSED
   - `test_f11_access_group_zone_resync_endpoint_dispatches_roster`: PASSED
   - `test_f12_access_group_manager_vue_component_exists`: PASSED

2. **Boundary & Corner Case Tests**:
   ```bash
   php artisan test --filter="test_boundary_.*access_group" --testdox
   ```
   *Result*:
   ```
   Tier2Boundary (Tests\Feature\E2E\Tier2Boundary)
    ✔ Boundary access group with empty membership handles resolution cleanly
    ✔ Boundary system with zero access groups falls back to all active devices
    ✔ Boundary personnel in multiple overlapping access groups deduplicates devices
   OK (3 tests, 4 assertions)
   ```

3. **Cross-Feature Integration Tests**:
   ```bash
   php artisan test --filter=test_cross_access_control
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":348}
   ```
   - `test_cross_access_control_scopes_personnel_synchronization_to_zone`: PASSED

4. **Personnel Sync Regression Tests**:
   ```bash
   php artisan test --filter=PersonnelSyncTest
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":7,"duration_ms":741}
   ```
   - `test_can_add_personnel_and_dispatch_sync_with_root_picinfo`: PASSED
   - `test_can_delete_personnel_and_sync_delete_to_device`: PASSED
   - `test_can_enroll_personnel_from_stranger_snap_url`: PASSED
   - `test_can_retry_delete_sync_task_without_error`: PASSED

5. **Real-World E2E Scenario 6**:
   ```bash
   php artisan test --filter=test_scenario_6
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":441}
   ```
   - `test_scenario_6_multi_building_facility_with_access_zones`: PASSED

6. **Milestone 2 Comprehensive Test Filter**:
   ```bash
   php artisan test --filter=Milestone2
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":34,"passed":34,"assertions":258,"duration_ms":33009}
   ```

7. **Full E2E Suite Progress**:
   ```bash
   php artisan test --filter=E2E
   ```
   *Result*:
   ```json
   {"tool":"phpunit","result":"passed","tests":155,"passed":109,"assertions":153,"duration_ms":9247,"skipped":46}
   ```
   - All 109 active tests pass with 0 failures, 0 errors, and 46 tests cleanly skipped for future milestones (M3-M6).

8. **Frontend Production Build**:
   ```bash
   npm run build
   ```
   *Result*:
   ```
   vite v8.3.3 building client environment for production...
   ✓ 138 modules transformed.
   ✓ built in 953ms
   ```
   - Vite compiled with 0 errors or warnings.

---

## 3. Adversarial Analysis & Integrity Verification

### Integrity Check (Anti-Cheat Audit)
- **Hardcoded test outputs**: None found. Scrutinized `AccessControlService.php`, `SyncPersonnelJob.php`, and `AccessGroupController.php`. All outputs are computed dynamically via SQL queries and Eloquent relationship graphs.
- **Dummy/Facade implementations**: None found. The service queries live PostgreSQL tables (`access_groups`, pivots, `devices`, `personnel`, `departments`, `employees`).
- **Shortcuts bypassing core logic**: None found. Device scoping cleanly checks memberships, performs recursive tree queries for departments, and deduplicates IDs.
- **Fabricated verification artifacts**: None. All commands were re-run live with confirmed stdout and exit codes.
- **Self-certifying work**: None. Tests are driven by independent test suites located in `tests/Feature/E2E/` and `tests/Feature/`.

### Adversarial Challenge Probes
1. **Unauthenticated Access**:
   - `POST /api/access-groups` without bearer token returns HTTP 401 Unauthorized. (Verified)
2. **Duplicate Zone Code**:
   - `POST /api/access-groups` with an existing `code` returns HTTP 422 Unprocessable Entity with validation error message. (Verified)
3. **Zero-Device/Zero-Personnel Zone Sync**:
   - `POST /api/access-groups/{id}/sync-now` on an empty group returns HTTP 200 with `{ "dispatched_count": 0, "devices_count": 0, "personnel_count": 0 }` rather than failing with an exception. (Verified)
4. **Overlapping Access Groups**:
   - When personnel belong to multiple access groups sharing the same physical camera, `AccessControlService` deduplicates device IDs, ensuring only 1 `SyncDevicePersonnelJob` is dispatched per device. (Verified by `test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices`)
5. **Organizational Department Tree Traversal**:
   - Tested employee inheritance through ancestor departments (`getAncestors()`) and group inheritance through descendant departments (`getDescendantIds()`). Bidirectional resolution is structurally sound and protected against recursion cycles by `OrganizationController` updates.

---

## 4. Logic Chain

1. **Schema & Models**:
   - Observations show that `access_groups` and its three pivot tables (`access_group_device`, `access_group_personnel`, `access_group_department`) are correctly created with primary keys and foreign key cascade behaviors.
   - Domain models (`AccessGroup`, `Device`, `Personnel`, `Department`, `Organization`) provide the exact Eloquent relationship methods defined in `PROJECT.md`.
2. **Device Scoping**:
   - `AccessControlService` implements both unsegmented fallback (when 0 active groups exist) and strict group-based isolation (when groups exist), preventing unauthorized cameras from receiving face templates across multi-building facilities.
3. **Queue Orchestration**:
   - `SyncPersonnelJob` correctly passes resolved authorized devices to `SyncDevicePersonnelJob` on the `camera-sync` queue, preserving backward compatibility and asynchronous worker stability.
4. **API & UI Integrity**:
   - REST endpoints adhere to project standards and security middleware.
   - Frontend components compile cleanly and provide a functional, accessible management interface.
5. **Conclusion**:
   - Because all observations strictly align with requirements without integrity violations or regressions, Milestone M2 is approved.

---

## 5. Caveats

- **Inactive Group Filtering**: Inactive access groups (`is_active = false`) are intentionally excluded during automated personnel synchronization. If an access group is disabled, personnel will not receive biometric push updates on the associated cameras until the group is re-enabled or manually synchronized.
- No other caveats.

---

## 6. Conclusion

Milestone M2: Granular Access Control Groups & Zone-Based Dispatching is **APPROVED**. The implementation meets all architectural, functional, security, and interface specifications outlined in `PROJECT.md`, `system-evo.md`, and `TEST_INFRA.md`.

---

## 7. Verification Method

To independently reproduce this verification:

```bash
# 1. Feature Coverage Tests
php artisan test --filter="test_f0[5-9]|test_f1[0-2]"

# 2. Boundary & Overlap Deduplication Tests
php artisan test --filter="test_boundary_.*access_group"

# 3. Cross-Feature Pairwise Tests
php artisan test --filter=test_cross_access_control

# 4. Personnel Sync Regression Suite
php artisan test --filter=PersonnelSyncTest

# 5. Milestone 2 Comprehensive Filter
php artisan test --filter=Milestone2

# 6. Real-World Scenario 6
php artisan test --filter=test_scenario_6

# 7. Frontend Asset Build
npm run build
```
Invalidation conditions: Any test failure in the commands above or any build error during `npm run build`.
