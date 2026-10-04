<?php

namespace Tests\Feature;

use App\Events\DeviceAlertUpdated;
use App\Events\PersonnelUpdated;
use App\Events\SyncTaskUpdated;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \App\Models\Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        $org = \App\Models\Organization::create([
            'name' => 'Pinnacle Technologies Inc.',
            'code' => 'PINNACLE-HQ',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$role->id]);
        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser, ['*']);
    }

    public function test_stats_endpoint_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'telemetry' => [
                    'total_scans_today',
                    'allowed_today',
                    'rejected_today',
                    'strangers_today',
                    'alerts_today',
                    'critical_alerts_today',
                    'unresolved_alerts',
                ],
                'devices' => [
                    'total',
                    'online',
                    'offline',
                ],
                'personnel' => [
                    'total',
                    'whitelisted',
                    'blacklisted',
                ],
                'sync' => [
                    'pending',
                    'failed',
                ],
            ]);
    }

    public function test_device_alert_update_dispatches_event(): void
    {
        Event::fake([DeviceAlertUpdated::class]);

        $device = Device::create([
            'device_id' => 'CAM-999',
            'name' => 'Gate Camera',
            'ip_address' => '192.168.1.50',
            'is_active' => true,
        ]);

        $alert = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'PPE_VIOLATION',
            'severity' => 'CRITICAL',
            'title' => 'Missing Safety Helmet',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/device-alerts/{$alert->id}/status", [
                'status' => 'RESOLVED',
            ]);

        $response->assertOk();

        Event::assertDispatched(DeviceAlertUpdated::class, function (DeviceAlertUpdated $event) use ($alert) {
            return $event->alert->id === $alert->id &&
                   $event->alert->status === 'RESOLVED' &&
                   $event->previousStatus === 'NEW';
        });
    }

    public function test_personnel_crud_dispatches_personnel_updated_event(): void
    {
        Event::fake([PersonnelUpdated::class]);

        $person = Personnel::create([
            'customize_id' => 99112,
            'name' => 'Jane Doe',
            'person_type' => 0,
        ]);

        Event::assertDispatched(PersonnelUpdated::class, function (PersonnelUpdated $event) {
            return $event->action === 'created' && $event->personType === 0;
        });

        $person->update(['person_type' => 1]);

        Event::assertDispatched(PersonnelUpdated::class, function (PersonnelUpdated $event) {
            return $event->action === 'updated' && $event->personType === 1 && $event->oldPersonType === 0;
        });

        $person->delete();

        Event::assertDispatched(PersonnelUpdated::class, function (PersonnelUpdated $event) {
            return $event->action === 'deleted';
        });
    }

    public function test_sync_task_dispatches_sync_task_updated_event(): void
    {
        Event::fake([SyncTaskUpdated::class]);

        $device = Device::create([
            'device_id' => 'CAM-888',
            'name' => 'Lobby Camera',
            'ip_address' => '192.168.1.60',
            'is_active' => true,
        ]);

        $person = Personnel::create([
            'customize_id' => 99887,
            'name' => 'John Sync',
            'person_type' => 0,
        ]);

        $task = SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person->id,
            'action' => 'ADD',
            'status' => 'PENDING',
        ]);

        Event::assertDispatched(SyncTaskUpdated::class, function (SyncTaskUpdated $event) use ($task) {
            return $event->syncTask->id === $task->id && $event->oldStatus === null;
        });

        $task->update(['status' => 'COMPLETED']);

        Event::assertDispatched(SyncTaskUpdated::class, function (SyncTaskUpdated $event) use ($task) {
            return $event->syncTask->id === $task->id &&
                   $event->syncTask->status === 'COMPLETED' &&
                   $event->oldStatus === 'PENDING';
        });
    }

    public function test_bulk_device_alerts_update_dispatches_events(): void
    {
        Event::fake([DeviceAlertUpdated::class]);

        $device = Device::create([
            'device_id' => 'CAM-BULK-1',
            'name' => 'Perimeter Camera',
            'ip_address' => '192.168.1.70',
            'is_active' => true,
        ]);

        $alert1 = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'AREA_INTRUSION',
            'severity' => 'CRITICAL',
            'title' => 'Intrusion Alert 1',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $alert2 = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'FIRE_SMOKE',
            'severity' => 'CRITICAL',
            'title' => 'Smoke Detected',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/device-alerts/bulk-status', [
                'ids' => [$alert1->id, $alert2->id],
                'status' => 'RESOLVED',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        Event::assertDispatched(DeviceAlertUpdated::class, 2);
    }

    public function test_http_webhook_heartbeat_dispatches_device_status_updated(): void
    {
        Event::fake([\App\Events\DeviceStatusUpdated::class]);

        $response = $this->postJson('/Subscribe/heartbeat', [
            'info' => [
                'DeviceID' => 'CAM-HB-123',
                'facesname' => 'Entrance Gate',
                'ip' => '192.168.1.80',
            ],
        ]);

        $response->assertOk();

        Event::assertDispatched(\App\Events\DeviceStatusUpdated::class, function (\App\Events\DeviceStatusUpdated $event) {
            return $event->device->device_id === 'CAM-HB-123';
        });
    }

    public function test_http_webhook_verify_dispatches_access_log_received(): void
    {
        Event::fake([\App\Events\AccessLogReceived::class]);

        Device::firstOrCreate(['device_id' => 'CAM-VERIFY-1'], [
            'name' => 'Verify Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $response = $this->postJson('/Subscribe/Verify', [
            'info' => [
                'DeviceID' => 'CAM-VERIFY-1',
                'PersonID' => 101,
                'CustomizeID' => 5001,
                'Name' => 'Alice Smith',
                'VerifyStatus' => 1,
                'Similarity' => 96.5,
                'CreateTime' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $response->assertOk();

        Event::assertDispatched(\App\Events\AccessLogReceived::class, function (\App\Events\AccessLogReceived $event) {
            return $event->log->device_id === 'CAM-VERIFY-1' &&
                   $event->log->person_name === 'Alice Smith' &&
                   $event->log->verify_status === 1;
        });
    }

    public function test_http_webhook_snap_dispatches_stranger_snap_received(): void
    {
        Event::fake([\App\Events\StrangerSnapReceived::class]);

        Device::firstOrCreate(['device_id' => 'CAM-SNAP-1'], [
            'name' => 'Snap Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $response = $this->postJson('/Subscribe/Snap', [
            'info' => [
                'DeviceID' => 'CAM-SNAP-1',
                'CreateTime' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $response->assertOk();

        Event::assertDispatched(\App\Events\StrangerSnapReceived::class, function (\App\Events\StrangerSnapReceived $event) {
            return $event->snap->device_id === 'CAM-SNAP-1';
        });
    }
}
