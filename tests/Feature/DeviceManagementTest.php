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
}
