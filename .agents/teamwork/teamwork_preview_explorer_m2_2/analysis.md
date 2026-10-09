# Technical Exploration Report: Milestone M2 Access Control Groups & Zone-Based Dispatching

**Focus Area:** `App\Services\AccessControlService`, `App\Jobs\SyncPersonnelJob`, `App\Observers\PersonnelObserver`, and Associated Test Assertions (Tier 1, Tier 2, Tier 3, Tier 4).  
**Explorer:** `teamwork_preview_explorer_m2_2`  
**Date:** 2026-10-07  

---

## Executive Summary

In the current codebase, biometric personnel provisioning (`SyncPersonnelJob`) broadcasts indiscriminately to all active edge cameras via `Device::where('is_active', true)->get()`. Milestone M2 introduces **Access Control Groups and Zone-Based Dispatching**, scoping biometric face enrollment and revocation strictly to authorized hardware zones based on individual personnel memberships (`access_group_personnel`) and departmental assignments (`access_group_department`).

This report provides a comprehensive architectural and code-level exploration of:
1. `App\Services\AccessControlService` and its primary method `getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection`.
2. Refactoring `App\Jobs\SyncPersonnelJob` to consume `AccessControlService` while preserving 100% backward compatibility for legacy tests and handling the `'DELETE'` action across edge cases.
3. Interaction dynamics with `App\Observers\PersonnelObserver` and `App\Jobs\SyncDevicePersonnelJob`.
4. Verification against requirements and assertions across `Tier1FeatureCoverageTest`, `Tier2BoundaryTest`, `Tier3CrossFeatureTest`, and `Tier4RealWorldScenariosTest`.

---

## 1. Investigation of `App\Services\AccessControlService`

### 1.1 Architectural Role and Requirements
`AccessControlService` is the domain authority that resolves the physical hardware perimeter for any given personnel. It must satisfy the following core requirements:

1. **Method Signature**:
   ```php
   public function getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection
   ```
   Must return an `Illuminate\Support\Collection` of `App\Models\Device` models.

2. **Direct Personnel Assignment Resolution**:
   - Queries pivot table `access_group_personnel` matching `personnel_id = $personnel->id`.
   - Filters only active access groups (`access_groups.is_active = true`).

3. **Departmental Group Assignment Resolution**:
   - Checks if `$personnel` is linked to an `Employee` record (`employees.personnel_id = $personnel->id`).
   - If linked and `$employee->department_id` is present, queries pivot table `access_group_department` matching `department_id = $employee->department_id`.
   - Filters only active access groups (`access_groups.is_active = true`).
   - Direct and departmental assignments are additive (union of authorized zones).

4. **Group Active Check**:
   - Only access groups with `is_active = true` may contribute authorized devices. Inactive groups (`is_active = false`) are strictly omitted.

5. **Device Active Check**:
   - Only devices with `devices.is_active = true` may be returned in the collection. Inactive cameras (`is_active = false`) are excluded.

6. **Device Deduplication**:
   - If a personnel is assigned to multiple overlapping access groups that link to the same device (e.g. Zone 1 has Devices [A, B], Zone 2 has Devices [B, C]), the returned collection must contain unique device instances without duplication (`$devices->count() === 3`).

7. **Zero-Groups Fallback Rule**:
   - **System with 0 Active Groups**: If `AccessGroup::count() === 0` or `!AccessGroup::where('is_active', true)->exists()`, the system operates in open/global mode, returning all active devices: `Device::where('is_active', true)->get()`.
   - **System with Active Groups but No Match**: If active access groups exist system-wide, but none are assigned to the personnel (neither directly nor via department), access is restricted and the method must return an empty collection: `collect()`.

8. **Unmigrated Schema Guard**:
   - If table `access_groups` does not yet exist in the database (e.g. pre-migration or during test suite bootstrapping), gracefully fall back to `Device::where('is_active', true)->get()`.

---

### 1.2 Proposed Implementation for `App\Services\AccessControlService`

