# Handoff Report: Milestone M2 Audit Remediation Investigation

**Work Product**: Milestone M2 Forensic Remediation Investigation & Cross-DB Verification Specification  
**Agent**: `teamwork_preview_explorer_m2_remed_3` (Explorer Archetype)  
**Parent**: `d92077ef-c304-46e9-b9e3-76162b255597`  
**Handoff Type**: Hard (Investigation & Remediation Specification Complete)  

---

## 1. Observation

### 1.1 Finding 3: Database Query Portability Defect in `AccessGroupController.php`
- **File**: `app/Http/Controllers/AccessGroupController.php`, lines 20–27:
  ```php
  20:         if ($request->filled('search')) {
  21:             $search = $request->query('search');
  22:             $query->where(function ($q) use ($search) {
  23:                 $q->where('name', 'ilike', "%{$search}%")
  24:                   ->orWhere('code', 'ilike', "%{$search}%")
  25:                   ->orWhere('description', 'ilike', "%{$search}%");
  26:             });
  27:         }
  ```
- **Observed Verbatim Error in `storage/logs/laravel.log`**:
  ```
  SQLSTATE[HY000]: General error: 1 near "ilike": syntax error (Connection: sqlite, Database: :memory:, SQL: select count(*) as "aggregate" from "access_groups" where ("name" ilike %Alpha% or "code" ilike %Alpha% or "description" ilike %Alpha%))
  ```
- **Existing Precedent in Codebase**:
  `app/Http/Controllers/EmployeeController.php` lines 60–64, `app/Http/Controllers/VisitorController.php` line 31, and `app/Http/Controllers/HolidayController.php` line 40 use standard `like` or lower-cased matching, which works across both SQLite and PostgreSQL.

### 1.2 Adversarial Test Assertion Probe in `AdversarialMilestone2Challenger2Test.php`
- **File**: `tests/Feature/AdversarialMilestone2Challenger2Test.php`, lines 635–656:
  ```php
  647:         if (DB::connection()->getDriverName() === 'sqlite') {
  648:             // Under SQLite, hardcoded 'ilike' causes a 500 error / SQLSTATE[HY000] syntax error
  649:             $res = $this->getJson('/api/access-groups?search=Alpha');
  650:             $this->assertEquals(500, $res->status(), 'Hardcoded ilike should fail on SQLite');
  651:         } else {
  652:             $res = $this->getJson('/api/access-groups?search=Alpha');
  653:             $res->assertStatus(200);
  654:             $this->assertCount(1, $res->json('data'));
  655:         }
  ```
- **Direct Tool Execution**:
  Command: `php artisan test --filter="test_index_search_parameter_behavior_under_current_driver"`
  Result: Passed with 1 assertion (asserting HTTP 500 under SQLite).
  Critical consequence: When `AccessGroupController.php` is repaired, this test will FAIL unless line 650 is updated to expect HTTP 200.

### 1.3 Finding 1: Observer Bypass in `SyncPersonnelJob.php`
- **File**: `app/Jobs/SyncPersonnelJob.php`, lines 54–55:
  ```php
  54:         } elseif ($this->fromObserver && \Illuminate\Support\Facades\Schema::hasTable('access_groups') && !\App\Models\AccessGroup::where('is_active', true)->exists()) {
  55:             $devices = collect();
  ```
- **Direct Tool Execution**:
  Command: `php artisan test --filter="test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production"`
  Output: `FAILED ... The expected [App\Jobs\SyncDevicePersonnelJob] job was not pushed.`
  Command: `php artisan test --filter="test_device_audit_returns_unified_user_roster"`
  Output: `FAILED ... Unable to find JSON: synced_count = 1, missing_on_camera_count = 1 within response JSON (actual: synced_count = 0, missing_on_camera_count = 2).`

### 1.4 Finding 2: Inactive Group Security Bypass in `AccessControlService.php`
- **File**: `app/Services/AccessControlService.php`, lines 26–28:
  ```php
  26:         if (!Schema::hasTable('access_groups') || !AccessGroup::where('is_active', true)->exists()) {
  27:             return Device::where('is_active', true)->get();
  28:         }
  ```
