<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\SyncTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_devices(): void
    {
        Device::create([
            'device_id' => 'CAM-001',
            'name' => 'Main Gate',
            'ip_address' => '192.168.1.101',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/devices');

        $response->assertStatus(200)
            ->assertJsonFragment(['device_id' => 'CAM-001']);
    }

    public function test_can_delete_device(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-DELETE-01',
            'name' => 'Back Gate Camera',
            'ip_address' => '192.168.1.105',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/devices/{$device->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Device deleted successfully']);

        $this->assertDatabaseMissing('devices', [
            'id' => $device->id,
            'device_id' => 'CAM-DELETE-01',
        ]);
    }

    public function test_can_get_device_detail(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-DETAIL-01',
            'name' => 'Front Gate Camera',
            'ip_address' => '192.168.1.106',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/devices/{$device->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $device->id,
                'device_id' => 'CAM-DETAIL-01',
                'name' => 'Front Gate Camera',
            ]);
    }

    public function test_device_counts_reflect_verifications_and_strangers(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-COUNTS-01',
            'name' => 'Counts Gate Camera',
            'ip_address' => '192.168.1.107',
            'port' => 8080,
            'is_active' => true,
        ]);

        AccessLog::create([
            'device_id' => 'CAM-COUNTS-01',
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        \App\Models\StrangerSnap::create([
            'device_id' => 'CAM-COUNTS-01',
            'snap_pic_url' => '/storage/strangers/snap1.jpg',
            'captured_at' => now(),
        ]);

        $response = $this->getJson("/api/devices/{$device->id}");

        $response->assertStatus(200)
            ->assertJson([
                'device_id' => 'CAM-COUNTS-01',
                'access_logs_count' => 1,
                'stranger_snaps_count' => 1,
            ]);

        $listResponse = $this->getJson('/api/devices');
        $listResponse->assertStatus(200)
            ->assertJsonFragment([
                'device_id' => 'CAM-COUNTS-01',
                'access_logs_count' => 1,
                'stranger_snaps_count' => 1,
            ]);
    }

    public function test_heartbeat_auto_registers_device(): void
    {
        $response = $this->postJson('/api/Subscribe/heartbeat', [
            'info' => [
                'DeviceID' => 'CAM-AUTO-999',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'CAM-AUTO-999',
        ]);
    }

    public function test_device_audit_returns_unified_user_roster(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-AUDIT-01',
            'name' => 'Audit Gate Camera',
            'ip_address' => '192.168.1.108',
            'port' => 1883,
            'is_active' => true,
        ]);

        $person1 = \App\Models\Personnel::create([
            'customize_id' => 9001,
            'name' => 'Alice Auditor',
            'person_type' => 0,
        ]);

        $person2 = \App\Models\Personnel::create([
            'customize_id' => 9002,
            'name' => 'Bob Blocked',
            'person_type' => 1,
        ]);

        // Remove person2's sync task to test missing status
        SyncTask::where('personnel_id', $person2->id)->delete();

        $response = $this->getJson("/api/devices/{$device->id}/audit");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'face_audit' => [
                    'total_in_db' => 2,
                    'synced_count' => 1,
                    'missing_on_camera_count' => 1,
                ],
            ]);

        $roster = $response->json('face_audit.user_roster');
        $this->assertCount(2, $roster);
        $this->assertEquals('Alice Auditor', $roster[0]['name']);
        $this->assertEquals('SYNCED', $roster[0]['status']);
        $this->assertEquals('Bob Blocked', $roster[1]['name']);
        $this->assertEquals('MISSING', $roster[1]['status']);
    }
}

