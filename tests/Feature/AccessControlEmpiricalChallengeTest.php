<?php

namespace Tests\Feature;

use App\Jobs\SyncDevicePersonnelJob;
use App\Jobs\SyncPersonnelJob;
use App\Models\AccessGroup;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessControlEmpiricalChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected AccessControlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $this->org = Organization::create([
            'name' => 'Test Corp',
            'code' => 'TEST-CORP',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$role->id]);

        $this->service = app(AccessControlService::class);
    }

    protected function createDevice(array $attributes = []): Device
    {
        static $counter = 100;
        $counter++;

        return Device::create(array_merge([
            'device_id' => "DEV-CHAL-{$counter}",
            'name' => "Challenge Camera {$counter}",
            'ip_address' => "192.168.10.{$counter}",
            'is_active' => true,
        ], $attributes));
    }

    protected function createPersonnel(array $attributes = []): Personnel
    {
        static $pCounter = 5000;
        $pCounter++;

        return Personnel::create(array_merge([
            'name' => "Personnel Member {$pCounter}",
            'customize_id' => $pCounter,
            'person_type' => 0,
            'gender' => 0,
        ], $attributes));
    }

    // =========================================================================
    // 1. ZERO-GROUP FALLBACK BEHAVIOR & BOUNDARY SCENARIOS
    // =========================================================================

    public function test_challenge_zero_groups_in_system_returns_all_active_devices_and_excludes_inactive(): void
    {
        $this->assertEquals(0, AccessGroup::count(), 'Precondition: Zero access groups in system');

        $active1 = $this->createDevice(['is_active' => true]);
        $active2 = $this->createDevice(['is_active' => true]);
        $inactive = $this->createDevice(['is_active' => false]);

        $person = $this->createPersonnel();

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        $this->assertCount(2, $devices, 'Must return exactly all active devices');
        $this->assertTrue($devices->contains('id', $active1->id));
        $this->assertTrue($devices->contains('id', $active2->id));
        $this->assertFalse($devices->contains('id', $inactive->id), 'Inactive device must be excluded');
    }

    public function test_challenge_active_group_exists_but_personnel_unassigned_returns_empty(): void
    {
        $dev = $this->createDevice(['is_active' => true]);
        $group = AccessGroup::create([
            'name' => 'Restricted Zone',
            'code' => 'ZONE-RESTRICTED',
            'is_active' => true,
        ]);
        $group->devices()->attach($dev->id);

        $unassignedPerson = $this->createPersonnel();

        $devices = $this->service->getAuthorizedDevicesForPersonnel($unassignedPerson);

        $this->assertCount(0, $devices, 'Unassigned personnel in segmented system must receive 0 devices');
    }

    public function test_challenge_all_groups_inactive_does_not_grant_all_devices(): void
    {
        $dev1 = $this->createDevice(['is_active' => true]);
        $dev2 = $this->createDevice(['is_active' => true]);

        // Access group exists, but is deactivated
        $group = AccessGroup::create([
            'name' => 'Decommissioned Zone',
            'code' => 'ZONE-DECOMM',
            'is_active' => false,
        ]);
        $group->devices()->attach([$dev1->id, $dev2->id]);

        $person = $this->createPersonnel();
        $group->personnel()->attach($person->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        // Security check: When access groups are defined in the database, deactivating them
        // must NOT cause a security bypass opening all cameras to all employees!
        $this->assertCount(0, $devices, 'Inactive access group must NOT grant access to devices');
    }

    public function test_challenge_zero_groups_personnel_creation_observer_sync_dispatch(): void
    {
        $this->assertEquals(0, AccessGroup::count(), 'Precondition: Zero access groups in system');

        $dev = $this->createDevice(['is_active' => true]);

        Queue::fake([SyncDevicePersonnelJob::class]);

        // When a personnel is created in a legacy unsegmented system, it should sync to all active devices
        $person = Personnel::create([
            'name' => 'Legacy Onboarding Employee',
            'customize_id' => 9901,
            'person_type' => 0,
        ]);

        // Direct explicit job without observer flag
        $job = new SyncPersonnelJob($person->id, 'ADD');
        $job->handle(app(\App\Services\CameraMqttService::class), $this->service);

        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($pushedJob) use ($dev, $person) {
            return $pushedJob->deviceId === $dev->id && $pushedJob->personnelId === $person->id;
        });
    }

    public function test_challenge_zero_groups_personnel_observer_syncs_to_active_devices_in_production(): void
    {
        $this->assertEquals(0, AccessGroup::count(), 'Precondition: Zero access groups in system');

        $dev = $this->createDevice(['is_active' => true]);

        Queue::fake([SyncDevicePersonnelJob::class]);

        // Creating personnel triggers PersonnelObserver::created
        // which dispatches SyncPersonnelJob with $fromObserver = true
        $person = Personnel::create([
            'name' => 'Observer Onboarding Employee',
            'customize_id' => 9902,
            'person_type' => 0,
        ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($pushedJob) use ($dev, $person) {
            return $pushedJob->deviceId === $dev->id && $pushedJob->personnelId === $person->id;
        });
    }

    // =========================================================================
    // 2. OVERLAPPING ACCESS GROUPS DEDUPLICATION
    // =========================================================================

    public function test_challenge_overlapping_groups_strictly_deduplicates_devices(): void
    {
        $devShared = $this->createDevice(['device_id' => 'DEV-SHARED']);
        $devA = $this->createDevice(['device_id' => 'DEV-A']);
        $devB = $this->createDevice(['device_id' => 'DEV-B']);

        $grpA = AccessGroup::create(['name' => 'Zone A', 'code' => 'ZONE-A', 'is_active' => true]);
        $grpB = AccessGroup::create(['name' => 'Zone B', 'code' => 'ZONE-B', 'is_active' => true]);

        // Both groups contain DEV-SHARED
        $grpA->devices()->attach([$devA->id, $devShared->id]);
        $grpB->devices()->attach([$devB->id, $devShared->id]);

        $person = $this->createPersonnel();
        $grpA->personnel()->attach($person->id);
        $grpB->personnel()->attach($person->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        $this->assertCount(3, $devices, 'Should have exactly 3 unique devices');
        $this->assertEquals(3, $devices->pluck('id')->unique()->count(), 'IDs must be unique');
        $this->assertEquals(1, $devices->where('id', $devShared->id)->count(), 'Shared device must only appear once');
    }

    public function test_challenge_overlapping_direct_and_departmental_groups_deduplicates(): void
    {
        $devShared = $this->createDevice(['device_id' => 'DEV-COMBINED-SHARED']);
        $devDirect = $this->createDevice(['device_id' => 'DEV-DIRECT-ONLY']);
        $devDept = $this->createDevice(['device_id' => 'DEV-DEPT-ONLY']);

        $grpDirect = AccessGroup::create(['name' => 'Direct Zone', 'code' => 'ZONE-DIR', 'is_active' => true]);
        $grpDept = AccessGroup::create(['name' => 'Dept Zone', 'code' => 'ZONE-DEP', 'is_active' => true]);

        $grpDirect->devices()->attach([$devDirect->id, $devShared->id]);
        $grpDept->devices()->attach([$devDept->id, $devShared->id]);

        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true,
        ]);
        $grpDept->departments()->attach($dept->id);

        $person = $this->createPersonnel();
        $grpDirect->personnel()->attach($person->id);

        Employee::create([
            'personnel_id' => $person->id,
            'department_id' => $dept->id,
            'employee_code' => 'EMP-DEDUP-01',
            'first_name' => 'Dedup',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        $this->assertCount(3, $devices);
        $this->assertEquals(1, $devices->where('id', $devShared->id)->count());
        $this->assertTrue($devices->contains('id', $devDirect->id));
        $this->assertTrue($devices->contains('id', $devDept->id));
    }

    public function test_challenge_sync_personnel_job_dispatches_exact_deduplicated_job_count(): void
    {
        Queue::fake([SyncDevicePersonnelJob::class]);

        $dev1 = $this->createDevice();
        $dev2 = $this->createDevice();

        $grp1 = AccessGroup::create(['name' => 'G1', 'code' => 'G1', 'is_active' => true]);
        $grp2 = AccessGroup::create(['name' => 'G2', 'code' => 'G2', 'is_active' => true]);

        // Both link to dev1 and dev2
        $grp1->devices()->attach([$dev1->id, $dev2->id]);
        $grp2->devices()->attach([$dev1->id, $dev2->id]);

        $person = $this->createPersonnel();
        $grp1->personnel()->attach($person->id);
        $grp2->personnel()->attach($person->id);

        $job = new SyncPersonnelJob($person->id, 'ADD');
        $job->handle(app(\App\Services\CameraMqttService::class), $this->service);

        // Should dispatch exactly 2 jobs: one for dev1, one for dev2 (never 4)
        Queue::assertPushed(SyncDevicePersonnelJob::class, 2);
    }

    // =========================================================================
    // 3. INACTIVE GROUPS & INACTIVE DEVICES EXCLUSION
    // =========================================================================

    public function test_challenge_inactive_access_group_devices_excluded_even_when_person_assigned(): void
    {
        $devActiveGroup = $this->createDevice(['device_id' => 'DEV-ACT-GRP']);
        $devInactiveGroup = $this->createDevice(['device_id' => 'DEV-INACT-GRP']);

        $grpActive = AccessGroup::create(['name' => 'Active Zone', 'code' => 'ACT-Z', 'is_active' => true]);
        $grpInactive = AccessGroup::create(['name' => 'Inactive Zone', 'code' => 'INACT-Z', 'is_active' => false]);

        $grpActive->devices()->attach($devActiveGroup->id);
        $grpInactive->devices()->attach($devInactiveGroup->id);

        $person = $this->createPersonnel();
        $grpActive->personnel()->attach($person->id);
        $grpInactive->personnel()->attach($person->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        $this->assertCount(1, $devices);
        $this->assertTrue($devices->contains('id', $devActiveGroup->id));
        $this->assertFalse($devices->contains('id', $devInactiveGroup->id), 'Devices in inactive access group must be excluded');
    }

    public function test_challenge_inactive_devices_excluded_from_active_access_group(): void
    {
        $devOnline = $this->createDevice(['device_id' => 'DEV-ONLINE', 'is_active' => true]);
        $devOffline = $this->createDevice(['device_id' => 'DEV-OFFLINE', 'is_active' => false]);

        $grp = AccessGroup::create(['name' => 'Zone Online Offline', 'code' => 'Z-ON-OFF', 'is_active' => true]);
        $grp->devices()->attach([$devOnline->id, $devOffline->id]);

        $person = $this->createPersonnel();
        $grp->personnel()->attach($person->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($person);

        $this->assertCount(1, $devices);
        $this->assertTrue($devices->contains('id', $devOnline->id));
        $this->assertFalse($devices->contains('id', $devOffline->id), 'Inactive device must be excluded');
    }

    public function test_challenge_sync_zone_excludes_inactive_devices(): void
    {
        Queue::fake([SyncDevicePersonnelJob::class]);

        $devActive = $this->createDevice(['is_active' => true]);
        $devInactive = $this->createDevice(['is_active' => false]);

        $grp = AccessGroup::create(['name' => 'Zone Partial', 'code' => 'Z-PART', 'is_active' => true]);
        $grp->devices()->attach([$devActive->id, $devInactive->id]);

        $person = $this->createPersonnel();
        $grp->personnel()->attach($person->id);

        $result = $this->service->syncZone($grp);

        $this->assertEquals(1, $result['devices_count']);
        $this->assertEquals(1, $result['dispatched_jobs']);

        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($job) use ($devActive) {
            return $job->deviceId === $devActive->id;
        });
        Queue::assertNotPushed(SyncDevicePersonnelJob::class, function ($job) use ($devInactive) {
            return $job->deviceId === $devInactive->id;
        });
    }

    // =========================================================================
    // 4. NESTED DEPARTMENTAL ACCESS INHERITANCE
    // =========================================================================

    public function test_challenge_multi_tier_department_hierarchy_inheritance(): void
    {
        // 3-tier hierarchy: Corp HQ -> Operations -> Logistics
        $hq = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Corp HQ',
            'code' => 'DEPT-HQ',
            'is_active' => true,
        ]);

        $ops = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Operations',
            'code' => 'DEPT-OPS',
            'parent_id' => $hq->id,
            'is_active' => true,
        ]);

        $logistics = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Logistics',
            'code' => 'DEPT-LOG',
            'parent_id' => $ops->id,
            'is_active' => true,
        ]);

        $devHq = $this->createDevice(['device_id' => 'DEV-HQ-ENTRANCE']);
        $devOps = $this->createDevice(['device_id' => 'DEV-OPS-FLOOR']);

        // Group 1 linked to HQ Department
        $grpHq = AccessGroup::create(['name' => 'HQ General Access', 'code' => 'ZONE-HQ-GEN', 'is_active' => true]);
        $grpHq->devices()->attach($devHq->id);
        $grpHq->departments()->attach($hq->id);

        // Group 2 linked to Operations Department
        $grpOps = AccessGroup::create(['name' => 'Ops Floor Access', 'code' => 'ZONE-OPS-FLR', 'is_active' => true]);
        $grpOps->devices()->attach($devOps->id);
        $grpOps->departments()->attach($ops->id);

        // Employee in Logistics (Grandchild of HQ, Child of Ops)
        $personLogistics = $this->createPersonnel(['name' => 'Logistics Worker']);
        Employee::create([
            'personnel_id' => $personLogistics->id,
            'department_id' => $logistics->id,
            'employee_code' => 'EMP-LOG-01',
            'first_name' => 'Logistics',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);

        // Logistics worker should inherit both HQ and Ops access!
        $devices = $this->service->getAuthorizedDevicesForPersonnel($personLogistics);

        $this->assertCount(2, $devices, 'Logistics worker must inherit access from Operations and HQ');
        $this->assertTrue($devices->contains('id', $devHq->id));
        $this->assertTrue($devices->contains('id', $devOps->id));

        // Group personnel resolution test: getAuthorizedPersonnelForGroup
        $hqMembers = $this->service->getAuthorizedPersonnelForGroup($grpHq);
        $this->assertTrue($hqMembers->contains('id', $personLogistics->id), 'HQ Group must include Logistics worker via descendant resolution');

        $opsMembers = $this->service->getAuthorizedPersonnelForGroup($grpOps);
        $this->assertTrue($opsMembers->contains('id', $personLogistics->id), 'Ops Group must include Logistics worker');
    }

    public function test_challenge_department_inheritance_does_not_leak_upwards_to_parent(): void
    {
        $parentDept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Corporate Executives',
            'code' => 'DEPT-EXEC',
            'is_active' => true,
        ]);

        $childDept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Server Room IT Ops',
            'code' => 'DEPT-IT-OPS',
            'parent_id' => $parentDept->id,
            'is_active' => true,
        ]);

        $devServerRoom = $this->createDevice(['device_id' => 'DEV-SERVER-ROOM']);

        // Group linked ONLY to child department
        $grpSub = AccessGroup::create(['name' => 'Server Room Zone', 'code' => 'ZONE-SRV', 'is_active' => true]);
        $grpSub->devices()->attach($devServerRoom->id);
        $grpSub->departments()->attach($childDept->id);

        // Employee in Parent Department (Executive)
        $personExec = $this->createPersonnel(['name' => 'Corporate Executive']);
        Employee::create([
            'personnel_id' => $personExec->id,
            'department_id' => $parentDept->id,
            'employee_code' => 'EMP-EXEC-01',
            'first_name' => 'Corp',
            'last_name' => 'Exec',
            'employment_status' => 'active',
        ]);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($personExec);

        $this->assertCount(0, $devices, 'Parent department employee must NOT inherit access granted specifically to child department');
    }

    public function test_challenge_sibling_departments_do_not_share_access(): void
    {
        $parent = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Shared Services',
            'code' => 'DEPT-SHR',
            'is_active' => true,
        ]);

        $siblingFinance = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Finance',
            'code' => 'DEPT-FIN',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $siblingLegal = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Legal',
            'code' => 'DEPT-LEG',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $devVault = $this->createDevice(['device_id' => 'DEV-FIN-VAULT']);

        $grpFinance = AccessGroup::create(['name' => 'Finance Vault', 'code' => 'ZONE-VAULT', 'is_active' => true]);
        $grpFinance->devices()->attach($devVault->id);
        $grpFinance->departments()->attach($siblingFinance->id);

        $personLegal = $this->createPersonnel(['name' => 'Legal Counsel']);
        Employee::create([
            'personnel_id' => $personLegal->id,
            'department_id' => $siblingLegal->id,
            'employee_code' => 'EMP-LEG-01',
            'first_name' => 'Legal',
            'last_name' => 'Counsel',
            'employment_status' => 'active',
        ]);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($personLegal);

        $this->assertCount(0, $devices, 'Sibling Legal department must NOT inherit Finance access');
    }

    // =========================================================================
    // 5. EDGE CASES & BOUNDARY ROBUSTNESS
    // =========================================================================

    public function test_challenge_personnel_without_employee_record_handles_gracefully(): void
    {
        $dev = $this->createDevice();
        $grp = AccessGroup::create(['name' => 'Visitor Zone', 'code' => 'ZONE-VIS', 'is_active' => true]);
        $grp->devices()->attach($dev->id);

        // Personnel has NO linked employee
        $personnel = $this->createPersonnel();
        $this->assertNull($personnel->employee);

        $grp->personnel()->attach($personnel->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertCount(1, $devices);
        $this->assertTrue($devices->contains('id', $dev->id));
    }

    public function test_challenge_employee_with_null_department_handles_gracefully(): void
    {
        $dev = $this->createDevice();
        $grp = AccessGroup::create(['name' => 'Temp Zone', 'code' => 'ZONE-TMP', 'is_active' => true]);
        $grp->devices()->attach($dev->id);

        $personnel = $this->createPersonnel();
        Employee::create([
            'personnel_id' => $personnel->id,
            'department_id' => null, // Explicit null department
            'employee_code' => 'EMP-NODEPT',
            'first_name' => 'No',
            'last_name' => 'Dept',
            'employment_status' => 'active',
        ]);

        $grp->personnel()->attach($personnel->id);

        $devices = $this->service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertCount(1, $devices);
    }

    public function test_challenge_empty_access_group_sync_zone_returns_clean_zero_summary(): void
    {
        $grp = AccessGroup::create([
            'name' => 'Empty Ghost Zone',
            'code' => 'ZONE-GHOST',
            'is_active' => true,
        ]);

        $result = $this->service->syncZone($grp);

        $this->assertEquals(0, $result['devices_count']);
        $this->assertEquals(0, $result['personnel_count']);
        $this->assertEquals(0, $result['dispatched_jobs']);
    }

    public function test_challenge_api_resync_endpoint_with_empty_group_returns_200(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $grp = AccessGroup::create([
            'name' => 'Empty API Zone',
            'code' => 'ZONE-API-EMPTY',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/access-groups/{$grp->id}/sync-now");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'devices_count' => 0,
            'personnel_count' => 0,
            'dispatched_count' => 0,
        ]);
    }
}