```php
<?php

namespace App\Services;

use App\Models\AccessGroup;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Personnel;
use App\Jobs\SyncDevicePersonnelJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccessControlService
{
    /**
     * Resolve all authorized active devices for a given personnel.
     *
     * Scans direct personnel group assignments (access_group_personnel)
     * and department group assignments (access_group_department).
     *
     * Fallback: If no active access groups exist system-wide, returns all active devices.
     * If active groups exist but none match, returns an empty collection.
     */
    public function getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection
    {
        // 1. Guard against unmigrated database schema
        if (!Schema::hasTable('access_groups')) {
            return Device::where('is_active', true)->get();
        }

        // 2. Zero-groups fallback rule:
        // When no active access groups exist in the database, broadcast globally to all active devices.
        if (!AccessGroup::where('is_active', true)->exists()) {
            return Device::where('is_active', true)->get();
        }

        if (!$personnel->id) {
            return collect();
        }

        // 3. Resolve direct access groups for the personnel
        $directGroupIds = DB::table('access_group_personnel')
            ->join('access_groups', 'access_groups.id', '=', 'access_group_personnel.access_group_id')
            ->where('access_group_personnel.personnel_id', $personnel->id)
            ->where('access_groups.is_active', true)
            ->pluck('access_groups.id')
            ->all();

        // 4. Resolve departmental access groups if personnel is linked to an Employee
        $deptGroupIds = [];
        $employee = $personnel->relationLoaded('employee')
            ? $personnel->employee
            : Employee::where('personnel_id', $personnel->id)->first();

        if ($employee && $employee->department_id) {
            $deptGroupIds = DB::table('access_group_department')
                ->join('access_groups', 'access_groups.id', '=', 'access_group_department.access_group_id')
                ->where('access_group_department.department_id', $employee->department_id)
                ->where('access_groups.is_active', true)
                ->pluck('access_groups.id')
                ->all();
        }

        // 5. Combine and deduplicate active group IDs
        $groupIds = array_values(array_unique(array_merge($directGroupIds, $deptGroupIds)));

        // If active access groups exist system-wide but none match this personnel, access is restricted
        if (empty($groupIds)) {
            return collect();
        }

        // 6. Query and return active devices linked to these groups, deduplicated by device ID
        return Device::where('is_active', true)
            ->whereIn('id', function ($query) use ($groupIds) {
                $query->select('device_id')
                    ->from('access_group_device')
                    ->whereIn('access_group_id', $groupIds);
            })
            ->get();
    }

    /**
     * Resolve all authorized personnel for an access group (direct + department employees).
     */
    public function getAuthorizedPersonnelForGroup(AccessGroup $group): Collection
    {
        $directPersonnelIds = DB::table('access_group_personnel')
            ->where('access_group_id', $group->id)
            ->pluck('personnel_id')
            ->all();

        $deptIds = DB::table('access_group_department')
            ->where('access_group_id', $group->id)
            ->pluck('department_id')
            ->all();

        $deptPersonnelIds = [];
        if (!empty($deptIds)) {
            $deptPersonnelIds = Employee::whereIn('department_id', $deptIds)
                ->whereNotNull('personnel_id')
                ->pluck('personnel_id')
                ->all();
        }

        $personnelIds = array_values(array_unique(array_merge($directPersonnelIds, $deptPersonnelIds)));
        if (empty($personnelIds)) {
            return collect();
        }

        return Personnel::whereIn('id', $personnelIds)->get();
    }

    /**
     * Synchronize all personnel in an access group to all active devices in that group.
     */
    public function syncAccessGroup(AccessGroup $group): int
    {
        $personnelList = $this->getAuthorizedPersonnelForGroup($group);
        $devices = $group->devices()->where('is_active', true)->get();

        $dispatchedCount = 0;
        foreach ($personnelList as $person) {
            foreach ($devices as $device) {
                SyncDevicePersonnelJob::dispatch(
                    $device->id,
                    $person->id,
                    'EDIT',
                    $person->customize_id
                );
                $dispatchedCount++;
            }
        }

        return $dispatchedCount;
    }
}
```

