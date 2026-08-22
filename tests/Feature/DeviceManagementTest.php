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
}
