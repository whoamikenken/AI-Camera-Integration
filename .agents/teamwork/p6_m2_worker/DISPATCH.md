# Worker M2 Dispatch Directive — Application Runtime & Compute Overhaul (Tasks 6.5 – 6.7)

## Objective
Implement Phase 6 Tasks 6.5, 6.6, and 6.7 for the Intelligent AI Camera Hub.

## Reference Documents (Must Read First)
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/DISPATCH.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_2/survey_compute_cache_report.md`

## Exclusive File Ownership
- `app/Http/Controllers/EmployeeController.php`
- `app/Models/Employee.php`
- `app/Http/Controllers/DeviceController.php`
- `app/Http/Controllers/DeviceAlertController.php`

## Tasks
1. **Task 6.5: Eliminate O(N) DB Queries in `Employee::isRestDay` Inside Summary Loop**
   - In `app/Models/Employee.php`: Add optional `$preloadedAssignments = null` parameter to `isRestDay(Carbon|string $date, ?iterable $preloadedAssignments = null): bool`. If provided (or relation loaded), filter in-memory with:
     `$from <= $dateStr && ($to === null || $to >= $dateStr)`
     Fall back to existing DB query if null and not relation loaded.
   - In `app/Http/Controllers/EmployeeController.php:262-269` (`attendanceSummary`):
     Pre-fetch overlapping shift assignments before the while loop with:
     ```php
     $shiftAssignments = $employee->shiftAssignments()
         ->where('effective_from', '<=', $to->toDateString())
         ->where(function ($q) use ($from) {
             $q->whereNull('effective_to')
               ->orWhere('effective_to', '>=', $from->toDateString());
         })
         ->orderBy('effective_from', 'desc')
         ->orderBy('id', 'desc')
         ->get();
     ```
     Pass `$shiftAssignments` to `$employee->isRestDay($current, $shiftAssignments)`.

2. **Task 6.6: Eliminate Linear O(NxM) Scan and Large Outbox Pull in `DeviceController::audit()`**
   - In `app/Http/Controllers/DeviceController.php`:
     - Key `$localPersonnel` by `customize_id`: `$localPersonnelKeyed = $localPersonnel->keyBy('customize_id');`
     - Replace `$localPersonnel->contains('customize_id', $cId)` with `$localPersonnelKeyed->has($cId);`
     - For sync tasks: Fetch only latest sync task per personnel via SQL subquery instead of pulling all historical tasks:
       ```php
       $latestTaskIds = \App\Models\SyncTask::where('device_id', $device->device_id)
           ->whereNotNull('personnel_id')
           ->groupBy('personnel_id')
           ->selectRaw('MAX(id)');
       $syncTasks = \App\Models\SyncTask::whereIn('id', $latestTaskIds)
           ->get()
           ->keyBy('personnel_id');
       ```

3. **Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`**
   - In `app/Http/Controllers/DeviceAlertController.php:110-128`:
     - Update all alerts in a single SQL query: `DeviceAlert::whereIn('id', $validated['ids'])->update($updateData);`
     - Iterate in-memory `$alerts` collection to update local properties and dispatch broadcast events `broadcast(new DeviceAlertUpdated($alert, $previousStatus));` without issuing row-by-row SQL updates.
     - Invalidate stats caches: `Cache::forget('device_alert_stats');` and `Cache::forget('dashboard_telemetry_stats');`.

## Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Verification
- Run `php artisan test --filter=PerformanceOptimizationTest`
- Run `php artisan test`


## 2026-10-08T00:58:23Z
You are Worker M2 (Application Runtime & Compute Specialist) for Phase 6 Performance Optimization in AI-Camera-Integration.

Working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_m2_worker
Tasks:
- Task 6.5: Eliminate O(N) DB Queries in `Employee::isRestDay`
- Task 6.6: Eliminate Linear O(NxM) Scan and Large Outbox Pull in `DeviceController::audit()`
- Task 6.7: Batch Multi-Record SQL Updates in `DeviceAlertController::bulkUpdateStatus`
