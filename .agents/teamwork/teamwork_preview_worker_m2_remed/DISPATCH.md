# DISPATCH — Milestone M2 Remediation Worker

## Task
Remediate the 5 forensic audit and challenger defects identified in Milestone M2:
1. `app/Jobs/SyncPersonnelJob.php`:
   - Remove `$fromObserver` parameter and property.
   - Delete lines 54–55 (`elseif ($this->fromObserver && ...)`).
   - Ensure target device resolution unconditionally calls `$accessControlService->getAuthorizedDevicesForPersonnel($person)`.
2. `app/Observers/PersonnelObserver.php`:
   - Remove the `$fromObserver` 5th argument from `SyncPersonnelJob::dispatch()` in `created()` and `updated()`.
3. `app/Services/AccessControlService.php`:
   - Update line 26:
     ```php
     if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) {
         return Device::where('is_active', true)->get();
     }
     ```
   - When `AccessGroup::count() > 0`, if no active access groups match the personnel (or all groups are inactive), return `collect()` (zero devices).
4. `app/Http/Controllers/AccessGroupController.php`:
   - Update lines 20–27 to use driver-aware matching:
     ```php
     $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
     $query->where(function ($q) use ($search, $like) {
         $q->where('name', $like, "%{$search}%")
           ->orWhere('code', $like, "%{$search}%")
           ->orWhere('description', $like, "%{$search}%");
     });
     ```
5. Test Harness Cleanups:
   - In `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1115` (`test_f10`) and `tests/Feature/E2E/Tier3CrossFeatureTest.php:161` (`test_cross_access_control`), isolate fixture creation using `Personnel::withoutEvents(fn () => $this->createTestPersonnel())` so setup doesn't trigger observer queue pushes before access groups are linked.
   - In `tests/Feature/AdversarialMilestone2Challenger2Test.php:650`, update the SQLite search probe from `assertStatus(500)` to `assertStatus(200)` and `assertJsonCount(1, 'data')`.

## References
- Auditor Report: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m2_1/handoff.md`
- Remediation Explorer 1 Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_1/handoff.md`
- Remediation Explorer 2 Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_2/handoff.md`
- Remediation Explorer 3 Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_3/handoff.md`
- Authoritative Request: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`

## Verification Commands
- `php artisan test --filter="test_f0[5-9]|test_f1[0-2]"`
- `php artisan test --filter="test_boundary_.*access_group"`
- `php artisan test --filter=test_cross_access_control`
- `php artisan test --filter=PersonnelSyncTest`
- `php artisan test --filter=DeviceManagementTest::test_device_audit_returns_unified_user_roster`
- `php artisan test tests/Feature/AccessControlEmpiricalChallengeTest.php` (all 18 must pass)
- `php artisan test tests/Feature/AdversarialMilestone2Challenger2Test.php` (all 17 must pass)
- `php artisan test --filter=Milestone2` (all 34 must pass)
- `npm run build`

## 2026-10-08T01:12:46Z
You are teamwork_preview_worker_m2_remed executing remediation for Milestone M2: Granular Access Control Groups & Zone-Based Dispatching.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m2_remed

Authoritative references:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_10/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m2_remed/DISPATCH.md
- Auditor Evidence: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_auditor_m2_1/handoff.md
- Remediation Explorer 1 Blueprint: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_1/handoff.md
- Remediation Explorer 2 Blueprint: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_2/handoff.md
- Remediation Explorer 3 Blueprint: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m2_remed_3/handoff.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your Tasks:
1. `app/Jobs/SyncPersonnelJob.php`:
   - Remove `$fromObserver` parameter from constructor and property declaration.
   - Delete lines 54–55 (`elseif ($this->fromObserver && ...)`).
   - Ensure target device resolution unconditionally calls `$accessControlService->getAuthorizedDevicesForPersonnel($person)` when `$this->targetDeviceId` is not specified.
2. `app/Observers/PersonnelObserver.php`:
   - Remove `$fromObserver` (the 5th argument) from `SyncPersonnelJob::dispatch()` in `created()` and `updated()`.
3. `app/Services/AccessControlService.php`:
   - Update line 26:
     ```php
     if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) {
         return Device::where('is_active', true)->get();
     }
     ```
   - When `AccessGroup::count() > 0`, if no active access groups match the personnel (or all groups are inactive), return `collect()` (zero devices).
4. `app/Http/Controllers/AccessGroupController.php`:
   - Update lines 20–27 to use driver-aware matching:
     ```php
     $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
     $query->where(function ($q) use ($search, $like) {
         $q->where('name', $like, "%{$search}%")
           ->orWhere('code', $like, "%{$search}%")
           ->orWhere('description', $like, "%{$search}%");
     });
     ```
5. Test Harness Cleanups:
   - In `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1115` (`test_f10`) and `tests/Feature/E2E/Tier3CrossFeatureTest.php:161` (`test_cross_access_control`), wrap test fixture creation with `Personnel::withoutEvents(fn () => $this->createTestPersonnel())` so setup doesn't trigger observer queue pushes before access groups are linked.
   - In `tests/Feature/AdversarialMilestone2Challenger2Test.php:650`, update the SQLite search probe assertion from 500 to 200.
6. Run Verification Commands:
   - `php artisan test --filter="test_f0[5-9]|test_f1[0-2]"`
   - `php artisan test --filter="test_boundary_.*access_group"`
   - `php artisan test --filter=test_cross_access_control`
   - `php artisan test --filter=PersonnelSyncTest`
   - `php artisan test --filter=DeviceManagementTest::test_device_audit_returns_unified_user_roster`
   - `php artisan test tests/Feature/AccessControlEmpiricalChallengeTest.php` (must pass 18/18)
   - `php artisan test tests/Feature/AdversarialMilestone2Challenger2Test.php` (must pass 17/17)
   - `php artisan test --filter=Milestone2` (must pass 34/34)
   - `npm run build`
