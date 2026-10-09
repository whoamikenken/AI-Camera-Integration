# Milestone M2 Empirical Challenge Report: Granular Access Control Groups & Zone-Based Dispatching

**Verdict**: **REQUEST_CHANGES**

---

## 1. Observation

Direct code examination and empirical test execution revealed two critical defects:

### Defect 1: Inactive Access Group Bypass Grants Enterprise-Wide Camera Access
- **File**: `app/Services/AccessControlService.php`
- **Lines 26–28**:
  ```php
  if (!Schema::hasTable('access_groups') || !AccessGroup::where('is_active', true)->exists()) {
      return Device::where('is_active', true)->get();
  }
  ```
- **Observed Behavior**:
  When one or more `AccessGroup` records exist in the database, but all of them are set to `is_active = false` (e.g. facility lockdown, deactivated zone), `AccessGroup::where('is_active', true)->exists()` evaluates to `false`. Consequently, `!AccessGroup::where('is_active', true)->exists()` evaluates to `true`, and the system activates the unsegmented fallback: returning **ALL active devices** across the enterprise to any personnel member.
- **Specification Conflict**:
  - `PROJECT.md` Line 82: `"Fallback: if total system AccessGroup::count() === 0, returns Device::where('is_active', true)->get()."`
  - `TEST_INFRA.md` Line 60: `"Zero access groups fallback mode: system with 0 access groups retains legacy broadcast to all active devices."`
- **Verbatim Empirical Failure**:
  Executed `php artisan test --filter="test_challenge_all_groups_inactive_does_not_grant_all_devices"`
  ```
  FAILED Tests\Feature\AccessControlEmpiricalChallengeTest::test_challenge_all_groups_inactive_does_not_grant_all_devices
  Inactive access group must NOT grant access to devices
  Failed asserting that actual size 2 matches expected size 0.
  ```

### Defect 2: Silent Suppression of Biometric Edge Sync in Zero-Group (Unsegmented) Mode
- **File**: `app/Jobs/SyncPersonnelJob.php`
- **Lines 54–55**:
  ```php
  } elseif ($this->fromObserver && \Illuminate\Support\Facades\Schema::hasTable('access_groups') && !\App\Models\AccessGroup::where('is_active', true)->exists()) {
      $devices = collect();
  }
  ```
- **Observed Behavior**:
  When an organization operates in legacy unsegmented mode (zero access groups configured in the system), creating or editing any personnel member through Eloquent (`Personnel::create()`, `POST /api/personnel`, `PUT /api/personnel/{id}`) fires `PersonnelObserver::created` or `updated`.
  `PersonnelObserver` calls `SyncPersonnelJob::dispatch($personnel->id, 'ADD', null, null, true)`.
  Because `$this->fromObserver` is `true` and no active access groups exist, line 54 forces `$devices = collect()`.
  The job logs `"SyncPersonnelJob: No active devices found for personnel ..."` and exits immediately without dispatching `SyncDevicePersonnelJob` to any active camera.
- **Worker Handoff Admission**:
  In `.agents/teamwork/teamwork_preview_worker_m2/handoff.md` Section 2 Item 4:
  `"By adding $fromObserver = true when dispatched by PersonnelObserver, SyncPersonnelJob::handle suppresses broadcasting to all cameras when no access groups are configured."`
  The worker introduced this conditional to prevent test queue contamination in `test_f10` and `test_cross_access_control`, but in doing so completely broke production edge synchronization for any deployment with zero access groups.
- **Verbatim Empirical Failure**:
  Executed `php artisan test --filter="test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production"`
  ```
  FAILED Tests\Feature\AccessControlEmpiricalChallengeTest::test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production
  The expected [App\Jobs\SyncDevicePersonnelJob] job was not pushed.
  Failed asserting that false is true.
  ```

---

## 2. Logic Chain

1. **Security Inversion on Deactivated Groups**:
   - The contract in `PROJECT.md` specifies that the fallback to all active devices applies *strictly* when `AccessGroup::count() === 0` (unsegmented legacy setup where the feature has not been initialized).
   - In `AccessControlService::getAuthorizedDevicesForPersonnel()`, the check was implemented as `!AccessGroup::where('is_active', true)->exists()`.
   - When an administrator configures access groups (e.g. `Zone A`) and sets `is_active = false` to suspend access, `AccessGroup::where('is_active', true)->exists()` returns `false`.
   - The method interprets this as "zero access groups exist" and returns `Device::where('is_active', true)->get()`.
   - Every personnel member in the company immediately receives credentials for every active camera. Disabling an access group thus expands access from 0 cameras to all cameras.

