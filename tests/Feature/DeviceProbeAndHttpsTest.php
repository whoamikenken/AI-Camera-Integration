<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\CameraHttpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceProbeAndHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_parse_various_endpoint_strings(): void
    {
        $res1 = CameraHttpService::parseEndpoint('https://ai-camera.philyra.cloud/');
        $this->assertEquals('https', $res1['scheme']);
        $this->assertEquals('ai-camera-api.philyra.cloud', $res1['host']);
        $this->assertEquals(443, $res1['port']);

        $res2 = CameraHttpService::parseEndpoint('https://ai-camera.philyra.cloud:8443/action');
        $this->assertEquals('https', $res2['scheme']);
        $this->assertEquals('ai-camera-api.philyra.cloud', $res2['host']);
        $this->assertEquals(8443, $res2['port']);

        $res3 = CameraHttpService::parseEndpoint('192.168.1.100', 8080);
        $this->assertEquals('http', $res3['scheme']);
        $this->assertEquals('192.168.1.100', $res3['host']);
        $this->assertEquals(8080, $res3['port']);
    }

    public function test_can_probe_camera_endpoint_and_auto_detect_device_id(): void
    {
        Http::fake([
            'https://ai-camera-api.philyra.cloud/action/GetSysParam' => Http::response([
                'operator' => 'GetSysParam',
                'code' => 200,
                'info' => [
                    'DeviceID' => 'CAM-HTTPS-999',
                    'Name' => 'Cloud Entrance Camera',
                    'Version' => 'v4.2.1',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/devices/probe', [
            'endpoint' => 'https://ai-camera.philyra.cloud/',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'scheme' => 'https',
                'host' => 'ai-camera-api.philyra.cloud',
                'port' => 443,
                'device_id' => 'CAM-HTTPS-999',
                'name' => 'Cloud Entrance Camera',
            ]);
    }

    public function test_can_store_device_with_full_https_url(): void
    {
        $response = $this->postJson('/api/devices', [
            'device_id' => 'CAM-HTTPS-101',
            'name' => 'HTTPS Gate Camera',
            'ip_address' => 'https://ai-camera-api.philyra.cloud/',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'CAM-HTTPS-101',
            'scheme' => 'https',
            'ip_address' => 'ai-camera-api.philyra.cloud',
            'port' => 443,
        ]);

        $device = Device::where('device_id', 'CAM-HTTPS-101')->first();
        $this->assertEquals('https://ai-camera-api.philyra.cloud', $device->endpoint_url);
    }

    public function test_https_device_dispatches_http_requests_over_https(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-HTTPS-102',
            'name' => 'HTTPS Camera Test',
            'scheme' => 'https',
            'ip_address' => 'ai-camera-api.philyra.cloud',
            'port' => 443,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'https://ai-camera-api.philyra.cloud/action/GetSysParam' => Http::response([
                'operator' => 'GetSysParam',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'DeviceID' => 'CAM-HTTPS-102'],
            ], 200),
        ]);

        $cameraService = app(CameraHttpService::class);
        $result = $cameraService->testConnection($device);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ai-camera-api.philyra.cloud/action/GetSysParam';
        });
    }

    public function test_can_test_device_connection_via_http_api_route(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-API-103',
            'name' => 'API Route Test Camera',
            'scheme' => 'http',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.1.100:8080/action/GetSysParam' => Http::response([
                'operator' => 'GetSysParam',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'DeviceID' => 'CAM-API-103'],
            ], 200),
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/test-connection", [
            'scheme' => 'http',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        $device->refresh();
        $this->assertNotNull($device->last_heartbeat_at);
    }

    public function test_can_perform_device_audit_via_http_api_route(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-AUDIT-200',
            'name' => 'Audit Test Camera',
            'scheme' => 'http',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.1.100:8080/action/GetSysParam' => Http::response([
                'operator' => 'GetSysParam',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'DeviceID' => 'CAM-AUDIT-200', 'Name' => 'Audit Test Camera'],
            ], 200),
            'http://192.168.1.100:8080/action/SearchPersonList' => Http::response([
                'operator' => 'SearchPersonList',
                'code' => 200,
                'info' => [
                    'TotalNum' => 1,
                    'Listnum' => 1,
                    'Personinfo_0' => [
                        'CustomizeID' => 99,
                        'Name' => 'Alice Auditor',
                        'PersonType' => 0,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson("/api/devices/{$device->id}/audit");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'device' => [
                    'device_id' => 'CAM-AUDIT-200',
                ],
                'face_audit' => [
                    'total_on_camera' => 1,
                ],
            ]);
    }
}
