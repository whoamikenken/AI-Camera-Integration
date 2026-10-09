<?php

namespace Tests\Feature;

use App\Jobs\SyncDevicePersonnelJob;
use App\Models\AccessGroup;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialMilestone2Challenger2Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $restrictedUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $viewerRole = Role::firstOrCreate(['slug' => 'restricted-viewer'], [
            'name' => 'Restricted Viewer',
            'is_system' => false,
        ]);

        $this->org = Organization::create([
            'name' => 'Challenger Test Org',
            'code' => 'CHALLENGER-ORG',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->restrictedUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->restrictedUser->roles()->sync([$viewerRole->id]);

        auth()->forgetGuards();
    }

    protected function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    protected function actingAsRestricted(): void
    {
        Sanctum::actingAs($this->restrictedUser, ['*']);
    }

    protected function createDevice(array $overrides = []): Device
    {
        return Device::create(array_merge([
            'device_id' => 'DEV-' . uniqid(),
            'name' => 'Gate Camera',
            'ip_address' => '192.168.1.' . rand(10, 250),
            'port' => 1883,
            'is_active' => true,
        ], $overrides));
    }

    protected function createPersonnel(array $overrides = []): Personnel
    {
        return Personnel::create(array_merge([
            'customize_id' => rand(100000, 999999),
            'name' => 'Person ' . uniqid(),
            'person_type' => 0,
            'gender' => 0,
            'temp_valid' => 0,
            'effect_number' => -1,
        ], $overrides));
    }

    // =========================================================================
    // 1. ZONE RESYNC STRESS TESTING (POST /api/access-groups/{id}/sync-now)
    // =========================================================================

    /**
     * Test Case 1.1: 0 devices, 0 personnel in group
     */
    public function test_sync_now_with_zero_devices_and_zero_personnel(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $group = AccessGroup::create([
            'name' => 'Empty Zone',
            'code' => 'ZONE-EMPTY-' . uniqid(),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 0,
                'personnel_count' => 0,
                'dispatched_count' => 0,
            ]);

        Queue::assertNothingPushed();
    }

    /**
     * Test Case 1.2: 5 active devices, 20 personnel in group -> 100 dispatched jobs
     */
    public function test_sync_now_with_five_devices_and_twenty_personnel(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $group = AccessGroup::create([
            'name' => 'Scale Zone 5x20',
            'code' => 'ZONE-SCALE-' . uniqid(),
            'is_active' => true,
        ]);

        $devices = [];
        for ($i = 0; $i < 5; $i++) {
            $devices[] = $this->createDevice();
        }

        $personnel = [];
        for ($i = 0; $i < 20; $i++) {
            $personnel[] = $this->createPersonnel();
        }

        $group->devices()->attach(collect($devices)->pluck('id'));
        $group->personnel()->attach(collect($personnel)->pluck('id'));

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 5,
                'personnel_count' => 20,
                'dispatched_count' => 100,
            ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, 100);

        // Verify that every combination of (device, person) was queued
        foreach ($devices as $dev) {
            foreach ($personnel as $pers) {
                Queue::assertPushed(SyncDevicePersonnelJob::class, function ($job) use ($dev, $pers) {
                    return $job->deviceId === $dev->id
                        && $job->personnelId === $pers->id
                        && $job->action === 'ADD'
                        && $job->customizeIdToDelete === $pers->customize_id;
                });
            }
        }
    }

    /**
     * Test Case 1.3: Inactive devices in the group are skipped from sync
     * 3 active devices + 2 inactive devices; 4 personnel -> 3 * 4 = 12 jobs dispatched
     */
    public function test_sync_now_skips_inactive_devices_in_group(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $group = AccessGroup::create([
            'name' => 'Mixed Activity Zone',
            'code' => 'ZONE-MIXED-' . uniqid(),
            'is_active' => true,
        ]);

        $activeDevices = [
            $this->createDevice(['is_active' => true]),
            $this->createDevice(['is_active' => true]),
            $this->createDevice(['is_active' => true]),
        ];

        $inactiveDevices = [
            $this->createDevice(['is_active' => false]),
            $this->createDevice(['is_active' => false]),
        ];

        $allDeviceIds = collect($activeDevices)->merge($inactiveDevices)->pluck('id');
        $group->devices()->attach($allDeviceIds);

        $personnel = [
            $this->createPersonnel(),
            $this->createPersonnel(),
            $this->createPersonnel(),
            $this->createPersonnel(),
        ];
        $group->personnel()->attach(collect($personnel)->pluck('id'));

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 3,
                'personnel_count' => 4,
                'dispatched_count' => 12,
            ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, 12);

        // Assert no inactive devices were pushed
        foreach ($inactiveDevices as $inactDev) {
            Queue::assertNotPushed(SyncDevicePersonnelJob::class, function ($job) use ($inactDev) {
                return $job->deviceId === $inactDev->id;
            });
        }
    }

    /**
     * Test Case 1.4: Department membership resolution in zone resync
     */
    public function test_sync_now_resolves_personnel_via_attached_department(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $dept = Department::create([
            'name' => 'Research',
            'code' => 'RES-' . uniqid(),
            'organization_id' => $this->org->id,
        ]);

        $device = $this->createDevice();

        $group = AccessGroup::create([
            'name' => 'Research Lab Zone',
            'code' => 'ZONE-RES-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach($device->id);
        $group->departments()->attach($dept->id);

        // Create 2 employees in the department with linked personnel
        $p1 = $this->createPersonnel();
        Employee::create([
            'employee_code' => 'EMP-RES-1',
            'first_name' => 'Marie',
            'last_name' => 'Curie',
            'department_id' => $dept->id,
            'personnel_id' => $p1->id,
            'employment_status' => 'active',
        ]);

        $p2 = $this->createPersonnel();
        Employee::create([
            'employee_code' => 'EMP-RES-2',
            'first_name' => 'Louis',
            'last_name' => 'Pasteur',
            'department_id' => $dept->id,
            'personnel_id' => $p2->id,
            'employment_status' => 'active',
        ]);

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 1,
                'personnel_count' => 2,
                'dispatched_count' => 2,
            ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, 2);
    }

    /**
     * Test Case 1.5: Deduplication when personnel is both direct member and department member
     */
    public function test_sync_now_deduplicates_personnel_in_direct_and_department(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $dept = Department::create([
            'name' => 'Security Ops',
            'code' => 'SEC-OPS-' . uniqid(),
            'organization_id' => $this->org->id,
        ]);

        $device = $this->createDevice();

        $group = AccessGroup::create([
            'name' => 'Security Suite',
            'code' => 'ZONE-SECOPS-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach($device->id);
        $group->departments()->attach($dept->id);

        $person = $this->createPersonnel();
        Employee::create([
            'employee_code' => 'EMP-SEC-1',
            'first_name' => 'Guard',
            'last_name' => 'One',
            'department_id' => $dept->id,
            'personnel_id' => $person->id,
            'employment_status' => 'active',
        ]);

        // Also attach person directly to the group
        $group->personnel()->attach($person->id);

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 1,
                'personnel_count' => 1,
                'dispatched_count' => 1,
            ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, 1);
    }

    // =========================================================================
    // 2. CRUD VALIDATION & INTEGRITY TESTING
    // =========================================================================

    /**
     * Test Case 2.1: Duplicate code rejection on store
     */
    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();

        $code = 'ZONE-UNIQUE-01';
        AccessGroup::create([
            'name' => 'First Zone',
            'code' => $code,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/access-groups', [
            'name' => 'Second Zone With Same Code',
            'code' => $code,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * Test Case 2.2: Duplicate code rejection on update, but allow keeping own code
     */
    public function test_update_validates_code_uniqueness_correctly(): void
    {
        $this->actingAsAdmin();

        $groupA = AccessGroup::create([
            'name' => 'Zone A',
            'code' => 'ZONE-CODE-A',
            'is_active' => true,
        ]);

        $groupB = AccessGroup::create([
            'name' => 'Zone B',
            'code' => 'ZONE-CODE-B',
            'is_active' => true,
        ]);

        // Attempt to update Group B to Group A's code -> fails 422
        $resConflict = $this->putJson("/api/access-groups/{$groupB->id}", [
            'code' => 'ZONE-CODE-A',
        ]);
        $resConflict->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Update Group A keeping its own code -> succeeds 200
        $resSelf = $this->putJson("/api/access-groups/{$groupA->id}", [
            'code' => 'ZONE-CODE-A',
            'name' => 'Zone A Renamed',
        ]);
        $resSelf->assertStatus(200)
            ->assertJsonPath('data.name', 'Zone A Renamed');
    }

    /**
     * Test Case 2.3: Non-existent IDs return 404
     */
    public function test_non_existent_group_ids_return_404(): void
    {
        $this->actingAsAdmin();
        $fakeId = 99999999;

        $this->getJson("/api/access-groups/{$fakeId}")->assertStatus(404);
        $this->putJson("/api/access-groups/{$fakeId}", ['name' => 'Ghost'])->assertStatus(404);
        $this->deleteJson("/api/access-groups/{$fakeId}")->assertStatus(404);
        $this->postJson("/api/access-groups/{$fakeId}/sync-now")->assertStatus(404);
    }

    /**
     * Test Case 2.4: Invalid foreign keys in store/update payload return 422
     */
    public function test_invalid_foreign_keys_return_422(): void
    {
        $this->actingAsAdmin();

        $invalidPayloads = [
            'invalid_device_id' => [
                'name' => 'Invalid Dev',
                'code' => 'INV-DEV-' . uniqid(),
                'device_ids' => [9999999],
            ],
            'invalid_personnel_id' => [
                'name' => 'Invalid Pers',
                'code' => 'INV-PERS-' . uniqid(),
                'personnel_ids' => [9999999],
            ],
            'invalid_department_id' => [
                'name' => 'Invalid Dept',
                'code' => 'INV-DEPT-' . uniqid(),
                'department_ids' => [9999999],
            ],
            'invalid_organization_id' => [
                'name' => 'Invalid Org',
                'code' => 'INV-ORG-' . uniqid(),
                'organization_id' => 9999999,
            ],
        ];

        foreach ($invalidPayloads as $field => $payload) {
            $res = $this->postJson('/api/access-groups', $payload);
            $res->assertStatus(422);
        }
    }

    /**
     * Test Case 2.5: Required fields and string length boundaries
     */
    public function test_store_validates_required_fields_and_string_lengths(): void
    {
        $this->actingAsAdmin();

        // Empty payload
        $this->postJson('/api/access-groups', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);

        // Exceed max lengths: name max 128, code max 64, description max 255
        $this->postJson('/api/access-groups', [
            'name' => str_repeat('A', 129),
            'code' => str_repeat('B', 65),
            'description' => str_repeat('C', 256),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code', 'description']);
    }

    /**
     * Test Case 2.6: Updating relationships synchronizes pivot tables
     */
    public function test_update_synchronizes_relationships_accurately(): void
    {
        $this->actingAsAdmin();

        $dev1 = $this->createDevice();
        $dev2 = $this->createDevice();
        $dev3 = $this->createDevice();

        $pers1 = $this->createPersonnel();
        $pers2 = $this->createPersonnel();
        $pers3 = $this->createPersonnel();

        $dept1 = Department::create(['name' => 'D1', 'code' => 'D1-' . uniqid(), 'organization_id' => $this->org->id]);
        $dept2 = Department::create(['name' => 'D2', 'code' => 'D2-' . uniqid(), 'organization_id' => $this->org->id]);

        // Create initial group with dev1, dev2, pers1, pers2, dept1
        $group = AccessGroup::create([
            'name' => 'Sync Test Zone',
            'code' => 'ZONE-SYNCTEST-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach([$dev1->id, $dev2->id]);
        $group->personnel()->attach([$pers1->id, $pers2->id]);
        $group->departments()->attach([$dept1->id]);

        // Update group: replace devices with [dev2, dev3], personnel with [pers3], departments with [dept2]
        $response = $this->putJson("/api/access-groups/{$group->id}", [
            'device_ids' => [$dev2->id, $dev3->id],
            'personnel_ids' => [$pers3->id],
            'department_ids' => [$dept2->id],
        ]);

        $response->assertStatus(200);

        // Verify pivots in database
        $this->assertDatabaseHas('access_group_device', ['access_group_id' => $group->id, 'device_id' => $dev2->id]);
        $this->assertDatabaseHas('access_group_device', ['access_group_id' => $group->id, 'device_id' => $dev3->id]);
        $this->assertDatabaseMissing('access_group_device', ['access_group_id' => $group->id, 'device_id' => $dev1->id]);

        $this->assertDatabaseHas('access_group_personnel', ['access_group_id' => $group->id, 'personnel_id' => $pers3->id]);
        $this->assertDatabaseMissing('access_group_personnel', ['access_group_id' => $group->id, 'personnel_id' => $pers1->id]);
        $this->assertDatabaseMissing('access_group_personnel', ['access_group_id' => $group->id, 'personnel_id' => $pers2->id]);

        $this->assertDatabaseHas('access_group_department', ['access_group_id' => $group->id, 'department_id' => $dept2->id]);
        $this->assertDatabaseMissing('access_group_department', ['access_group_id' => $group->id, 'department_id' => $dept1->id]);
    }

    /**
     * Test Case 2.7: Deleting an access group cleans up pivots without deleting entities
     */
    public function test_destroy_deletes_group_and_pivots_without_affecting_entities(): void
    {
        $this->actingAsAdmin();

        $dev = $this->createDevice();
        $pers = $this->createPersonnel();
        $dept = Department::create(['name' => 'Preserved Dept', 'code' => 'PD-' . uniqid(), 'organization_id' => $this->org->id]);

        $group = AccessGroup::create([
            'name' => 'To Be Deleted',
            'code' => 'ZONE-DEL-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach($dev->id);
        $group->personnel()->attach($pers->id);
        $group->departments()->attach($dept->id);

        $response = $this->deleteJson("/api/access-groups/{$group->id}");
        $response->assertStatus(200);

        // Access group is gone
        $this->assertDatabaseMissing('access_groups', ['id' => $group->id]);

        // Pivots are cleaned up
        $this->assertDatabaseMissing('access_group_device', ['access_group_id' => $group->id]);
        $this->assertDatabaseMissing('access_group_personnel', ['access_group_id' => $group->id]);
        $this->assertDatabaseMissing('access_group_department', ['access_group_id' => $group->id]);

        // Underlying entities are intact
        $this->assertDatabaseHas('devices', ['id' => $dev->id]);
        $this->assertDatabaseHas('personnel', ['id' => $pers->id]);
        $this->assertDatabaseHas('departments', ['id' => $dept->id]);
    }

    /**
     * Test Case 2.8: Unauthenticated and unauthorized access control
     */
    public function test_auth_and_permission_enforcement(): void
    {
        auth()->forgetGuards();

        // 1. Unauthenticated -> 401
        $this->getJson('/api/access-groups')->assertStatus(401);
        $this->postJson('/api/access-groups', ['name' => 'X', 'code' => 'X'])->assertStatus(401);
        $this->postJson('/api/access-groups/1/sync-now')->assertStatus(401);

        // 2. Restricted user without required permissions -> 403
        $this->actingAsRestricted();
        $this->postJson('/api/access-groups', ['name' => 'X', 'code' => 'X'])->assertStatus(403);
        $this->putJson('/api/access-groups/1', ['name' => 'X'])->assertStatus(403);
        $this->deleteJson('/api/access-groups/1')->assertStatus(403);
        $this->postJson('/api/access-groups/1/sync-now')->assertStatus(403);
    }

    /**
     * Test Case 2.9: Index endpoint filters
     */
    public function test_index_filters(): void
    {
        $this->actingAsAdmin();

        $g1 = AccessGroup::create([
            'name' => 'Alpha Core Zone',
            'code' => 'ZONE-ALPHA-SEARCH',
            'description' => 'High security area',
            'is_active' => true,
            'organization_id' => $this->org->id,
        ]);

        $g2 = AccessGroup::create([
            'name' => 'Beta Logistics',
            'code' => 'ZONE-BETA-SEARCH',
            'description' => 'Warehouse area',
            'is_active' => false,
            'organization_id' => $this->org->id,
        ]);

        // Filter by is_active=false
        $resInactive = $this->getJson('/api/access-groups?is_active=false');
        $resInactive->assertStatus(200);
        $this->assertTrue(collect($resInactive->json('data'))->contains('id', $g2->id));
        $this->assertFalse(collect($resInactive->json('data'))->contains('id', $g1->id));

        // Filter by is_active=true
        $resActive = $this->getJson('/api/access-groups?is_active=true');
        $resActive->assertStatus(200);
        $this->assertTrue(collect($resActive->json('data'))->contains('id', $g1->id));
        $this->assertFalse(collect($resActive->json('data'))->contains('id', $g2->id));

        // Filter by organization_id
        $resOrg = $this->getJson("/api/access-groups?organization_id={$this->org->id}");
        $resOrg->assertStatus(200);
        $this->assertCount(2, $resOrg->json('data'));

        // Request all=true (unpaginated array)
        $resAll = $this->getJson('/api/access-groups?all=true');
        $resAll->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
    }

    /**
     * Test Case 2.10: Empirical probe of search parameter across database drivers
     * Demonstrates whether hardcoded 'ilike' in AccessGroupController succeeds or fails under current DB connection.
     */
    public function test_index_search_parameter_behavior_under_current_driver(): void
    {
        $this->actingAsAdmin();

        AccessGroup::create([
            'name' => 'Alpha Core Zone',
            'code' => 'ZONE-ALPHA-SEARCH',
            'description' => 'High security area',
            'is_active' => true,
            'organization_id' => $this->org->id,
        ]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            // Under SQLite, driver-aware matching succeeds with 200
            $res = $this->getJson('/api/access-groups?search=Alpha');
            $this->assertEquals(200, $res->status(), 'Driver-aware LIKE should succeed on SQLite');
            $this->assertCount(1, $res->json('data'));
        } else {
            $res = $this->getJson('/api/access-groups?search=Alpha');
            $res->assertStatus(200);
            $this->assertCount(1, $res->json('data'));
        }
    }

    /**
     * Test Case 2.11: Multi-level departmental hierarchy descendant resolution in zone resync
     * Parent Department attached to AccessGroup -> Grandchild department employees synced
     */
    public function test_hierarchical_department_descendants_in_zone_resync(): void
    {
        $this->actingAsAdmin();
        Queue::fake([SyncDevicePersonnelJob::class]);

        $parentDept = Department::create([
            'name' => 'Engineering Division',
            'code' => 'ENG-DIV-' . uniqid(),
            'organization_id' => $this->org->id,
        ]);

        $childDept = Department::create([
            'name' => 'Software Dept',
            'code' => 'SW-DEPT-' . uniqid(),
            'parent_id' => $parentDept->id,
            'organization_id' => $this->org->id,
        ]);

        $grandchildDept = Department::create([
            'name' => 'AI Robotics Team',
            'code' => 'AI-TEAM-' . uniqid(),
            'parent_id' => $childDept->id,
            'organization_id' => $this->org->id,
        ]);

        $device = $this->createDevice();

        $group = AccessGroup::create([
            'name' => 'Engineering Campus Zone',
            'code' => 'ZONE-ENG-CAMPUS-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach($device->id);
        $group->departments()->attach($parentDept->id);

        // Employee in grandchild department
        $personnel = $this->createPersonnel();
        Employee::create([
            'employee_code' => 'EMP-ROBOTICS-1',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'department_id' => $grandchildDept->id,
            'personnel_id' => $personnel->id,
            'employment_status' => 'active',
        ]);

        $response = $this->postJson("/api/access-groups/{$group->id}/sync-now");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'devices_count' => 1,
                'personnel_count' => 1,
                'dispatched_count' => 1,
            ]);

        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($job) use ($device, $personnel) {
            return $job->deviceId === $device->id
                && $job->personnelId === $personnel->id;
        });
    }

    /**
     * Test Case 2.12: Ancestor department access resolution for individual personnel
     * Employee in Grandchild department inherits access group assigned to Parent department
     */
    public function test_hierarchical_department_ancestors_in_authorized_devices(): void
    {
        $parentDept = Department::create([
            'name' => 'Operations Division',
            'code' => 'OPS-DIV-' . uniqid(),
            'organization_id' => $this->org->id,
        ]);

        $childDept = Department::create([
            'name' => 'Field Services',
            'code' => 'FIELD-SVC-' . uniqid(),
            'parent_id' => $parentDept->id,
            'organization_id' => $this->org->id,
        ]);

        $device = $this->createDevice();

        $group = AccessGroup::create([
            'name' => 'Operations Hub',
            'code' => 'ZONE-OPS-HUB-' . uniqid(),
            'is_active' => true,
        ]);
        $group->devices()->attach($device->id);
        $group->departments()->attach($parentDept->id);

        $personnel = $this->createPersonnel();
        $employee = Employee::create([
            'employee_code' => 'EMP-FIELD-1',
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'department_id' => $childDept->id,
            'personnel_id' => $personnel->id,
            'employment_status' => 'active',
        ]);

        $service = app(\App\Services\AccessControlService::class);
        $authorizedDevices = $service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertTrue($authorizedDevices->contains('id', $device->id), 'Grandchild employee should inherit ancestor department access group devices');
    }
}

