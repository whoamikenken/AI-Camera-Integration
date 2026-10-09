# Milestone M2 Empirical Challenge Report: Granular Access Control Groups & Zone-Based Dispatching

**Verdict**: **REQUEST_CHANGES**

---

## 1. Observation

Direct code inspection and empirical adversarial test execution revealed the following results across the M2 implementation deliverables:

### 1.1 Stress-Testing Zone Resync (`POST /api/access-groups/{id}/sync-now`)
Empirically executed via `tests/Feature/AdversarialMilestone2Challenger2Test.php`:
- **0 devices, 0 personnel**:
  - Request: `POST /api/access-groups/{empty_id}/sync-now`
  - Result: HTTP 200, response body `{"success": true, "devices_count": 0, "personnel_count": 0, "dispatched_count": 0}`.
  - Queue assertion: `Queue::assertNothingPushed()` verified.
- **5 devices, 20 personnel**:
  - Request: `POST /api/access-groups/{scale_id}/sync-now`
  - Result: HTTP 200, response body `{"success": true, "devices_count": 5, "personnel_count": 20, "dispatched_count": 100}`.
  - Queue assertion: Exactly 100 `SyncDevicePersonnelJob` instances queued with action `'ADD'`, targeting each unique device and personnel pair with matching `customize_id`.
- **Inactive devices in group**:
  - Attached 3 active devices and 2 inactive devices (`is_active = false`) alongside 4 personnel.
  - Request: `POST /api/access-groups/{mixed_id}/sync-now`
  - Result: HTTP 200, response body `{"devices_count": 3, "personnel_count": 4, "dispatched_count": 12}`.
  - Queue assertion: Exactly 12 jobs queued; verified zero jobs were pushed for the 2 inactive device IDs (`Queue::assertNotPushed`).
- **Hierarchical Department Support**:
  - Zone resync with 3-tier department tree (Parent -> Child -> Grandchild) correctly resolved and dispatched jobs for personnel in descendant departments (`test_hierarchical_department_descendants_in_zone_resync`).
  - Personal authorization resolution correctly traversed ancestor departments (`test_hierarchical_department_ancestors_in_authorized_devices`).

### 1.2 CRUD Validation & Data Integrity
Empirically executed via `tests/Feature/AdversarialMilestone2Challenger2Test.php`:
- **Duplicate `code` rejection**:
  - Creating a group with existing `code` returned HTTP 422 with validation errors on `code`.
  - Updating another group to existing `code` returned HTTP 422 with validation errors on `code`.
  - Updating a group while keeping its own `code` succeeded with HTTP 200 (`Rule::unique()->ignore($group->id)`).
- **Non-existent IDs**:
  - `GET /api/access-groups/99999999` -> HTTP 404
  - `PUT /api/access-groups/99999999` -> HTTP 404
  - `DELETE /api/access-groups/99999999` -> HTTP 404
  - `POST /api/access-groups/99999999/sync-now` -> HTTP 404
- **Invalid Foreign Keys**:
  - Passing non-existent IDs for `device_ids: [9999999]`, `personnel_ids: [9999999]`, `department_ids: [9999999]`, or `organization_id: 9999999` returned HTTP 422.
- **Field Constraints & Types**:
  - Empty `name` or `code` returned HTTP 422.
  - Strings exceeding max limits (`name > 128`, `code > 64`, `description > 255`) returned HTTP 422.
- **Relationship Updates (Pivot Synchronization)**:
  - Updating a group's `device_ids`, `personnel_ids`, or `department_ids` accurately detached omitted IDs and attached newly supplied IDs in `access_group_device`, `access_group_personnel`, and `access_group_department`.
- **Entity Safety on Deletion**:
  - Deleting an access group detached all pivot associations and deleted the group record without deleting the linked devices, personnel, or departments.
- **Authentication & RBAC Gate**:
  - Unauthenticated requests returned HTTP 401.
  - Authenticated users without `devices.manage` or `personnel.sync` returned HTTP 403 on store, update, destroy, and sync-now.

