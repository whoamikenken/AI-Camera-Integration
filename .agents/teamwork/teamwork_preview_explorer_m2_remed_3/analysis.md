# Comprehensive Forensic Remediation Analysis: Milestone M2 Audit Failures

**Investigator**: `teamwork_preview_explorer_m2_remed_3` (Explorer Archetype)  
**Target Milestone**: M2 (Granular Access Control Groups & Zone-Based Dispatching)  
**Date**: 2026-10-08  
**References**:
- Auditor Report: `.agents/teamwork/teamwork_preview_auditor_m2_1/handoff.md`
- Challenger 1 Report: `.agents/teamwork/teamwork_preview_challenger_m2_1/handoff.md`
- Challenger 2 Report: `.agents/teamwork/teamwork_preview_challenger_m2_2/handoff.md`
- Architecture & Dead Ends: `PROJECT.md`, `system-evo.md`, `.agents/teamwork/orchestrator_10/DEAD_ENDS.md`

---

## Executive Summary

The Forensic Integrity Audit by `teamwork_preview_auditor_m2_1` rejected Milestone M2 due to three substantive defects and their cascading side-effects:
1. **Observer Bypass Defect** (`SyncPersonnelJob.php:54-55`): Artificial suppression of personnel creation sync when `$fromObserver === true` and no active groups exist, breaking live edge synchronization in zero-group environments and causing a regression in `DeviceManagementTest::test_device_audit_returns_unified_user_roster`.
2. **Security Fallback Inversion** (`AccessControlService.php:26-28`): Fallback check implemented as `!AccessGroup::where('is_active', true)->exists()` instead of `AccessGroup::count() === 0`, causing enterprise-wide device exposure when all configured access groups are deactivated.
3. **Database Query Portability Defect** (`AccessGroupController.php:23-25`): Hardcoded PostgreSQL-specific `ilike` operator causing fatal `SQLSTATE[HY000]: General error: 1 near "ilike": syntax error` exceptions under SQLite (:memory:) test runners.

This report provides the exhaustive forensic analysis, query formulations, test assertion synchronization requirements, and a consolidated remediation verification harness for all 5 audit points.

---

## 1. Deep Dive: Finding 3 — Database Query Portability Defect

### 1.1 The Anomaly in `AccessGroupController.php`
Lines 20–27 of `app/Http/Controllers/AccessGroupController.php`:
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

