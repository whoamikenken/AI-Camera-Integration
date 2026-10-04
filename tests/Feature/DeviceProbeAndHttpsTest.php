<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceProbeAndHttpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrator']);
        $permission = Permission::firstOrCreate(['slug' => 'devices.manage'], ['name' => 'Manage Devices', 'group' => 'devices', 'module' => 'devices']);
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        Sanctum::actingAs($user, ['*']);
    }

    public function test_can_store_wan_mqtt_device(): void
    {
        $response = $this->postJson('/api/devices', [
            'device_id' => 'CAM-MQTT-101',
            'name' => 'WAN Entrance Camera',
            'ip_address' => 'ai-camera.philyra.cloud',
            'port' => 1883,
            'mqtt_topic' => 'mqtt/face/CAM-MQTT-101',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'CAM-MQTT-101',
            'ip_address' => 'ai-camera.philyra.cloud',
            'port' => 1883,
            'mqtt_topic' => 'mqtt/face/CAM-MQTT-101',
        ]);
    }

    public function test_can_test_device_connection_via_mqtt(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-MQTT-103',
            'name' => 'WAN Route Test Camera',
            'ip_address' => 'ai-camera.philyra.cloud',
            'port' => 1883,
            'mqtt_topic' => 'mqtt/face/CAM-MQTT-103',
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/test-connection");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        $device->refresh();
        $this->assertNotNull($device->last_heartbeat_at);
    }
}