- **Direct Tool Execution**:
  Command: `php artisan test --filter="test_challenge_all_groups_inactive_does_not_grant_all_devices"`
  Output: `FAILED ... Inactive access group must NOT grant access to devices. Failed asserting that actual size 2 matches expected size 0.`

### 1.5 Test Setup Race in `Tier1FeatureCoverageTest.php` and `Tier3CrossFeatureTest.php`
- **Files**:
  - `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1112–1133` (`test_f10`)
  - `tests/Feature/E2E/Tier3CrossFeatureTest.php:154–179` (`test_cross_access_control`)
- **Direct Code Inspection**:
  `Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class])` is invoked before `$personnel = $this->createTestPersonnel()`.
  When the `$fromObserver` bypass is removed, `$personnel` creation triggers `PersonnelObserver::created`, which uses zero-group fallback to push jobs for unauthorized devices into the fake queue, contaminating subsequent `assertNotPushed` assertions.

---

## 2. Logic Chain

1. **Root Cause of Finding 3**:
   - `phpunit.xml` configures `DB_CONNECTION=sqlite` (`:memory:`).
   - SQLite does not parse `ilike`, generating `SQLSTATE[HY000]`.
   - Changing the query to `$like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';` (or standard `like`) allows SQLite to execute valid, case-insensitive ASCII LIKE queries while retaining PostgreSQL 16 case-insensitive `ilike` in production.

2. **Challenger 2 Test Synchronization**:
   - Challenger 2 explicitly authored `test_index_search_parameter_behavior_under_current_driver` as an empirical probe asserting HTTP 500 under SQLite.
   - Remediating `AccessGroupController.php` causes SQLite requests to return HTTP 200 with matching results.
   - Without updating `test_index_search_parameter_behavior_under_current_driver` line 650 to assert HTTP 200, the test suite will break on SQLite.

3. **Restoring Zero-Group Production Sync**:
   - Removing lines 54–55 of `SyncPersonnelJob.php` delegates device resolution solely to `AccessControlService::getAuthorizedDevicesForPersonnel($person)`.
   - This directly fixes `test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production` and fixes the `DeviceManagementTest` audit roster regression.

4. **Hardening Inactive Group Security Boundary**:
   - Updating `AccessControlService.php:26` to `!Schema::hasTable('access_groups') || AccessGroup::count() === 0` ensures that when access groups exist but are deactivated, the fallback to all active devices is suppressed.
   - Deactivated groups thus return `collect()` (0 devices), passing `test_challenge_all_groups_inactive_does_not_grant_all_devices`.

5. **Test Isolation in `test_f10` and `test_cross_access_control`**:
   - Wrapping personnel fixture creation in `Personnel::withoutEvents(...)` or declaring `AccessGroup` before `Personnel` prevents observer sync events from polluting `Queue::fake()`.

---

## 3. Caveats

- **PostgreSQL Production vs. SQLite Test Runner**:
  If Option B (plain `like`) is chosen instead of Option A (driver-aware `$like`), string matching in production PostgreSQL will be case-sensitive unless column values or search queries are normalized. Option A is strongly recommended.
- **Other Controllers with `ilike`**:
  `PersonnelController.php` (lines 31–33) and `AccessLogController.php` (lines 33–34) also contain `ilike`. While not currently tested under SQLite with search queries in existing suites, adopting driver-aware search across all controllers is recommended for holistic codebase hygiene.

---

## 4. Conclusion

Milestone M2 can be fully restored to integrity by executing five coordinated remediations:

### Remediation Code Snippets:

#### 1. `app/Jobs/SyncPersonnelJob.php` (Lines 52–60)
**Before**:
```php
if ($this->targetDeviceId) {
    $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
} elseif ($this->fromObserver && \Illuminate\Support\Facades\Schema::hasTable('access_groups') && !\App\Models\AccessGroup::where('is_active', true)->exists()) {
    $devices = collect();
} elseif ($person) {
    $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
} else {
    $devices = Device::where('is_active', true)->get();
}
```
**After**:
```php
if ($this->targetDeviceId) {
    $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
} elseif ($person) {
    $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
} else {
    $devices = Device::where('is_active', true)->get();
}
```