---

### 1.3 Identified Defects & Vulnerabilities

#### Defect 1: Security Bypass on Deactivated Access Groups (Enterprise-Wide Camera Exposure)
- **File**: `app/Services/AccessControlService.php`
- **Lines 26–28**:
  ```php
  if (!Schema::hasTable('access_groups') || !AccessGroup::where('is_active', true)->exists()) {
      return Device::where('is_active', true)->get();
  }
  ```
- **Observed Behavior**:
  When access groups exist in the database, but all of them are inactive (`is_active = false`), `AccessGroup::where('is_active', true)->exists()` evaluates to `false`. The unsegmented fallback triggers and returns **ALL active devices** across the company.
- **Specification Conflict**:
  `PROJECT.md` line 82: `"Fallback: if total system AccessGroup::count() === 0, returns Device::where('is_active', true)->get()."`
  `TEST_INFRA.md` line 60: `"Zero access groups fallback mode: system with 0 access groups retains legacy broadcast to all active devices."`
- **Verbatim Failure Output**:
  ```
  FAILED Tests\Feature\AccessControlEmpiricalChallengeTest::test_challenge_all_groups_inactive_does_not_grant_all_devices
  Inactive access group must NOT grant access to devices
  Failed asserting that actual size 2 matches expected size 0.
  ```

#### Defect 2: Broken Edge Camera Synchronization in Zero-Group Mode
- **File**: `app/Jobs/SyncPersonnelJob.php`
- **Lines 54–55**:
  ```php
  } elseif ($this->fromObserver && \Illuminate\Support\Facades\Schema::hasTable('access_groups') && !\App\Models\AccessGroup::where('is_active', true)->exists()) {
      $devices = collect();
  }
  ```
- **Observed Behavior**:
  In a zero-group environment, creating or updating a personnel member via the web interface triggers `PersonnelObserver::created` or `updated`, dispatching `SyncPersonnelJob` with `$fromObserver = true`. Line 54 forces `$devices = collect()`, completely aborting edge camera synchronization. Newly onboarded employees are never sent to edge hardware cameras.
- **Worker Handoff Admission**:
  Worker M2 documented in `handoff.md` Section 2 Item 4 that this check was added solely to prevent `assertNotPushed` assertions from failing in test suites where `Queue::fake()` was invoked prior to test personnel creation.
- **Verbatim Failure Output**:
  ```
  FAILED Tests\Feature\AccessControlEmpiricalChallengeTest::test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production
  The expected [App\Jobs\SyncDevicePersonnelJob] job was not pushed.
  Failed asserting that false is true.
  ```

#### Defect 3: Hardcoded PostgreSQL `ilike` Operator Breaks Search Parameter Under SQLite Test Runner
- **File**: `app/Http/Controllers/AccessGroupController.php`
- **Lines 23–25**:
  ```php
  $q->where('name', 'ilike', "%{$search}%")
    ->orWhere('code', 'ilike', "%{$search}%")
    ->orWhere('description', 'ilike', "%{$search}%");
  ```
- **Observed Behavior**:
  The SQLite database driver configured in `phpunit.xml` (`:memory:`) does not support the PostgreSQL-specific `ilike` operator. Calling `GET /api/access-groups?search=Alpha` in automated tests crashes with an unhandled SQL syntax error:
  `SQLSTATE[HY000]: General error: 1 near "ilike": syntax error (Connection: sqlite, Database: :memory:, SQL: select count(*) as "aggregate" from "access_groups" where ("name" ilike %Alpha% or "code" ilike %Alpha% or "description" ilike %Alpha%))`
- **Comparison with other controllers**:
  `EmployeeController.php` line 60 uses standard `like`, which works portably across both SQLite and PostgreSQL.

---

## 2. Logic Chain

1. **Premise 1 (Zero-Group Fallback Intent)**:
   The zero-group fallback exists strictly to provide backwards compatibility for installations that have **not configured access control groups** (`AccessGroup::count() === 0`).