2. **Broken Legacy Biometric Synchronization**:
   - In an installation with 0 access groups, legacy behavior dictates that any new employee or photo update broadcasts to all active cameras.
   - However, in production, personnel are created via `Personnel::create()` which triggers `PersonnelObserver::created()`.
   - `PersonnelObserver` dispatches `SyncPersonnelJob` with `$fromObserver = true`.
   - `SyncPersonnelJob::handle()` intercepts `$fromObserver && !AccessGroup::where('is_active', true)->exists()` and returns an empty collection (`$devices = collect()`).
   - Consequently, zero `SyncDevicePersonnelJob` instances are dispatched. Cameras never receive biometric templates for newly onboarded staff.

3. **Well-Functioning Subsystems**:
   - Overlapping access groups device deduplication functions correctly: shared devices appear exactly once (16 assertions passed in `AccessControlEmpiricalChallengeTest`).
   - Multi-tier recursive department inheritance works as expected across 3 hierarchical levels (Grandchild -> Child -> Parent), cascading down without leaking upwards to parent departments or sideways to sibling departments.
   - Inactive devices (`is_active = false`) are properly excluded from active access groups and `syncZone` job dispatches.
   - Frontend components (`AccessGroupManager.vue`) compile cleanly with zero errors.

---

## 3. Caveats

- **Test Harness Queue Faking**:
  In `Tier1FeatureCoverageTest::test_f10` and `Tier3CrossFeatureTest::test_cross_access_control`, `Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class])` is invoked before calling `$this->createTestPersonnel()`. If the worker simply removes line 54 of `SyncPersonnelJob`, those two existing tests may fail assertions like `assertNotPushed` because the observer will have pushed jobs during setup.
  The worker must resolve this cleanly (e.g., using `Personnel::withoutEvents(fn() => $this->createTestPersonnel())` in test setup or managing job assertions specifically for the dispatched job instance) without crippling production observer behavior.
- **Deactivated Zone Resync**:
  `AccessGroupController::syncNow` currently allows triggering sync on groups where `is_active = false`. While not explicitly barred by `PROJECT.md`, syncing inactive groups to edge hardware is semantically inconsistent.

---

## 4. Conclusion

Milestone M2 cannot be approved in its current state due to two architectural and security flaws:
1. **Critical Security Flaw**: Inactive access groups trigger the zero-group fallback, opening all enterprise cameras to all personnel.
2. **Critical Functional Flaw**: Personnel creations and updates in zero-group environments silently fail to sync to edge hardware cameras due to observer suppression.

### Required Changes for Worker:
1. **Fix `AccessControlService::getAuthorizedDevicesForPersonnel()`**:
   Change fallback check to strictly verify total absence of access groups:
   ```php
   if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) {
       return Device::where('is_active', true)->get();
   }
   ```
   If `AccessGroup::count() > 0`, personnel without matching active groups must return `collect()`, even if all groups in the system are currently inactive.
2. **Remove Artificial Observer Suppression in `SyncPersonnelJob::handle()`**:
   Remove the `elseif ($this->fromObserver && ...)` clause. When running in a system with 0 access groups, observer-triggered sync jobs must respect the legacy fallback and dispatch to all active devices.
3. **Ensure Test Isolation in E2E Tests**:
   Update `Tier1FeatureCoverageTest::test_f10` and `Tier3CrossFeatureTest::test_cross_access_control` so that test setup uses `Personnel::withoutEvents(...)` or asserts jobs with matching action/payload, preventing observer events during fixture creation from polluting queue assertions.
4. **All 18 tests in `tests/Feature/AccessControlEmpiricalChallengeTest.php` must pass**.

---

## 5. Verification Method

To verify these findings independently, execute:

```bash
# 1. Run the empirical challenger suite (currently fails on the 2 identified defects):
php artisan test tests/Feature/AccessControlEmpiricalChallengeTest.php

# 2. Inspect failure on inactive group security bypass:
php artisan test --filter="test_challenge_all_groups_inactive_does_not_grant_all_devices"

# 3. Inspect failure on zero-group observer sync suppression:
php artisan test --filter="test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production"
```
