<?php

namespace Tests\Feature;

use App\Contracts\CameraGatewayInterface;
use App\Gateways\CameraGateway;
use App\Gateways\FakeCameraGateway;
use App\Jobs\BulkDeviceCampaignJob;
use App\Jobs\BulkPersonnelSyncJob;
use App\Models\AccessGroup;
use App\Models\BulkCampaign;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\SyncTask;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\CameraMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adversarial Empirical Verification Suite for Milestone M4
 *
 * Challenges:
 * 1. Offline & Inactive Device Resilience (BulkDeviceCampaignJob)
 * 2. Access Control Scoping on Bulk Sync (BulkPersonnelSyncJob)
 * 3. Audit Trail Verification (SyncTask records per person/device)
 * 4. Boundary, Concurrency & Cross-Feature Edge Cases
 */
class AdversarialMilestone4Challenger2Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $unauthorizedUser;
    protected Organization $org;
    protected FakeCameraGateway $fakeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $employeeRole = Role::firstOrCreate(['slug' => 'employee'], [
            'name' => 'Regular Employee',
            'is_system' => false,
        ]);

        $this->org = Organization::create([
            'name' => 'Adversarial Test Org',
            'code' => 'ADV-M4-ORG',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->unauthorizedUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->unauthorizedUser->roles()->sync([$employeeRole->id]);

        $this->fakeGateway = CameraGateway::fake();
    }

    protected function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    protected function actingAsUnauthorized(): void
    {
        Sanctum::actingAs($this->unauthorizedUser, ['*']);
    }

    // =========================================================================
    // SECTION 1: Offline & Inactive Device Resilience (BulkDeviceCampaignJob)
    // =========================================================================

    /**
     * Challenge 1.1: Inactive device in BulkDeviceCampaignJob must fail gracefully
     * without aborting or crashing the remaining active devices in the campaign.
     */
    public function test_bulk_device_campaign_gracefully_handles_inactive_device_without_crashing(): void
    {
        $devActive1 = Device::factory()->online()->create([
            'name' => 'Active Gate 1',
            'is_active' => true,
        ]);
        $devInactive = Device::factory()->create([
            'name' => 'Broken Turnstile',
            'is_active' => false,
        ]);
        $devActive2 = Device::factory()->online()->create([
            'name' => 'Active Gate 2',
            'is_active' => true,
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 3,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$devActive1->id, $devInactive->id, $devActive2->id],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle($cameraService);

        $campaign->refresh();

        // Must succeed for the 2 active devices and record 1 failure for the inactive one
        $this->assertEquals(2, $campaign->processed_items, 'Active devices should be processed');
        $this->assertEquals(1, $campaign->failed_items, 'Inactive device should be marked failed');
        $this->assertEquals('partial', $campaign->status, 'Mixed outcome must produce partial status');
        $this->assertStringContainsString("Device #{$devInactive->id} (Broken Turnstile) is inactive.", $campaign->error_summary);

        // Dispatches must have targeted only the 2 active devices
        CameraGateway::assertDispatchedTimes('RebootDevice', 2);
        CameraGateway::assertDispatched('RebootDevice', fn(Device $d) => $d->id === $devActive1->id);
        CameraGateway::assertDispatched('RebootDevice', fn(Device $d) => $d->id === $devActive2->id);
        CameraGateway::assertNotDispatched('RebootDevice', fn(Device $d) => $d->id === $devInactive->id);
    }

    /**
     * Challenge 1.2: Missing/non-existent device ID in BulkDeviceCampaignJob must increment
     * failed_items, record the error, and continue processing valid devices.
     */
    public function test_bulk_device_campaign_handles_missing_device_id_and_continues(): void
    {
        $devActive = Device::factory()->online()->create([
            'name' => 'Valid Gate',
            'is_active' => true,
        ]);
        $missingId = 987654;

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$missingId, $devActive->id],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle($cameraService);

        $campaign->refresh();

        $this->assertEquals(1, $campaign->processed_items);
        $this->assertEquals(1, $campaign->failed_items);
        $this->assertEquals('partial', $campaign->status);
        $this->assertStringContainsString("Device #{$missingId} not found.", $campaign->error_summary);

        CameraGateway::assertDispatchedTimes('RebootDevice', 1);
        CameraGateway::assertDispatched('RebootDevice', fn(Device $d) => $d->id === $devActive->id);
    }

    /**
     * Challenge 1.3: Fleet where all target devices fail (inactive or missing) terminates
     * with final status 'failed' and 0% progress.
     */
    public function test_bulk_device_campaign_all_devices_failing_marks_status_failed(): void
    {
        $devInactive1 = Device::factory()->create(['is_active' => false]);
        $devInactive2 = Device::factory()->create(['is_active' => false]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$devInactive1->id, $devInactive2->id],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle($cameraService);

        $campaign->refresh();

        $this->assertEquals(0, $campaign->processed_items);
        $this->assertEquals(2, $campaign->failed_items);
        $this->assertEquals('failed', $campaign->status);
        $this->assertEquals(0, $campaign->progressPercent());
        CameraGateway::assertNothingDispatched();
    }

    /**
     * Challenge 1.4: Bulk MQTT config update propagates new topic to database only for devices
     * where the hardware command actually succeeds.
     */
    public function test_bulk_device_campaign_mqtt_config_updates_db_only_on_command_success(): void
    {
        $devSucceeds = Device::factory()->online()->create([
            'mqtt_topic' => 'mqtt/face/old_topic_1',
            'is_active' => true,
        ]);
        $devFails = Device::factory()->online()->create([
            'mqtt_topic' => 'mqtt/face/old_topic_2',
            'is_active' => true,
        ]);

        // Configure fake to return failure for devFails
        $this->fakeGateway->setResponses([
            'UpMQTTconfig' => function (Device $device, array $info) use ($devFails) {
                if ($device->id === $devFails->id) {
                    return [
                        'success' => false,
                        'code' => 504,
                        'error' => 'Device timed out waiting for UpMQTTconfig-Ack',
                    ];
                }
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => ['result' => 'ok'],
                ];
            },
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'update_mqtt_config',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$devSucceeds->id, $devFails->id],
                'mqtt_config' => [
                    'MQTopic' => 'mqtt/face/new_fleet_topic',
                    'KeepAlive' => 60,
                ],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle($cameraService);

        $campaign->refresh();
        $devSucceeds->refresh();
        $devFails->refresh();

        $this->assertEquals(1, $campaign->processed_items);
        $this->assertEquals(1, $campaign->failed_items);
        $this->assertEquals('partial', $campaign->status);
        $this->assertStringContainsString('Device timed out', $campaign->error_summary);

        // devSucceeds topic must be updated in DB
        $this->assertEquals('mqtt/face/new_fleet_topic', $devSucceeds->mqtt_topic);
        // devFails topic must remain intact (no unverified optimistic update)
        $this->assertEquals('mqtt/face/old_topic_2', $devFails->mqtt_topic);
    }

    /**
     * Challenge 1.5: Hardware service exceptions are caught, logged, and isolated per device
     * without killing the worker process or terminating subsequent devices.
     */
    public function test_bulk_device_campaign_isolates_exceptions_per_device(): void
    {
        $devExplodes = Device::factory()->online()->create(['is_active' => true]);
        $devNormal = Device::factory()->online()->create(['is_active' => true]);

        $this->fakeGateway->setResponses([
            'RebootDevice' => function (Device $device) use ($devExplodes) {
                if ($device->id === $devExplodes->id) {
                    throw new \RuntimeException('Fatal network socket reset on device ' . $device->id);
                }
                return ['success' => true, 'code' => 200];
            },
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 2,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => [$devExplodes->id, $devNormal->id],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $job = new BulkDeviceCampaignJob($campaign->id);
        $job->handle($cameraService);

        $campaign->refresh();

        $this->assertEquals(1, $campaign->processed_items);
        $this->assertEquals(1, $campaign->failed_items);
        $this->assertEquals('partial', $campaign->status);
        $this->assertStringContainsString('Exception on device #' . $devExplodes->id, $campaign->error_summary);
        $this->assertStringContainsString('Fatal network socket reset', $campaign->error_summary);
    }

    // =========================================================================
    // SECTION 2: Access Control Scoping on Bulk Sync (BulkPersonnelSyncJob)
    // =========================================================================

    /**
     * Challenge 2.1: Bulk personnel sync strictly scopes face template provisioning
     * to authorized devices via Access Control groups and NEVER broadcasts to unauthorized cameras.
     */
    public function test_bulk_personnel_sync_strictly_scopes_dispatch_to_authorized_devices(): void
    {
        // Setup 3 physical devices
        $devZoneA = Device::factory()->online()->create(['name' => 'Zone A Turnstile']);
        $devZoneB = Device::factory()->online()->create(['name' => 'Zone B Turnstile']);
        $devServerRoom = Device::factory()->online()->create(['name' => 'Restricted Server Room']);

        // Setup Access Groups
        $groupA = AccessGroup::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'General Zone A Access',
            'is_active' => true,
        ]);
        $groupA->devices()->attach([$devZoneA->id]);

        $groupB = AccessGroup::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'General Zone B Access',
            'is_active' => true,
        ]);
        $groupB->devices()->attach([$devZoneB->id]);

        // Personnel Members
        $personA = Personnel::factory()->whitelist()->create(['name' => 'Alice ZoneA']);
        $personA->accessGroups()->attach([$groupA->id]);

        $personB = Personnel::factory()->whitelist()->create(['name' => 'Bob ZoneB']);
        $personB->accessGroups()->attach([$groupB->id]);

        $personBoth = Personnel::factory()->whitelist()->create(['name' => 'Charlie Floater']);
        $personBoth->accessGroups()->attach([$groupA->id, $groupB->id]);

        $personUnassigned = Personnel::factory()->whitelist()->create(['name' => 'Dave NewHire']);
        // Note: personUnassigned has no access groups, while access groups exist in system.

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 4,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'personnel_ids' => [$personA->id, $personB->id, $personBoth->id, $personUnassigned->id],
            ],
        ]);

        $cameraService = app(CameraMqttService::class);
        $accessControlService = app(AccessControlService::class);

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$personA->id, $personB->id, $personBoth->id, $personUnassigned->id],
            campaignId: $campaign->id,
            action: 'sync',
            targetDeviceId: null // No specific target device passed -> scoping required
        );

        $job->handle($cameraService, $accessControlService);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(4, $campaign->processed_items);

        // Verify Device A received PersonA and PersonBoth, but NOT PersonB or PersonUnassigned
        $devADispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $devZoneA->id);
        $this->assertNotEmpty($devADispatches, 'Zone A device should receive AddPersons command');
        $devACustomIds = $this->extractCustomIdsFromAddPersonsDispatches($devADispatches);
        $this->assertContains((string) $personA->customize_id, $devACustomIds);
        $this->assertContains((string) $personBoth->customize_id, $devACustomIds);
        $this->assertNotContains((string) $personB->customize_id, $devACustomIds);
        $this->assertNotContains((string) $personUnassigned->customize_id, $devACustomIds);

        // Verify Device B received PersonB and PersonBoth, but NOT PersonA or PersonUnassigned
        $devBDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $devZoneB->id);
        $this->assertNotEmpty($devBDispatches, 'Zone B device should receive AddPersons command');
        $devBCustomIds = $this->extractCustomIdsFromAddPersonsDispatches($devBDispatches);
        $this->assertContains((string) $personB->customize_id, $devBCustomIds);
        $this->assertContains((string) $personBoth->customize_id, $devBCustomIds);
        $this->assertNotContains((string) $personA->customize_id, $devBCustomIds);
        $this->assertNotContains((string) $personUnassigned->customize_id, $devBCustomIds);

        // Verify Restricted Server Room device received ZERO personnel
        $serverDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $devServerRoom->id);
        $this->assertEmpty($serverDispatches, 'Unmapped server room device must receive ZERO bulk sync commands');
    }

    /**
     * Challenge 2.2: Department-based access group membership must be respected
     * during workforce bulk synchronization.
     */
    public function test_bulk_personnel_sync_resolves_departmental_access_groups(): void
    {
        $deptDevice = Device::factory()->online()->create(['name' => 'Engineering Wing Camera']);
        $otherDevice = Device::factory()->online()->create(['name' => 'Marketing Wing Camera']);

        $engGroup = AccessGroup::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering Department Access',
            'is_active' => true,
        ]);
        $engGroup->devices()->attach([$deptDevice->id]);

        $department = Department::factory()->create(['name' => 'R&D Engineering', 'organization_id' => $this->org->id]);
        $engGroup->departments()->attach([$department->id]);

        $person = Personnel::factory()->whitelist()->create(['name' => 'Dev Engineer']);
        $employee = Employee::factory()->create([
            'personnel_id' => $person->id,
            'department_id' => $department->id,
            'organization_id' => $this->org->id,
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 1,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => [$person->id]],
        ]);

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$person->id],
            campaignId: $campaign->id,
            action: 'sync',
            targetDeviceId: null
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        $deptDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $deptDevice->id);
        $this->assertNotEmpty($deptDispatches);
        $otherDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $otherDevice->id);
        $this->assertEmpty($otherDispatches);
    }

    /**
     * Challenge 2.3: Explicit targetDeviceId parameter overrides access group resolution
     * and forces sync exclusively to that device.
     */
    public function test_bulk_personnel_sync_with_explicit_target_device_overrides_access_groups(): void
    {
        $devAllowed = Device::factory()->online()->create();
        $devTargetOverride = Device::factory()->online()->create();

        $group = AccessGroup::factory()->create(['is_active' => true]);
        $group->devices()->attach([$devAllowed->id]);

        $person = Personnel::factory()->whitelist()->create();
        $person->accessGroups()->attach([$group->id]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 1,
            'payload' => ['personnel_ids' => [$person->id], 'device_id' => $devTargetOverride->id],
        ]);

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$person->id],
            campaignId: $campaign->id,
            action: 'sync',
            targetDeviceId: $devTargetOverride->id
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        // Command must have gone to devTargetOverride, not devAllowed
        $targetDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $devTargetOverride->id);
        $this->assertNotEmpty($targetDispatches);
        $allowedDispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $devAllowed->id);
        $this->assertEmpty($allowedDispatches);
    }

    /**
     * Challenge 2.4: Fallback behavior when 0 access groups exist in the database
     * provisions personnel to all active devices (legacy backwards compatibility).
     */
    public function test_bulk_personnel_sync_fallback_when_zero_access_groups_exist(): void
    {
        AccessGroup::query()->delete();
        $this->assertEquals(0, AccessGroup::count());

        $devActive1 = Device::factory()->online()->create(['is_active' => true]);
        $devActive2 = Device::factory()->online()->create(['is_active' => true]);
        $devInactive = Device::factory()->create(['is_active' => false]);

        $person = Personnel::factory()->whitelist()->create();

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$person->id],
            action: 'sync',
            targetDeviceId: null
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        CameraGateway::assertDispatched('AddPersons', fn(Device $d) => $d->id === $devActive1->id);
        CameraGateway::assertDispatched('AddPersons', fn(Device $d) => $d->id === $devActive2->id);
        CameraGateway::assertNotDispatched('AddPersons', fn(Device $d) => $d->id === $devInactive->id);
    }

    /**
     * Challenge 2.5: Bulk personnel deletion scopes deletion commands strictly to authorized devices
     * and deletes records from database.
     */
    public function test_bulk_personnel_deletion_scopes_to_authorized_devices_and_removes_from_db(): void
    {
        $devZone = Device::factory()->online()->create();
        $devUnrelated = Device::factory()->online()->create();

        $group = AccessGroup::factory()->create(['is_active' => true]);
        $group->devices()->attach([$devZone->id]);

        $person1 = Personnel::factory()->whitelist()->create(['customize_id' => 77001]);
        $person1->accessGroups()->attach([$group->id]);
        $person2 = Personnel::factory()->whitelist()->create(['customize_id' => 77002]);
        $person2->accessGroups()->attach([$group->id]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'delete_personnel',
            'total_items' => 2,
            'payload' => ['personnel_ids' => [$person1->id, $person2->id]],
        ]);

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$person1->id, $person2->id],
            campaignId: $campaign->id,
            action: 'delete'
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        $campaign->refresh();
        $this->assertEquals(2, $campaign->processed_items);
        $this->assertEquals('completed', $campaign->status);

        // Hardware command DeletePersons sent to devZone
        CameraGateway::assertDispatched('DeletePersons', fn(Device $d) => $d->id === $devZone->id);
        CameraGateway::assertNotDispatched('DeletePersons', fn(Device $d) => $d->id === $devUnrelated->id);

        // Database records deleted
        $this->assertDatabaseMissing('personnel', ['id' => $person1->id]);
        $this->assertDatabaseMissing('personnel', ['id' => $person2->id]);
    }

    // =========================================================================
    // SECTION 3: Audit Trail Verification (SyncTask Records)
    // =========================================================================

    /**
     * Challenge 3.1: Every personnel-device synchronization must create a distinct SyncTask
     * audit record in the database with accurate status and metadata.
     */
    public function test_bulk_personnel_sync_creates_distinct_sync_task_audit_records(): void
    {
        $dev1 = Device::factory()->online()->create(['device_id' => 'CAM-SYNC-AUDIT-1']);
        $dev2 = Device::factory()->online()->create(['device_id' => 'CAM-SYNC-AUDIT-2']);

        $group1 = AccessGroup::factory()->create(['is_active' => true]);
        $group1->devices()->attach([$dev1->id, $dev2->id]);

        $personA = Personnel::factory()->whitelist()->create(['name' => 'Audited User A']);
        $personB = Personnel::factory()->whitelist()->create(['name' => 'Audited User B']);
        $personA->accessGroups()->attach([$group1->id]);
        $personB->accessGroups()->attach([$group1->id]);

        SyncTask::query()->delete();

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$personA->id, $personB->id],
            action: 'sync'
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        // 2 persons x 2 authorized devices = 4 SyncTasks
        $this->assertEquals(4, SyncTask::count());

        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-AUDIT-1',
            'personnel_id' => $personA->id,
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-AUDIT-2',
            'personnel_id' => $personA->id,
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-AUDIT-1',
            'personnel_id' => $personB->id,
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-AUDIT-2',
            'personnel_id' => $personB->id,
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);
    }

    /**
     * Challenge 3.2: When edge camera hardware rejects the AddPersons packet,
     * SyncTask audit records must reflect FAILED status with error detail.
     */
    public function test_bulk_personnel_sync_records_failed_sync_tasks_on_hardware_rejection(): void
    {
        $devFailing = Device::factory()->online()->create(['device_id' => 'CAM-FAIL-01']);

        $group = AccessGroup::factory()->create(['is_active' => true]);
        $group->devices()->attach([$devFailing->id]);

        $person = Personnel::factory()->whitelist()->create();
        $person->accessGroups()->attach([$group->id]);

        $this->fakeGateway->setResponses([
            'AddPersons' => function () {
                return [
                    'success' => false,
                    'code' => 400,
                    'error' => 'Face quality score below threshold (ERR_FACE_BLURRY)',
                ];
            },
        ]);

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 1,
            'payload' => ['personnel_ids' => [$person->id]],
        ]);

        SyncTask::query()->delete();

        $job = new BulkPersonnelSyncJob(
            personnelIds: [$person->id],
            campaignId: $campaign->id,
            action: 'sync'
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        $campaign->refresh();
        $this->assertEquals(1, $campaign->failed_items);
        $this->assertEquals('failed', $campaign->status);

        $task = SyncTask::where('device_id', 'CAM-FAIL-01')
            ->where('personnel_id', $person->id)
            ->first();

        $this->assertNotNull($task);
        $this->assertEquals('FAILED', $task->status);
        $this->assertStringContainsString('ERR_FACE_BLURRY', $task->error_message);
    }

    // =========================================================================
    // SECTION 4: Boundary & Concurrency (Packet Chunking & API Integrity)
    // =========================================================================

    /**
     * Challenge 4.1: Edge cameras require maximum 50 persons per AddPersons packet.
     * Synchronizing 125 persons must generate exactly 3 packets (50, 50, 25) with 125 SyncTasks.
     */
    public function test_large_workforce_chunking_creates_proper_sized_packets(): void
    {
        $device = Device::factory()->online()->create(['device_id' => 'CAM-CHUNK-01']);
        $group = AccessGroup::factory()->create(['is_active' => true]);
        $group->devices()->attach([$device->id]);

        $personnelList = Personnel::factory()->count(125)->whitelist()->create();
        foreach ($personnelList as $p) {
            $p->accessGroups()->attach([$group->id]);
        }

        $campaign = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 125,
            'payload' => ['personnel_ids' => $personnelList->pluck('id')->all()],
        ]);

        SyncTask::query()->delete();

        $job = new BulkPersonnelSyncJob(
            personnelIds: $personnelList->pluck('id')->all(),
            campaignId: $campaign->id,
            action: 'sync'
        );
        $job->handle(app(CameraMqttService::class), app(AccessControlService::class));

        $campaign->refresh();
        $this->assertEquals(125, $campaign->processed_items);
        $this->assertEquals(0, $campaign->failed_items);
        $this->assertEquals('completed', $campaign->status);

        // 3 AddPersons dispatches: 50 + 50 + 25
        $dispatches = $this->fakeGateway->dispatched('AddPersons', fn(Device $d) => $d->id === $device->id);
        $this->assertCount(3, $dispatches);
        $this->assertEquals(50, $dispatches[0]['info']['Total']);
        $this->assertEquals(50, $dispatches[1]['info']['Total']);
        $this->assertEquals(25, $dispatches[2]['info']['Total']);

        // Exactly 125 SyncTasks created
        $this->assertEquals(125, SyncTask::count());
    }

    /**
     * Challenge 4.2: Bulk campaign endpoints require RBAC authorization.
     * Unauthorized users must receive HTTP 403.
     */
    public function test_bulk_campaign_endpoints_enforce_rbac_permissions(): void
    {
        $this->actingAsUnauthorized();

        $device = Device::factory()->create();
        $person = Personnel::factory()->create();

        // 1. Bulk Reboot requires devices.manage
        $resReboot = $this->postJson('/api/devices/bulk-reboot', ['device_ids' => [$device->id]]);
        $this->assertEquals(403, $resReboot->status());

        // 2. Bulk MQTT config requires devices.manage
        $resMqtt = $this->postJson('/api/devices/bulk-sync-mqtt', [
            'device_ids' => [$device->id],
            'mqtt_config' => ['MQTopic' => 'mqtt/face/test'],
        ]);
        $this->assertEquals(403, $resMqtt->status());

        // 3. Bulk Personnel Sync requires personnel.sync
        $resSync = $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => [$person->id]]);
        $this->assertEquals(403, $resSync->status());

        // 4. Bulk Personnel Delete requires personnel.delete
        $resDel = $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => [$person->id]]);
        $this->assertEquals(403, $resDel->status());
    }

    /**
     * Challenge 4.3: Bulk campaign endpoints reject empty array payloads with HTTP 422.
     */
    public function test_bulk_campaign_endpoints_reject_empty_arrays(): void
    {
        $this->actingAsAdmin();

        $resReboot = $this->postJson('/api/devices/bulk-reboot', ['device_ids' => []]);
        $resReboot->assertStatus(422);
        $resReboot->assertJsonValidationErrors(['device_ids']);

        $resSync = $this->postJson('/api/personnel/bulk-sync', ['personnel_ids' => []]);
        $resSync->assertStatus(422);
        $resSync->assertJsonValidationErrors(['personnel_ids']);

        $resDel = $this->postJson('/api/personnel/bulk-delete', ['personnel_ids' => []]);
        $resDel->assertStatus(422);
        $resDel->assertJsonValidationErrors(['personnel_ids']);
    }

    /**
     * Challenge 4.4: Progress percent virtual attribute must clamp correctly between 0 and 100
     * and handle division by zero safely.
     */
    public function test_bulk_campaign_progress_percent_clamping_and_rounding(): void
    {
        $campaignZero = BulkCampaign::create([
            'campaign_type' => 'reboot_fleet',
            'total_items' => 0,
            'processed_items' => 0,
            'status' => 'pending',
        ]);
        $this->assertEquals(0, $campaignZero->progress_percent);

        $campaignHalf = BulkCampaign::create([
            'campaign_type' => 'reboot_fleet',
            'total_items' => 3,
            'processed_items' => 1,
            'status' => 'processing',
        ]);
        $this->assertEquals(33, $campaignHalf->progress_percent);

        $campaignOver = BulkCampaign::create([
            'campaign_type' => 'reboot_fleet',
            'total_items' => 10,
            'processed_items' => 15, // Out of bounds
            'status' => 'completed',
        ]);
        $this->assertEquals(100, $campaignOver->progress_percent);
    }

    /**
     * Challenge 4.5: Bulk campaign show and index API endpoints return correct structure
     * and respect filtering parameters.
     */
    public function test_bulk_campaign_show_and_index_api_endpoints(): void
    {
        $this->actingAsAdmin();

        $c1 = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 10,
            'processed_items' => 10,
            'failed_items' => 0,
            'status' => 'completed',
        ]);

        $c2 = BulkCampaign::create([
            'user_id' => $this->adminUser->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 5,
            'processed_items' => 2,
            'failed_items' => 3,
            'status' => 'partial',
        ]);

        // GET show
        $resShow = $this->getJson("/api/bulk-campaigns/{$c1->id}");
        $resShow->assertStatus(200);
        $resShow->assertJsonFragment([
            'id' => $c1->id,
            'campaign_type' => 'reboot_fleet',
            'status' => 'completed',
            'progress_percent' => 100,
        ]);

        // GET index with filter
        $resIndex = $this->getJson('/api/bulk-campaigns?campaign_type=sync_personnel&status=partial');
        $resIndex->assertStatus(200);
        $data = $resIndex->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($c2->id, $data[0]['id']);
    }

    /**
     * Helper to extract customId strings from AddPersons dispatches recorded by FakeCameraGateway.
     */
    protected function extractCustomIdsFromAddPersonsDispatches(iterable $dispatches): array
    {
        $customIds = [];
        foreach ($dispatches as $dispatch) {
            $info = $dispatch['info'] ?? [];
            $total = (int) ($info['PersonNum'] ?? $info['Total'] ?? 0);
            for ($i = 0; $i < $total; $i++) {
                if (isset($info["Personinfo_{$i}"]['customId'])) {
                    $customIds[] = (string) $info["Personinfo_{$i}"]['customId'];
                }
            }
        }
        return $customIds;
    }
}