### 1.2 Verbatim Runtime Failure & Database Engine Matrix
When `GET /api/access-groups?search=Alpha` is dispatched in an automated test environment (`phpunit.xml` configuring `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), SQLite's SQL parser halts execution:
```
[testing.ERROR] SQLSTATE[HY000]: General error: 1 near "ilike": syntax error 
(Connection: sqlite, Database: :memory:, SQL: select count(*) as "aggregate" from "access_groups" where ("name" ilike %Alpha% or "code" ilike %Alpha% or "description" ilike %Alpha%))
```

#### SQL Engine Comparison:
| SQL Engine | Driver Name | `LIKE` Behavior | Supports `ILIKE` Keyword? | Result of Hardcoded `ILIKE` |
|:---|:---|:---|:---|:---|
| **PostgreSQL 16** (Production) | `pgsql` | Case-sensitive | **YES** (Native keyword) | Executes successfully (Case-insensitive) |
| **SQLite 3** (Automated Tests/CI) | `sqlite` | Case-insensitive for ASCII | **NO** (Grammar syntax error) | Fatal `500 QueryException` |
| **MySQL / MariaDB** | `mysql` | Case-insensitive (default collations) | **NO** (Grammar syntax error) | Fatal `500 QueryException` |

### 1.3 Architectural Inconsistency with Existing Controllers
A survey of existing search filters in the codebase reveals established precedents:
- `EmployeeController.php` lines 60–64 uses standard `like`:
  ```php
  $q->where('first_name', 'like', "%{$search}%")
    ->orWhere('last_name', 'like', "%{$search}%")
    ->orWhere('employee_code', 'like', "%{$search}%")
  ```
- `VisitorController.php` line 31 uses standard `like`:
  ```php
  $q->where('first_name', 'like', "%{$search}%")
  ```
- `HolidayController.php` line 40 uses standard `like`:
  ```php
  $query->where('name', 'like', "%{$search}%");
  ```

---

## 2. Cross-Database Compatible Query Formulations

We formulate and evaluate three distinct query patterns:

### Option A: Driver-Aware Dynamic Operator (Recommended for Enterprise Hybrid)
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
**Rationale**:
- In production PostgreSQL 16: Employs native `ilike`, providing full Unicode case-insensitivity without invalidating expression indexes.
- In testing SQLite: Employs `like`, which SQLite natively treats as case-insensitive for ASCII characters without throwing a grammar error.
- Zero external package dependencies.

### Option B: Universal Portable `LIKE` (Idiomatic Laravel)
```php
if ($request->filled('search')) {
    $search = $request->query('search');
    $query->where(function ($q) use ($search) {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('code', 'like', "%{$search}%")
          ->orWhere('description', 'like', "%{$search}%");
    });
}
```
**Rationale**:
- Completely eliminates driver branching logic.
- Exactly matches `EmployeeController.php`, `VisitorController.php`, and `HolidayController.php`.
- 100% compliant with standard ANSI SQL.

### Option C: Normalized Lowercase Search (`LOWER(?) LIKE LOWER(?)`)
```php
if ($request->filled('search')) {
    $search = mb_strtolower($request->query('search'));
    $query->where(function ($q) use ($search) {
        $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
          ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
    });
}
```
**Trade-offs**: Works universally across all SQL dialects and forces case-insensitivity in PostgreSQL without `ilike`, but bypasses Eloquent parameter abstractions with raw SQL bindings.

**Recommendation**: Adopt **Option A** or **Option B**. Both guarantee 100% test pass on SQLite and production reliability.

---

## 3. Test Assertion Synchronization & Suite Interactions

### 3.1 `AdversarialMilestone2Challenger2Test.php`
- **Location**: Lines 635–656 (`test_index_search_parameter_behavior_under_current_driver`)
- **Current Code**:
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
- **Forensic Finding**:
  Challenger 2 wrote this test specifically to prove the existence of Defect 3 by asserting HTTP 500 under SQLite.
  **When Defect 3 is remedied in `AccessGroupController.php`, this test will immediately fail because `$res->status()` will be 200 instead of 500.**
- **Remediation Requirement**:
  The test assertion must be updated to assert success (HTTP 200) across all database drivers:
  ```php
  $res = $this->getJson('/api/access-groups?search=Alpha');
  $res->assertStatus(200);
  $this->assertCount(1, $res->json('data'));
  $this->assertEquals('Alpha Core Zone', $res->json('data.0.name'));
  ```

### 3.2 `AccessControlEmpiricalChallengeTest.php`
- **Current State**: 18 tests total, 16 passed, 2 failed.
- **Defect 1 Test**: `test_challenge_all_groups_inactive_does_not_grant_all_devices` (lines 119–140):
  - Fails because `AccessControlService.php:26` checked `!AccessGroup::where('is_active', true)->exists()`.
  - When all groups are inactive, this returned all active devices (size 2 instead of 0).
  - Remediation in `AccessControlService.php:26` (`AccessGroup::count() === 0`) resolves this assertion completely.
- **Defect 2 Test**: `test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production` (lines 166–185):
  - Fails because `SyncPersonnelJob.php:54` forced `$devices = collect()` when `$fromObserver === true` in zero-group mode.
  - Removing lines 54–55 allows the unsegmented fallback to dispatch `SyncDevicePersonnelJob` for all active devices, satisfying the `Queue::assertPushed` assertion.

### 3.3 `Tier1FeatureCoverageTest.php` and `Tier3CrossFeatureTest.php`
- **Location**:
  - `Tier1FeatureCoverageTest.php:1107–1134` (`test_f10_sync_personnel_job_dispatches_only_to_authorized_devices`)
  - `Tier3CrossFeatureTest.php:150–180` (`test_cross_access_control_scopes_personnel_synchronization_to_zone`)
- **The Observer Race Condition**:
  Both tests initialize `Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class])` *before* creating the test personnel fixture:
  ```php
  Queue::fake([SyncDevicePersonnelJob::class]);
  $authDevice = $this->createTestDevice(['device_id' => 'CAM-AUTH-01']);
  $unauthDevice = $this->createTestDevice(['device_id' => 'CAM-UNAUTH-02']);
  $personnel = $this->createTestPersonnel(); // <-- FIRES PersonnelObserver::created
  ```
  In an unsegmented environment (`AccessGroup::count() === 0` at this point in the test), `PersonnelObserver::created` dispatches `SyncPersonnelJob`, which falls back to all active devices (`$authDevice` AND `$unauthDevice`), pushing jobs into the fake queue.
  When the test later attaches `$personnel` to an access group containing only `$authDevice` and calls `Queue::assertNotPushed(..., $unauthDevice)`, the assertion fails because `$unauthDevice` was pushed during fixture creation.
- **Why the Worker Added the `$fromObserver` Hack**:
  The original worker added `$fromObserver && !AccessGroup::where('is_active', true)->exists() => collect()` solely to silence this assertion.
- **Clean Remediation for Tests**:
  Rather than mutilating production business logic, the tests must decouple fixture creation from queue assertions using one of two standard patterns:
  1. **Pattern 1 (Event Suppression during Fixture Setup)**:
     ```php
     $personnel = \App\Models\Personnel::withoutEvents(fn() => $this->createTestPersonnel());
     ```
  2. **Pattern 2 (Entity Ordering)**:
     Create the `AccessGroup` *before* creating `$personnel`. When `AccessGroup::count() > 0`, unassigned personnel resolves to 0 devices, preventing unwanted job dispatches into the fake queue during fixture creation.
  3. **Pattern 3 (Scoped Queue Faking)**:
     Call `Queue::fake([SyncDevicePersonnelJob::class])` directly before the explicit action under test (`dispatch(new SyncPersonnelJob(...))`).

### 3.4 `DeviceManagementTest.php`
- **Location**: Lines 136–180 (`test_device_audit_returns_unified_user_roster`)
- **Current Failure**: `Unable to find JSON: synced_count = 1, missing_on_camera_count = 1`
- **Cause**: In an unsegmented setup, `$person1 = Personnel::create(...)` was suppressed by the `$fromObserver` hack. No `SyncTask` was recorded, leaving both users as `missing_on_camera_count = 2`.
- **Resolution**: Removing the hack restores automatic `SyncTask` generation for `$person1`, restoring `synced_count = 1`.

---

## 4. Comprehensive 5-Point Remediation Plan

| # | Target File | Line(s) | Defect | Required Remediation |
|---|---|---|---|---|
| **1** | `app/Jobs/SyncPersonnelJob.php` | 54–55 | Artificial observer bypass suppresses sync | Delete lines 54–55 (`elseif ($this->fromObserver && ...)`). Resolve devices purely via `AccessControlService::getAuthorizedDevicesForPersonnel($person)`. |
| **2** | `app/Services/AccessControlService.php` | 26–28 | Inactive groups trigger company-wide camera access | Change condition to: `if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) { return Device::where('is_active', true)->get(); }`. When groups exist but all are inactive, return `collect()`. |
| **3** | `app/Http/Controllers/AccessGroupController.php` | 20–27 | Hardcoded `ilike` causes SQL syntax error on SQLite | Use driver-aware operator `$like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';` or standard `like`. |
| **4** | `tests/Feature/AdversarialMilestone2Challenger2Test.php` | 647–656 | Test asserts 500 on SQLite to prove bug | Update test 2.10 to assert HTTP 200 and successful result matching across all database drivers. |
| **5** | `tests/Feature/E2E/Tier1FeatureCoverageTest.php`<br>`tests/Feature/E2E/Tier3CrossFeatureTest.php` | 1116<br>159 | Queue fake polluted by fixture creation | Wrap personnel creation in `Personnel::withoutEvents(...)` or instantiate `AccessGroup` prior to personnel fixture setup. |

