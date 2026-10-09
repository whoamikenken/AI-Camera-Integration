<?php

namespace Tests\Feature;

use App\Contracts\CameraGatewayInterface;
use App\Gateways\FakeCameraGateway;
use App\Jobs\BulkDeviceCampaignJob;
use App\Jobs\BulkPersonnelSyncJob;
use App\Models\BulkCampaign;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\SyncTask;
use App\Models\User;
use App\Services\CameraMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialMilestone4Challenger1Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;
    protected Organization $org;
    protected FakeCameraGateway $fakeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway = FakeCameraGateway::fake();

        $this->org = Organization::create([
            'name' => 'Empirical Challenger Corp',
            'code' => 'ECC-CORP',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        $employeeRole = Role::firstOrCreate(
            ['slug' => 'employee'],
            ['name' => 'Standard Employee', 'is_system' => true]
        );

        $permDevicesView = Permission::firstOrCreate(['slug' => 'devices.view'], ['name' => 'View Devices', 'group' => 'devices']);
        $permDevicesManage = Permission::firstOrCreate(['slug' => 'devices.manage'], ['name' => 'Manage Devices', 'group' => 'devices']);
        $permPersonnelView = Permission::firstOrCreate(['slug' => 'personnel.view'], ['name' => 'View Personnel', 'group' => 'personnel']);
        $permPersonnelSync = Permission::firstOrCreate(['slug' => 'personnel.sync'], ['name' => 'Sync Personnel', 'group' => 'personnel']);
        $permPersonnelDelete = Permission::firstOrCreate(['slug' => 'personnel.delete'], ['name' => 'Delete Personnel', 'group' => 'personnel']);

        $adminRole->permissions()->sync([
            $permDevicesView->id,
            $permDevicesManage->id,
            $permPersonnelView->id,
            $permPersonnelSync->id,
            $permPersonnelDelete->id,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->regularUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->regularUser->roles()->sync([$employeeRole->id]);
    }

    /**
     * INVARIANT CHALLENGE 1:
     * Mathematical array partitioning across boundary conditions.
     */
    public function test_invariant_50_person_chunk_partitioning_boundaries(): void
    {
        // 0 items -> 0 chunks
        $chunks0 = array_chunk([], 50);
        $this->assertCount(0, $chunks0);

        // 1 item -> 1 chunk of [1]
        $chunks1 = array_chunk(range(1, 1), 50);
        $this->assertCount(1, $chunks1);
        $this->assertCount(1, $chunks1[0]);

        // 49 items -> 1 chunk of [49]
        $chunks49 = array_chunk(range(1, 49), 50);
        $this->assertCount(1, $chunks49);
        $this->assertCount(49, $chunks49[0]);

        // 50 items -> 1 chunk of [50]
        $chunks50 = array_chunk(range(1, 50), 50);
        $this->assertCount(1, $chunks50);
        $this->assertCount(50, $chunks50[0]);

        // 51 items -> 2 chunks: [50, 1]
        $chunks51 = array_chunk(range(1, 51), 50);
        $this->assertCount(2, $chunks51);
        $this->assertCount(50, $chunks51[0]);
        $this->assertCount(1, $chunks51[1]);

        // 99 items -> 2 chunks: [50, 49]
        $chunks99 = array_chunk(range(1, 99), 50);
        $this->assertCount(2, $chunks99);
        $this->assertCount(50, $chunks99[0]);
        $this->assertCount(49, $chunks99[1]);

        // 100 items -> 2 chunks: [50, 50]
        $chunks100 = array_chunk(range(1, 100), 50);
        $this->assertCount(2, $chunks100);
        $this->assertCount(50, $chunks100[0]);
        $this->assertCount(50, $chunks100[1]);

        // 101 items -> 3 chunks: [50, 50, 1]
        $chunks101 = array_chunk(range(1, 101), 50);
        $this->assertCount(3, $chunks101);
        $this->assertCount(50, $chunks101[0]);
        $this->assertCount(50, $chunks101[1]);
        $this->assertCount(1, $chunks101[2]);

        // 120 items -> 3 chunks: [50, 50, 20]
        $chunks120 = array_chunk(range(1, 120), 50);
        $this->assertCount(3, $chunks120);
        $this->assertCount(50, $chunks120[0]);
        $this->assertCount(50, $chunks120[1]);
        $this->assertCount(20, $chunks120[2]);

        // 150 items -> 3 chunks: [50, 50, 50]
        $chunks150 = array_chunk(range(1, 150), 50);
        $this->assertCount(3, $chunks150);
        $this->assertCount(50, $chunks150[0]);
        $this->assertCount(50, $chunks150[1]);
        $this->assertCount(50, $chunks150[2]);

        // 250 items -> 5 chunks of 50
        $chunks250 = array_chunk(range(1, 250), 50);
        $this->assertCount(5, $chunks250);
        foreach ($chunks250 as $chunk) {
            $this->assertCount(50, $chunk);
        }
    }

    /**
     * EMPIRICAL CHALLENGE 1.1:
     * BulkPersonnelSyncJob real execution with 51 items dispatches exactly 2 chunks [50, 1] to hardware gateway.
     */
    public function test_empirical_bulk_sync_job_with_51_personnel_dispatches_two_chunks(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-CHALLENGE-51',
            'name' => '51 Boundary Test Camera',
            'ip_address' => '192.168.1.151',
            'is_active' => true,
        ]);

        $personnelIds = [];
        for ($i = 1; $i <= 51; $i++) {
            $p = Personnel::create([
                'name' => "Worker {$i}",
                'customize_id' => 7000 + $i,
                'person_type' => 0,
            ]);
            $personnelIds[] = $p->id;
        }

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 51,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => $personnelIds, 'device_id' => $device->id],
        ]);

        SyncTask::truncate();
        $this->fakeGateway->reset();

        $job = new BulkPersonnelSyncJob($personnelIds, $campaign->id, 'sync', $device->id);
        $job->handle(app(CameraMqttService::class));

        // Verify gateway calls
        $dispatchedAddPersons = $this->fakeGateway->dispatched('AddPersons');
        $this->assertCount(2, $dispatchedAddPersons, 'Must dispatch exactly 2 AddPersons packets for 51 items');

        $firstPacket = $dispatchedAddPersons[0]['info'];
        $this->assertEquals(50, $firstPacket['Total']);
        $this->assertEquals(50, $firstPacket['PersonNum']);
        $this->assertArrayHasKey('Personinfo_0', $firstPacket);
        $this->assertArrayHasKey('Personinfo_49', $firstPacket);
        $this->assertArrayNotHasKey('Personinfo_50', $firstPacket);

        $secondPacket = $dispatchedAddPersons[1]['info'];
        $this->assertEquals(1, $secondPacket['Total']);
        $this->assertEquals(1, $secondPacket['PersonNum']);
        $this->assertArrayHasKey('Personinfo_0', $secondPacket);
        $this->assertArrayNotHasKey('Personinfo_1', $secondPacket);

        // Verify campaign final status
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(51, $campaign->processed_items);
        $this->assertEquals(0, $campaign->failed_items);
        $this->assertEquals(100, $campaign->progress_percent);

        // Verify SyncTask table records
        $this->assertEquals(51, SyncTask::where('device_id', $device->device_id)->where('status', 'COMPLETED')->count());
    }

    /**
     * EMPIRICAL CHALLENGE 1.2:
     * BulkPersonnelSyncJob with 120 items dispatches exactly 3 chunks [50, 50, 20] to hardware gateway.
     */
    public function test_empirical_bulk_sync_job_with_120_personnel_dispatches_three_chunks(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-CHALLENGE-120',
            'name' => '120 Workforce Test Camera',
            'ip_address' => '192.168.1.120',
            'is_active' => true,
        ]);

        $personnelIds = [];
        for ($i = 1; $i <= 120; $i++) {
            $p = Personnel::create([
                'name' => "Workforce {$i}",
                'customize_id' => 8000 + $i,
                'person_type' => 0,
            ]);
            $personnelIds[] = $p->id;
        }

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 120,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => $personnelIds, 'device_id' => $device->id],
        ]);

        SyncTask::truncate();
        $this->fakeGateway->reset();

        $job = new BulkPersonnelSyncJob($personnelIds, $campaign->id, 'sync', $device->id);
        $job->handle(app(CameraMqttService::class));

        // Verify gateway calls
        $dispatchedAddPersons = $this->fakeGateway->dispatched('AddPersons');
        $this->assertCount(3, $dispatchedAddPersons, 'Must dispatch exactly 3 AddPersons packets for 120 items');

        $this->assertEquals(50, $dispatchedAddPersons[0]['info']['Total']);
        $this->assertEquals(50, $dispatchedAddPersons[1]['info']['Total']);
        $this->assertEquals(20, $dispatchedAddPersons[2]['info']['Total']);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(120, $campaign->processed_items);
        $this->assertEquals(100, $campaign->progress_percent);
        $this->assertEquals(120, SyncTask::where('device_id', $device->device_id)->count());
    }

    /**
     * EMPIRICAL CHALLENGE 1.3:
     * Exact 50 items dispatches exactly 1 chunk of 50.
     */
    public function test_empirical_bulk_sync_job_with_exact_50_personnel_dispatches_one_chunk(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-CHALLENGE-50',
            'name' => '50 Boundary Test Camera',
            'ip_address' => '192.168.1.150',
            'is_active' => true,
        ]);

        $personnelIds = [];
        for ($i = 1; $i <= 50; $i++) {
            $p = Personnel::create([
                'name' => "Exact50 {$i}",
                'customize_id' => 9000 + $i,
                'person_type' => 0,
            ]);
            $personnelIds[] = $p->id;
        }

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 50,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => $personnelIds, 'device_id' => $device->id],
        ]);

        SyncTask::truncate();
        $this->fakeGateway->reset();

        $job = new BulkPersonnelSyncJob($personnelIds, $campaign->id, 'sync', $device->id);
        $job->handle(app(CameraMqttService::class));

        $dispatchedAddPersons = $this->fakeGateway->dispatched('AddPersons');
        $this->assertCount(1, $dispatchedAddPersons);
        $this->assertEquals(50, $dispatchedAddPersons[0]['info']['Total']);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(50, $campaign->processed_items);
        $this->assertEquals(100, $campaign->progress_percent);
    }

    /**
     * EMPIRICAL CHALLENGE 1.4:
     * Multi-device chunking: 51 personnel across 2 active devices dispatches 2 chunks per device (total 4).
     */
    public function test_empirical_bulk_sync_job_multi_device_distribution_and_chunking(): void
    {
        $dev1 = Device::create([
            'device_id' => 'CAM-MULTI-1',
            'name' => 'Multi Camera 1',
            'ip_address' => '192.168.1.201',
            'is_active' => true,
        ]);
        $dev2 = Device::create([
            'device_id' => 'CAM-MULTI-2',
            'name' => 'Multi Camera 2',
            'ip_address' => '192.168.1.202',
            'is_active' => true,
        ]);

        $personnelIds = [];
        for ($i = 1; $i <= 51; $i++) {
            $p = Personnel::create([
                'name' => "MultiUser {$i}",
                'customize_id' => 9500 + $i,
                'person_type' => 0,
            ]);
            $personnelIds[] = $p->id;
        }

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 51,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => $personnelIds],
        ]);

        SyncTask::truncate();
        $this->fakeGateway->reset();

        // targetDeviceId is null: broadcasts to all authorized active devices (dev1 and dev2)
        $job = new BulkPersonnelSyncJob($personnelIds, $campaign->id, 'sync', null);
        $job->handle(app(CameraMqttService::class));

        $dispatches = $this->fakeGateway->dispatched('AddPersons');
        $this->assertCount(4, $dispatches, 'Each of the 2 devices must receive 2 chunks [50, 1]');

        $dev1Calls = $dispatches->filter(fn($d) => $d['device_id'] === $dev1->device_id)->values();
        $dev2Calls = $dispatches->filter(fn($d) => $d['device_id'] === $dev2->device_id)->values();

        $this->assertCount(2, $dev1Calls);
        $this->assertCount(2, $dev2Calls);

        $this->assertEquals(50, $dev1Calls[0]['info']['Total']);
        $this->assertEquals(1, $dev1Calls[1]['info']['Total']);

        $this->assertEquals(50, $dev2Calls[0]['info']['Total']);
        $this->assertEquals(1, $dev2Calls[1]['info']['Total']);

        $this->assertEquals(102, SyncTask::count());
    }

    /**
     * EMPIRICAL CHALLENGE 2:
     * Empty input rejection invariant (strictly HTTP 422).
     */
    public function test_empty_input_rejection_invariant_across_bulk_endpoints(): void
    {
        Sanctum::actingAs($this->adminUser);

        // 1. POST /api/devices/bulk-reboot with device_ids: [] -> strictly HTTP 422
        $resRebootEmpty = $this->postJson('/api/devices/bulk-reboot', ['device_ids' => []]);
        $resRebootEmpty->assertStatus(422);
        $resRebootEmpty->assertJsonValidationErrors(['device_ids']);

        // 2. POST /api/devices/bulk-reboot without device_ids field -> HTTP 422
        $resRebootMissing = $this->postJson('/api/devices/bulk-reboot', []);
        $resRebootMissing->assertStatus(422);
        $resRebootMissing->assertJsonValidationErrors(['device_ids']);

        // 3. POST /api/devices/bulk-reboot with non-array string -> HTTP 422
        $resRebootString = $this->postJson('/api/devices/bulk-reboot', ['device_ids' => '1,2,3']);
        $resRebootString->assertStatus(422);

        // 4. POST /api/devices/bulk-reboot with non-existent device ID -> HTTP 422
        $resRebootNonExistent = $this->postJson('/api/devices/bulk-reboot', ['device_ids' => [999999]]);
        $resRebootNonExistent->assertStatus(422);

        // 5. POST /api/devices/bulk-sync-mqtt with device_ids: [] -> strictly HTTP 422
        $resMqttEmpty = $this->postJson('/api/devices/bulk-sync-mqtt', [
            'device_ids' => [],
            'mqtt_config' => ['MQTopic' => 'mqtt/face/test'],
        ]);
        $resMqttEmpty->assertStatus(422);
        $resMqttEmpty->assertJsonValidationErrors(['device_ids']);

        // 6. POST /api/devices/bulk-sync-mqtt without mqtt_config -> HTTP 422
        $dev = Device::create([
            'device_id' => 'CAM-VALID-1',
            'name' => 'Valid Device',
            'ip_address' => '192.168.1.10',
            'is_active' => true,
        ]);
        $resMqttNoConfig = $this->postJson('/api/devices/bulk-sync-mqtt', [
            'device_ids' => [$dev->id],
        ]);
        $resMqttNoConfig->assertStatus(422);
        $resMqttNoConfig->assertJsonValidationErrors(['mqtt_config']);

        // 7. POST /api/personnel/bulk-delete with personnel_ids: [] -> strictly HTTP 422
        $resDeleteEmpty = $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => []]);
        $resDeleteEmpty->assertStatus(422);
        $resDeleteEmpty->assertJsonValidationErrors(['personnel_ids']);

        // 8. POST /api/personnel/bulk-delete with missing personnel_ids -> HTTP 422
        $resDeleteMissing = $this->postJson('/api/personnel/bulk-delete', []);
        $resDeleteMissing->assertStatus(422);
        $resDeleteMissing->assertJsonValidationErrors(['personnel_ids']);

        // 9. POST /api/personnel/bulk-delete with non-existent IDs -> HTTP 422
        $resDeleteNonExistent = $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => [999999]]);
        $resDeleteNonExistent->assertStatus(422);

        // 10. POST /api/personnel/bulk-sync with personnel_ids: [] -> strictly HTTP 422
        $resSyncEmpty = $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => []]);
        $resSyncEmpty->assertStatus(422);
        $resSyncEmpty->assertJsonValidationErrors(['personnel_ids']);

        // 11. POST /api/personnel/bulk-sync with missing personnel_ids -> HTTP 422
        $resSyncMissing = $this->postJson('/api/personnel/bulk-sync', []);
        $resSyncMissing->assertStatus(422);
        $resSyncMissing->assertJsonValidationErrors(['personnel_ids']);

        // 12. POST /api/personnel/bulk-sync with non-existent personnel_ids -> HTTP 422
        $resSyncNonExistent = $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => [999999]]);
        $resSyncNonExistent->assertStatus(422);
    }

    /**
     * EMPIRICAL CHALLENGE 3:
     * Progress calculation clamping invariant and zero division prevention.
     */
    public function test_progress_calculation_clamping_and_zero_division_prevention(): void
    {
        // 1. total_items = 0 -> strictly 0% (no division by zero)
        $cZero = new BulkCampaign(['total_items' => 0, 'processed_items' => 0]);
        $this->assertSame(0, $cZero->progressPercent());
        $this->assertSame(0, $cZero->progress_percent);

        // 2. total_items = 0, processed_items = 5 -> strictly 0%
        $cZeroWithProcessed = new BulkCampaign(['total_items' => 0, 'processed_items' => 5]);
        $this->assertSame(0, $cZeroWithProcessed->progressPercent());
        $this->assertSame(0, $cZeroWithProcessed->progress_percent);

        // 3. negative total_items -> strictly 0%
        $cNegativeTotal = new BulkCampaign(['total_items' => -10, 'processed_items' => 5]);
        $this->assertSame(0, $cNegativeTotal->progressPercent());
        $this->assertSame(0, $cNegativeTotal->progress_percent);

        // 4. processed_items = 0, total_items = 10 -> strictly 0%
        $c1 = new BulkCampaign(['total_items' => 10, 'processed_items' => 0]);
        $this->assertSame(0, $c1->progressPercent());
        $this->assertSame(0, $c1->progress_percent);

        // 5. processed_items = 5, total_items = 10 -> strictly 50%
        $c2 = new BulkCampaign(['total_items' => 10, 'processed_items' => 5]);
        $this->assertSame(50, $c2->progressPercent());
        $this->assertSame(50, $c2->progress_percent);

        // 6. processed_items = 10, total_items = 10 -> strictly 100%
        $c3 = new BulkCampaign(['total_items' => 10, 'processed_items' => 10]);
        $this->assertSame(100, $c3->progressPercent());
        $this->assertSame(100, $c3->progress_percent);

        // 7. processed_items = 15, total_items = 10 -> strictly clamped to 100%
        $cOverflow = new BulkCampaign(['total_items' => 10, 'processed_items' => 15]);
        $this->assertSame(100, $cOverflow->progressPercent());
        $this->assertSame(100, $cOverflow->progress_percent);

        // 8. processed_items = -5, total_items = 10 -> strictly clamped to 0%
        $cUnderflow = new BulkCampaign(['total_items' => 10, 'processed_items' => -5]);
        $this->assertSame(0, $cUnderflow->progressPercent());
        $this->assertSame(0, $cUnderflow->progress_percent);

        // 9. Rounding checks: 1/3 = 33%, 2/3 = 67%
        $cThird = new BulkCampaign(['total_items' => 3, 'processed_items' => 1]);
        $this->assertSame(33, $cThird->progressPercent());
        $cTwoThirds = new BulkCampaign(['total_items' => 3, 'processed_items' => 2]);
        $this->assertSame(67, $cTwoThirds->progressPercent());

        // 10. API endpoint serialization verification
        Sanctum::actingAs($this->adminUser);
        $savedCampaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 20,
            'processed_items' => 15,
            'failed_items' => 0,
            'status' => 'processing',
        ]);

        $res = $this->getJson("/api/bulk-campaigns/{$savedCampaign->id}");
        $res->assertStatus(200);
        $res->assertJsonFragment(['progress_percent' => 75]);
    }

    /**
     * EMPIRICAL CHALLENGE 4:
     * State machine transitions across partial, completed, and failed execution paths.
     */
    public function test_bulk_campaign_state_machine_transitions(): void
    {
        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 10,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
        ]);

        // 1. Transition to processing
        $campaign->markProcessing();
        $this->assertEquals('processing', $campaign->fresh()->status);

        // 2. Increment processed
        $campaign->incrementProcessed(4);
        $this->assertEquals(4, $campaign->fresh()->processed_items);

        // 3. Increment failed with error message
        $campaign->incrementFailed(2, 'Timeout on camera 3');
        $this->assertEquals(2, $campaign->fresh()->failed_items);
        $this->assertStringContainsString('Timeout on camera 3', $campaign->fresh()->error_summary);

        // 4. markCompleted() with both processed > 0 and failed > 0 -> 'partial'
        $campaign->markCompleted();
        $this->assertEquals('partial', $campaign->fresh()->status);

        // 5. Campaign where all items fail -> 'failed'
        $allFailed = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 5,
            'processed_items' => 0,
            'failed_items' => 5,
            'status' => 'processing',
        ]);
        $allFailed->markCompleted();
        $this->assertEquals('failed', $allFailed->fresh()->status);

        // 6. Campaign where all items succeed -> 'completed'
        $allSuccess = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 5,
            'processed_items' => 5,
            'failed_items' => 0,
            'status' => 'processing',
        ]);
        $allSuccess->markCompleted();
        $this->assertEquals('completed', $allSuccess->fresh()->status);

        // 7. markFailed directly
        $fatalCamp = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 5,
            'status' => 'processing',
        ]);
        $fatalCamp->markFailed('Hardware network partition');
        $this->assertEquals('failed', $fatalCamp->fresh()->status);
        $this->assertStringContainsString('Hardware network partition', $fatalCamp->fresh()->error_summary);
    }

    /**
     * EMPIRICAL CHALLENGE 5:
     * Bulk deletion executes edge camera de-provisioning and deletes DB records atomically.
     */
    public function test_empirical_bulk_personnel_delete_job_execution(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-DEL-TEST',
            'name' => 'Deletion Test Camera',
            'ip_address' => '192.168.1.130',
            'is_active' => true,
        ]);

        $p1 = Personnel::create(['name' => 'Del Person 1', 'customize_id' => 1001, 'person_type' => 0]);
        $p2 = Personnel::create(['name' => 'Del Person 2', 'customize_id' => 1002, 'person_type' => 0]);
        $p3 = Personnel::create(['name' => 'Del Person 3', 'customize_id' => 1003, 'person_type' => 0]);

        $ids = [$p1->id, $p2->id, $p3->id];

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'delete_personnel',
            'total_items' => 3,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => $ids],
        ]);

        $job = new BulkPersonnelSyncJob($ids, $campaign->id, 'delete', $device->id);
        $job->handle(app(CameraMqttService::class));

        // 1. Gateway received DeletePersons call
        $delCalls = $this->fakeGateway->dispatched('DeletePersons');
        $this->assertCount(1, $delCalls);
        $this->assertEquals(3, $delCalls[0]['info']['PersonNum']);
        $this->assertEquals(['1001', '1002', '1003'], $delCalls[0]['info']['customId']);

        // 2. DB records removed
        $this->assertDatabaseMissing('personnel', ['id' => $p1->id]);
        $this->assertDatabaseMissing('personnel', ['id' => $p2->id]);
        $this->assertDatabaseMissing('personnel', ['id' => $p3->id]);

        // 3. Campaign completed
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(3, $campaign->processed_items);
        $this->assertEquals(100, $campaign->progress_percent);
    }

    /**
     * EMPIRICAL CHALLENGE 6:
     * BulkDeviceCampaignJob reboot fleet handling active and inactive devices properly.
     */
    public function test_empirical_bulk_fleet_reboot_job_with_inactive_devices(): void
    {
        $activeDev1 = Device::create([
            'device_id' => 'CAM-REBOOT-1',
            'name' => 'Active Reboot 1',
            'ip_address' => '192.168.1.141',
            'is_active' => true,
        ]);
        $activeDev2 = Device::create([
            'device_id' => 'CAM-REBOOT-2',
            'name' => 'Active Reboot 2',
            'ip_address' => '192.168.1.142',
            'is_active' => true,
        ]);
        $inactiveDev = Device::create([
            'device_id' => 'CAM-REBOOT-3',
            'name' => 'Inactive Reboot 3',
            'ip_address' => '192.168.1.143',
            'is_active' => false,
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 3,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['device_ids' => [$activeDev1->id, $activeDev2->id, $inactiveDev->id]],
        ]);

        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle(app(CameraMqttService::class));

        // Active devices received RebootDevice
        $reboots = $this->fakeGateway->dispatched('RebootDevice');
        $this->assertCount(2, $reboots);

        // Inactive device recorded failure
        $campaign->refresh();
        $this->assertEquals('partial', $campaign->status);
        $this->assertEquals(2, $campaign->processed_items);
        $this->assertEquals(1, $campaign->failed_items);
        $this->assertStringContainsString('is inactive', $campaign->error_summary);
    }

    /**
     * EMPIRICAL CHALLENGE 7:
     * BulkDeviceCampaignJob update MQTT config updates database topic and dispatches UpMQTTconfig.
     */
    public function test_empirical_bulk_fleet_mqtt_sync_job_execution(): void
    {
        $dev1 = Device::create([
            'device_id' => 'CAM-MQTT-1',
            'name' => 'MQTT Cam 1',
            'ip_address' => '192.168.1.161',
            'mqtt_topic' => 'mqtt/face/old1',
            'is_active' => true,
        ]);
        $dev2 = Device::create([
            'device_id' => 'CAM-MQTT-2',
            'name' => 'MQTT Cam 2',
            'ip_address' => '192.168.1.162',
            'mqtt_topic' => 'mqtt/face/old2',
            'is_active' => true,
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'update_mqtt_config',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$dev1->id, $dev2->id],
                'mqtt_config' => [
                    'MQTopic' => 'mqtt/face/fleet-unified',
                    'KeepAliveInterval' => 45,
                ],
            ],
        ]);

        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle(app(CameraMqttService::class));

        $mqttCalls = $this->fakeGateway->dispatched('UpMQTTconfig');
        $this->assertCount(2, $mqttCalls);
        $this->assertEquals('mqtt/face/fleet-unified', $mqttCalls[0]['info']['MQTopic']);
        $this->assertEquals(45, $mqttCalls[0]['info']['KeepAliveInterval']);

        // Devices DB topic updated
        $this->assertEquals('mqtt/face/fleet-unified', $dev1->fresh()->mqtt_topic);
        $this->assertEquals('mqtt/face/fleet-unified', $dev2->fresh()->mqtt_topic);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(2, $campaign->processed_items);
        $this->assertEquals(100, $campaign->progress_percent);
    }

    /**
     * EMPIRICAL CHALLENGE 8:
     * BulkCampaignController index filtering by campaign_type and status.
     */
    public function test_bulk_campaigns_index_filtering_and_pagination(): void
    {
        Sanctum::actingAs($this->adminUser);

        BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 5,
            'status' => 'completed',
        ]);
        BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 3,
            'status' => 'failed',
        ]);
        BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 50,
            'status' => 'completed',
        ]);

        // Filter by campaign_type
        $resType = $this->getJson('/api/bulk-campaigns?campaign_type=reboot_fleet');
        $resType->assertStatus(200);
        $this->assertEquals(2, $resType->json('total'));

        // Filter by status
        $resStatus = $this->getJson('/api/bulk-campaigns?status=failed');
        $resStatus->assertStatus(200);
        $this->assertEquals(1, $resStatus->json('total'));

        // Filter by both
        $resBoth = $this->getJson('/api/bulk-campaigns?campaign_type=reboot_fleet&status=completed');
        $resBoth->assertStatus(200);
        $this->assertEquals(1, $resBoth->json('total'));
    }

    /**
     * EMPIRICAL CHALLENGE 9:
     * Access Control Group segmentation during Bulk Personnel Sync.
     * Ensures personnel in Group 1 are sent ONLY to Device 1, and Group 2 ONLY to Device 2.
     */
    public function test_empirical_bulk_sync_with_access_control_groups_segmentation(): void
    {
        $dev1 = Device::create([
            'device_id' => 'CAM-ZONE-A',
            'name' => 'Zone A Camera',
            'ip_address' => '192.168.1.181',
            'is_active' => true,
        ]);
        $dev2 = Device::create([
            'device_id' => 'CAM-ZONE-B',
            'name' => 'Zone B Camera',
            'ip_address' => '192.168.1.182',
            'is_active' => true,
        ]);

        $groupA = \App\Models\AccessGroup::create([
            'organization_id' => $this->org->id,
            'name' => 'Zone A Access',
            'code' => 'ZONE-A',
            'is_active' => true,
        ]);
        $groupA->devices()->attach($dev1->id);

        $groupB = \App\Models\AccessGroup::create([
            'organization_id' => $this->org->id,
            'name' => 'Zone B Access',
            'code' => 'ZONE-B',
            'is_active' => true,
        ]);
        $groupB->devices()->attach($dev2->id);

        $personA = Personnel::create(['name' => 'Alice Zone A', 'customize_id' => 3001, 'person_type' => 0]);
        $personB = Personnel::create(['name' => 'Bob Zone B', 'customize_id' => 3002, 'person_type' => 0]);

        $groupA->personnel()->attach($personA->id);
        $groupB->personnel()->attach($personB->id);

        SyncTask::truncate();
        $this->fakeGateway->reset();

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => [$personA->id, $personB->id]],
        ]);

        $job = new BulkPersonnelSyncJob([$personA->id, $personB->id], $campaign->id, 'sync', null);
        $job->handle(app(CameraMqttService::class));

        $dispatches = $this->fakeGateway->dispatched('AddPersons');
        $this->assertCount(2, $dispatches);

        $dev1Calls = $dispatches->filter(fn($d) => $d['device_id'] === $dev1->device_id)->values();
        $dev2Calls = $dispatches->filter(fn($d) => $d['device_id'] === $dev2->device_id)->values();

        $this->assertCount(1, $dev1Calls);
        $this->assertCount(1, $dev2Calls);

        // Device 1 received ONLY Alice (customize_id 3001)
        $this->assertEquals('3001', $dev1Calls[0]['info']['Personinfo_0']['customId']);

        // Device 2 received ONLY Bob (customize_id 3002)
        $this->assertEquals('3002', $dev2Calls[0]['info']['Personinfo_0']['customId']);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(2, $campaign->processed_items);
    }

    /**
     * EMPIRICAL CHALLENGE 10:
     * Hardware failure resilience during bulk personnel sync.
     * When hardware returns success: false, campaign records partial/failed and creates FAILED SyncTasks.
     */
    public function test_empirical_bulk_sync_job_handles_hardware_failure(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-FAIL-TEST',
            'name' => 'Failing Hardware Camera',
            'ip_address' => '192.168.1.199',
            'is_active' => true,
        ]);

        $person = Personnel::create(['name' => 'Failing Sync Worker', 'customize_id' => 4001, 'person_type' => 0]);

        $this->fakeGateway->setResponses([
            'AddPersons' => [
                'success' => false,
                'code' => 500,
                'error' => 'Storage full: flash memory exhausted',
                'data' => null,
            ],
        ]);

        SyncTask::truncate();

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 1,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => [$person->id], 'device_id' => $device->id],
        ]);

        $job = new BulkPersonnelSyncJob([$person->id], $campaign->id, 'sync', $device->id);
        $job->handle(app(CameraMqttService::class));

        $campaign->refresh();
        $this->assertEquals('failed', $campaign->status);
        $this->assertEquals(0, $campaign->processed_items);
        $this->assertEquals(1, $campaign->failed_items);

        $failedTask = SyncTask::where('device_id', $device->device_id)->first();
        $this->assertNotNull($failedTask);
        $this->assertEquals('FAILED', $failedTask->status);
        $this->assertStringContainsString('Storage full', $failedTask->error_message);
    }

    /**
     * EMPIRICAL CHALLENGE 11:
     * Hardware exception resilience during bulk personnel sync.
     * When gateway throws unexpected exception, job catches it and records failure without crashing.
     */
    public function test_empirical_bulk_sync_job_handles_unhandled_exception(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-EX-TEST',
            'name' => 'Exception Hardware Camera',
            'ip_address' => '192.168.1.198',
            'is_active' => true,
        ]);

        $person = Personnel::create(['name' => 'Exception Worker', 'customize_id' => 4002, 'person_type' => 0]);

        $this->fakeGateway->setResponses([
            'AddPersons' => function() {
                throw new \RuntimeException('Fatal broker TCP socket breakdown');
            },
        ]);

        SyncTask::truncate();

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 1,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => [$person->id], 'device_id' => $device->id],
        ]);

        $job = new BulkPersonnelSyncJob([$person->id], $campaign->id, 'sync', $device->id);
        $job->handle(app(CameraMqttService::class));

        $campaign->refresh();
        $this->assertEquals('failed', $campaign->status);
        $this->assertEquals(1, $campaign->failed_items);
    }

    /**
     * EMPIRICAL CHALLENGE 12:
     * RBAC permission enforcement and unauthenticated request rejection.
     */
    public function test_empirical_bulk_endpoints_rbac_and_unauthenticated_guards(): void
    {
        auth()->forgetGuards();

        // 1. Unauthenticated requests must strictly return HTTP 401
        $this->postJson('/api/devices/bulk-reboot', ['device_ids' => [1]])->assertStatus(401);
        $this->postJson('/api/devices/bulk-sync-mqtt', ['device_ids' => [1], 'mqtt_config' => []])->assertStatus(401);
        $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => [1]])->assertStatus(401);
        $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => [1]])->assertStatus(401);
        $this->getJson('/api/bulk-campaigns')->assertStatus(401);
        $this->getJson('/api/bulk-campaigns/1')->assertStatus(401);

        // 2. Authenticated user without required permissions must strictly return HTTP 403
        Sanctum::actingAs($this->regularUser);
        $this->postJson('/api/devices/bulk-reboot', ['device_ids' => [1]])->assertStatus(403);
        $this->postJson('/api/devices/bulk-sync-mqtt', ['device_ids' => [1], 'mqtt_config' => ['test' => 1]])->assertStatus(403);
        $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => [1]])->assertStatus(403);
        $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => [1]])->assertStatus(403);
        $this->getJson('/api/bulk-campaigns')->assertStatus(403);
        $this->getJson('/api/bulk-campaigns/1')->assertStatus(403);

        // 3. 404 for non-existent campaign
        Sanctum::actingAs($this->adminUser);
        $this->getJson('/api/bulk-campaigns/999999')->assertStatus(404);
    }

    /**
     * EMPIRICAL CHALLENGE 13:
     * Empty payload handling in BulkDeviceCampaignJob and BulkPersonnelSyncJob.
     */
    public function test_empirical_empty_payload_jobs_complete_gracefully(): void
    {
        // 1. BulkDeviceCampaignJob with empty device_ids
        $campDev = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 0,
            'status' => 'pending',
            'payload' => ['device_ids' => []],
        ]);
        $devJob = new BulkDeviceCampaignJob($campDev->id);
        $devJob->handle(app(CameraMqttService::class));
        $this->assertEquals('completed', $campDev->fresh()->status);

        // 2. BulkPersonnelSyncJob with empty personnelIds
        $campPers = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => []],
        ]);
        $persJob = new BulkPersonnelSyncJob([], $campPers->id, 'sync');
        $persJob->handle(app(CameraMqttService::class));
        $this->assertEquals('completed', $campPers->fresh()->status);
    }
}