---

## 2. Investigation of `App\Jobs\SyncPersonnelJob`

### 2.1 Current Implementation Analysis
`app/Jobs/SyncPersonnelJob.php` currently executes:
```php
public function handle(CameraMqttService $cameraService): void
{
    $devices = $this->targetDeviceId
        ? Device::where('id', $this->targetDeviceId)->where('is_active', true)->get()
        : Device::where('is_active', true)->get();

    if ($devices->isEmpty()) {
        Log::info("SyncPersonnelJob: No active devices found for personnel {$this->personnelId}");
        return;
    }

    $person = $this->personnelId ? Personnel::find($this->personnelId) : null;
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

### 2.2 Critical Findings & Refactoring Strategy

1. **Target Device Resolution**:
   - When `$this->targetDeviceId` is provided (e.g. targeted camera sync or failed task retry via `SyncTaskController`), target only that device.
   - When `$this->targetDeviceId` is null, target devices must be resolved via `AccessControlService::getAuthorizedDevicesForPersonnel($person)`.

2. **Handle Method Signature & Backward Compatibility**:
   - In existing tests (`PersonnelSyncTest.php:74` and `PerformanceOptimizationTest.php:486`), the test calls `$job->handle(app(CameraMqttService::class))` with **exactly one argument**.
   - If `handle()` required `AccessControlService $accessControlService` without a default value, PHP would throw an `ArgumentCountError` in existing tests.
   - **Solution**: Make the second parameter optional with fallback to `app()`:
     ```php
     public function handle(CameraMqttService $cameraService, ?AccessControlService $accessControlService = null): void
     {
         $accessControlService = $accessControlService ?? app(AccessControlService::class);
         ...
     }
     ```
   - When invoked by Laravel's queue worker, the container automatically injects both services. When invoked manually in tests with one argument, the fallback activates.

3. **Behavior of the `'DELETE'` Action**:
   - **Case A: Personnel record still exists in DB**:
     `$person` is resolved -> `$accessControlService->getAuthorizedDevicesForPersonnel($person)` returns the authorized devices for this person's zones. Deletion is dispatched strictly to the cameras that actually hold the enrolled face.
   - **Case B: Personnel record was deleted prior to job execution**:
     In `PersonnelSyncTest.php:71-74`, `$person->delete()` is executed before `new SyncPersonnelJob($personId, 'DELETE', null, $customizeId)`. In this scenario, `$person` is `null`.
     If `$person` is null and `$this->action === 'DELETE'`:
     We fall back to `Device::where('is_active', true)->get()` to ensure the deletion command (`DelPerson`) is broadcast across all active cameras. Edge cameras handle unknown face deletion idempotently without error.
   - **Case C: Personnel record is null and `$action !== 'DELETE'`**:
     Cannot ADD or EDIT a deleted/missing person. In this case, `$devices = collect()` and the job exits cleanly without dispatching `SyncDevicePersonnelJob`.

4. **`customizeIdToDelete` Handling**:
   - When `PersonnelObserver::deleting` runs, `$personnel->customize_id` is passed as the fourth argument:
     `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);`
   - The job preserves `$cId = (int) ($this->customizeIdToDelete ?? ($person ? $person->customize_id : $this->personnelId));`, ensuring that hardware deletion packets have the numeric biometric ID even if the model row is gone.

5. **`SerializesModels` Safety**:
   - To prevent `ModelNotFoundException` when a queued job attempts to deserialize a deleted model, `__construct` should only keep the model instance in memory if passed directly; otherwise keep `$personnelId` as an integer and fetch on demand in `handle()`.

---

### 2.3 Proposed Refactored `App\Jobs\SyncPersonnelJob`

```php
<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Personnel;
use App\Services\AccessControlService;
use App\Services\CameraMqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPersonnelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public ?int $personnelId = null;
    public ?Personnel $personnel = null;

    public function __construct(
        Personnel|int|null $personnel = null,
        public string $action = 'ADD', // 'ADD', 'EDIT', 'DELETE'
        public ?int $targetDeviceId = null,
        public ?int $customizeIdToDelete = null
    ) {
        if ($personnel instanceof Personnel) {
            $this->personnel = $personnel;
            $this->personnelId = $personnel->id;
        } else {
            $this->personnelId = $personnel;
            $this->personnel = null;
        }

        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService, ?AccessControlService $accessControlService = null): void
    {
        $accessControlService = $accessControlService ?? app(AccessControlService::class);

        if ($this->targetDeviceId) {
            $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
        } else {
            $person = $this->personnel ? $this->personnel : ($this->personnelId ? Personnel::find($this->personnelId) : null);

            if ($person) {
                $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
            } else {
                // If personnel record is already deleted from DB:
                // For DELETE action, broadcast revocation to all active cameras.
                // For ADD/EDIT action, do nothing.
                $devices = ($this->action === 'DELETE')
                    ? Device::where('is_active', true)->get()
                    : collect();
            }
        }

        if ($devices->isEmpty()) {
            Log::info("SyncPersonnelJob: No target devices resolved for personnel {$this->personnelId} (action: {$this->action})");
            return;
        }

        $person = $this->personnel ? $this->personnel : ($this->personnelId ? Personnel::find($this->personnelId) : null);
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
}
```

---

## 3. Interaction Dynamics: `PersonnelObserver` & `SyncDevicePersonnelJob`

### 3.1 `PersonnelObserver` Lifecycle
`app/Observers/PersonnelObserver.php` dispatches `SyncPersonnelJob` on model lifecycle events:
- **`created(Personnel $personnel)`**:
  - `SyncPersonnelJob::dispatch($personnel->id, 'ADD')`.
  - When newly created without an access group, if active access groups exist in the system, `getAuthorizedDevicesForPersonnel` returns `collect()`. The job exits without sending face data to cameras until the person is assigned to a group.
  - If no access groups exist in the system, it broadcasts to all active cameras.
- **`updated(Personnel $personnel)`**:
  - `SyncPersonnelJob::dispatch($personnel->id, 'EDIT')`.
  - Syncs updated biometric face photo and parameters to all authorized cameras in the person's active groups.
- **`deleting(Personnel $personnel)`**:
  - `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id)`.
  - Prior to deletion, cleans up pending outbox tasks: `SyncTask::where('personnel_id', $personnel->id)->delete()`.
  - Sends `DELETE` with `customizeIdToDelete = $personnel->customize_id`.

### 3.2 `EmployeeObserver` Cascading Deletion
When an `Employee` is deleted:
- `EmployeeObserver::deleting` deletes the associated `Personnel` record.
- This invokes `PersonnelObserver::deleting`, which dispatches `SyncPersonnelJob('DELETE')` with the employee's `customize_id`.
- Edge cameras receive the `DelPerson` payload via `SyncDevicePersonnelJob`.

---

## 4. Test Harness & Assertion Analysis

| Test Method | Location | Scope Verified | Critical Assertions |
|---|---|---|---|
| `test_f09_access_control_service_resolves_authorized_devices` | `Tier1FeatureCoverageTest:1086` | Direct personnel attachment to access group | `$devices = $service->getAuthorizedDevicesForPersonnel($personnel);`<br>`$this->assertTrue($devices->contains('id', $device->id));` |
| `test_f10_sync_personnel_job_dispatches_only_to_authorized_devices` | `Tier1FeatureCoverageTest:1107` | Zone-scoped job dispatching | `Queue::assertPushed(SyncDevicePersonnelJob::class, fn($j) => $j->deviceId === $authDevice->id);`<br>`Queue::assertNotPushed(SyncDevicePersonnelJob::class, fn($j) => $j->deviceId === $unauthDevice->id);` |
| `test_boundary_access_group_with_empty_membership_handles_resolution_cleanly` | `Tier2BoundaryTest:321` | Empty membership resolution | `AccessGroup::create(...)` exists, person not in it.<br>`$this->assertInstanceOf(Collection::class, $devices);`<br>`$devices->isEmpty() === true` |
| `test_boundary_system_with_zero_access_groups_falls_back_to_all_active_devices` | `Tier2BoundaryTest:339` | Global broadcast fallback when 0 groups | `$activeDevice` (`is_active=true`), `$inactiveDevice` (`is_active=false`).<br>`$this->assertTrue($devices->contains('id', $activeDevice->id));`<br>`$this->assertFalse($devices->contains('id', $inactiveDevice->id));` |
| `test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices` | `Tier2BoundaryTest:354` | Deduplication across overlapping groups | Group 1 [DevA, DevB] & Group 2 [DevB, DevC].<br>`$this->assertEquals(3, $devices->unique('id')->count());` |
| `test_cross_access_control_scopes_personnel_synchronization_to_zone` | `Tier3CrossFeatureTest:150` | Scoped multi-camera sync in a zone | Group [ZoneCam1, ZoneCam2].<br>Assert pushed for ZoneCam1 & ZoneCam2.<br>Assert NOT pushed for OtherCam. |
| `test_scenario_6_multi_building_facility_with_access_zones` | `Tier4RealWorldScenariosTest:163` | Real-world multi-building facility | Eng Zone [EngCam1, EngCam2] vs Finance Zone [FinCam].<br>Assert pushed for Eng turnstiles, never Finance. |
| `test_sync_personnel_dispatches_parallel_sync_device_personnel_jobs` | `PerformanceOptimizationTest:461` | Regression check for parallel dispatch | Asserts 2 `SyncDevicePersonnelJob` instances dispatched when 2 devices exist with 0 access groups. |
| `test_can_delete_personnel_and_sync_hardware` | `PersonnelSyncTest:63` | Regression check for delete action | `$person->delete()` executed before job handle.<br>Asserts `SyncTask` record created with status `'COMPLETED'`. |

---

## 5. Potential Pitfalls & Architectural Safeguards

1. **PostgreSQL Column Not Null Violation on Department Creation**:
   - In `test_f08`, `Department::create(['name' => 'Engineering', 'code' => 'ENG-01'])` is executed without `organization_id`.
   - In `departments` table, `organization_id` has a PostgreSQL `NOT NULL` constraint without default.
   - **Safeguard**: Explorer M2_1 identified that `Department` model must implement a `static::creating` boot hook to provide a default organization ID when not provided.

2. **Deduplication in Eloquent Queries**:
   - If Eloquent `Device::whereIn('id', subquery)` is used, SQL naturally deduplicates records by `devices.id`.
   - Returning `$devices` directly guarantees no duplicate models in the returned collection.

3. **Active State Filtering at Multiple Levels**:
   - Access group level: `access_groups.is_active = true` (checked on both `access_group_personnel` and `access_group_department`).
   - Device level: `devices.is_active = true`.
   - Inactive groups do not grant access, and inactive devices never receive sync jobs.

---

## 6. Verification Steps for Implementation Agent

To independently verify after implementation:
```bash
# 1. Run Tier 1 Feature Coverage Tests for M2
php artisan test --filter=test_f09_access_control_service_resolves_authorized_devices
php artisan test --filter=test_f10_sync_personnel_job_dispatches_only_to_authorized_devices

# 2. Run Tier 2 Boundary Tests for M2 Access Control
php artisan test --filter=test_boundary_access_group_with_empty_membership_handles_resolution_cleanly
php artisan test --filter=test_boundary_system_with_zero_access_groups_falls_back_to_all_active_devices
php artisan test --filter=test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices

# 3. Run Tier 3 Cross-Feature Tests
php artisan test --filter=test_cross_access_control_scopes_personnel_synchronization_to_zone

# 4. Run Tier 4 Real-World Scenario 6 Test
php artisan test --filter=test_scenario_6_multi_building_facility_with_access_zones

# 5. Run Regression Suites
php artisan test --filter=PersonnelSyncTest
php artisan test --filter=test_sync_personnel_dispatches_parallel_sync_device_personnel_jobs
```