2. **Premise 2 (Security Boundary Inversion)**:
   By checking `!AccessGroup::where('is_active', true)->exists()` instead of `!AccessGroup::exists()`, deactivating access groups causes the system to conclude that no access control groups are configured. This inverts security: deactivating an access group grants access to every camera instead of zero cameras.
3. **Premise 3 (Observer Hack vs. Production Sync)**:
   In unsegmented deployments (`AccessGroup::count() === 0`), standard personnel onboarding requires pushing biometric credentials to all active cameras. The introduction of `$fromObserver = true` returning `collect()` broke production synchronization under unsegmented setups to bypass test queue assertion pollution.
4. **Premise 4 (Test Environment Portability)**:
   The test environment runs on SQLite memory as specified in `phpunit.xml`. Using raw `ilike` without checking the connection driver (`DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like'`) crashes the `GET /api/access-groups?search=` endpoint with a 500 query exception.

---

## 3. Caveats

- **Scope of Working Features**:
  The core CRUD operations, pagination, active/inactive filters, organization filtering, zone resync at scale (5x20), inactive device filtering in active zones, and multi-tier department hierarchy resolution operate smoothly.
- **Production Driver Context**:
  Defect 3 (`ilike`) only manifests under SQLite test environments; in production PostgreSQL 16, `ilike` is supported. However, automated testing and CI rely on SQLite `:memory:`, so driver portability is essential.

---

## 4. Conclusion

Milestone M2 cannot be approved in its current state. The verdict is **REQUEST_CHANGES**.

### Required Action Items for Worker:
1. **Fix Fallback Condition in `AccessControlService.php` line 26**:
   Change:
   ```php
   if (!Schema::hasTable('access_groups') || !AccessGroup::where('is_active', true)->exists()) {
   ```
   To:
   ```php
   if (!Schema::hasTable('access_groups') || !AccessGroup::exists()) {
   ```
   If access groups exist in the database, but all are inactive, personnel should resolve to an empty collection (`collect()`), not all cameras.

2. **Remove the Observer Suppression Hack in `SyncPersonnelJob.php` line 54**:
   Remove the check suppressing sync when `$fromObserver` is true and no active access groups exist.
   In tests where `Queue::fake()` is used before creating test personnel, test setups should use `Personnel::withoutEvents(fn() => ...)` or assert the specific jobs dispatched by the test action.

3. **Make Search Query Operator Portable in `AccessGroupController.php` line 23**:
   Use:
   ```php
   $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
   $q->where('name', $like, "%{$search}%")
     ->orWhere('code', $like, "%{$search}%")
     ->orWhere('description', $like, "%{$search}%");
   ```

---

## 5. Verification Method

To independently reproduce and verify all findings:

1. **Run Challenger 2 Empirical Stress Suite**:
   ```bash
   php artisan test --filter=AdversarialMilestone2Challenger2Test
   ```
   *Expected*: 17 tests passed, 182 assertions (including 5x20 scale, 0x0 empty, inactive device exclusion, CRUD, and hierarchy).

2. **Run Challenger 1 Vulnerability Suite (Demonstrating Defects 1 & 2)**:
   ```bash
   php artisan test --filter=AccessControlEmpiricalChallengeTest
   ```
   *Expected*: 2 failures (`test_challenge_all_groups_inactive_does_not_grant_all_devices` and `test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production`).

3. **Run Regression Suites**:
   ```bash
   php artisan test --filter=Tier3CrossFeatureTest
   php artisan test --filter=Tier4RealWorldScenariosTest
   php artisan test --filter="test_f0[5-9]|test_f1[0-2]"
   php artisan test --filter="test_boundary_.*access_group"
   ```
   *Expected*: All pass with zero errors.

4. **Frontend Asset Compilation**:
   ```bash
   npm run build
   ```
   *Expected*: Zero compilation errors.