#### 2. `app/Services/AccessControlService.php` (Lines 26–28)
**Before**:
```php
if (!Schema::hasTable('access_groups') || !AccessGroup::where('is_active', true)->exists()) {
    return Device::where('is_active', true)->get();
}
```
**After**:
```php
if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) {
    return Device::where('is_active', true)->get();
}
```

#### 3. `app/Http/Controllers/AccessGroupController.php` (Lines 20–27)
**Before**:
```php
if ($request->filled('search')) {
    $search = $request->query('search');
    $query->where(function ($q) use ($search) {
        $q->where('name', 'ilike', "%{$search}%")
          ->orWhere('code', 'ilike', "%{$search}%")
          ->orWhere('description', 'ilike', "%{$search}%");
    });
}
```
**After**:
```php
if ($request->filled('search')) {
    $search = $request->query('search');
    $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    $query->where(function ($q) use ($search, $like) {
        $q->where('name', $like, "%{$search}%")
          ->orWhere('code', $like, "%{$search}%")
          ->orWhere('description', $like, "%{$search}%");
    });
}
```

#### 4. `tests/Feature/AdversarialMilestone2Challenger2Test.php` (Lines 647–656)
**Before**:
```php
if (DB::connection()->getDriverName() === 'sqlite') {
    // Under SQLite, hardcoded 'ilike' causes a 500 error / SQLSTATE[HY000] syntax error
    $res = $this->getJson('/api/access-groups?search=Alpha');
    $this->assertEquals(500, $res->status(), 'Hardcoded ilike should fail on SQLite');
} else {
    $res = $this->getJson('/api/access-groups?search=Alpha');
    $res->assertStatus(200);
    $this->assertCount(1, $res->json('data'));
}
```
**After**:
```php
$res = $this->getJson('/api/access-groups?search=Alpha');
$res->assertStatus(200);
$this->assertCount(1, $res->json('data'));
$this->assertEquals('Alpha Core Zone', $res->json('data.0.name'));
```

#### 5. `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (Line 1116) & `Tier3CrossFeatureTest.php` (Line 159)
**Before**:
```php
$personnel = $this->createTestPersonnel();
```
**After**:
```php
$personnel = \App\Models\Personnel::withoutEvents(function () {
    return $this->createTestPersonnel();
});
```

---

## 5. Verification Method

To verify all remediations end-to-end, execute the following commands in the workspace:

```bash
# 1. Verify zero-group observer sync in production
php artisan test --filter="test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production"

# 2. Verify inactive group security boundary
php artisan test --filter="test_challenge_all_groups_inactive_does_not_grant_all_devices"

# 3. Verify regression fix in DeviceManagementTest
php artisan test --filter="test_device_audit_returns_unified_user_roster"

# 4. Verify search portability and complete Challenger 2 suite (17 tests)
php artisan test tests/Feature/AdversarialMilestone2Challenger2Test.php

# 5. Verify complete Challenger 1 suite (18 tests)
php artisan test tests/Feature/AccessControlEmpiricalChallengeTest.php

# 6. Verify E2E coverage suites
php artisan test tests/Feature/E2E/Tier1FeatureCoverageTest.php --filter="test_f10|test_f11|test_f12"
php artisan test tests/Feature/E2E/Tier3CrossFeatureTest.php --filter="test_cross_access_control"
php artisan test tests/Feature/E2E/Tier2BoundaryTest.php --filter="test_boundary_.*access_group"
php artisan test tests/Feature/E2E/Tier4RealWorldScenariosTest.php --filter="test_scenario_6_zone_based_biometric_access_dispatching"

# 7. Verify PersonnelSyncTest
php artisan test tests/Feature/PersonnelSyncTest.php

# 8. Verify Frontend build
npm run build
```

**Invalidation Conditions**:
- If `test_challenge_all_groups_inactive_does_not_grant_all_devices` returns > 0 devices, remediation 2 was not applied.
- If `test_device_audit_returns_unified_user_roster` returns `synced_count: 0`, remediation 1 was not applied.
- If `GET /api/access-groups?search=Alpha` returns 500 under SQLite, remediation 3 was not applied.
- If `test_index_search_parameter_behavior_under_current_driver` asserts 500 after fixing `AccessGroupController`, remediation 4 was not applied.