---

## 5. Consolidated Verification Script & Execution Matrix

A verification script to be run sequentially after remediation:

```bash
#!/usr/bin/env bash
set -e

echo "=== 1. Verifying Zero-Group Fallback in Production Mode ==="
php artisan test --filter="test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production"

echo "=== 2. Verifying Inactive Access Group Security Guard ==="
php artisan test --filter="test_challenge_all_groups_inactive_does_not_grant_all_devices"

echo "=== 3. Verifying Core Device Management Audit Regression ==="
php artisan test --filter="test_device_audit_returns_unified_user_roster"

echo "=== 4. Verifying Challenger 2 Search Portability & Suite ==="
php artisan test tests/Feature/AdversarialMilestone2Challenger2Test.php

echo "=== 5. Verifying Challenger 1 Full Empirical Suite (18 tests) ==="
php artisan test tests/Feature/AccessControlEmpiricalChallengeTest.php

echo "=== 6. Verifying E2E Milestone 2 Coverage Suites ==="
php artisan test tests/Feature/E2E/Tier1FeatureCoverageTest.php --filter="test_f10|test_f11|test_f12"
php artisan test tests/Feature/E2E/Tier3CrossFeatureTest.php --filter="test_cross_access_control"
php artisan test tests/Feature/E2E/Tier2BoundaryTest.php --filter="test_boundary_.*access_group"
php artisan test tests/Feature/E2E/Tier4RealWorldScenariosTest.php --filter="test_scenario_6_zone_based_biometric_access_dispatching"

echo "=== 7. Verifying Personnel Sync Baseline ==="
php artisan test tests/Feature/PersonnelSyncTest.php

echo "=== 8. Verifying Frontend Compilation ==="
npm run build

echo "=== ALL AUDIT REMEDIATION GATES PASSED CLEANLY ==="
```
