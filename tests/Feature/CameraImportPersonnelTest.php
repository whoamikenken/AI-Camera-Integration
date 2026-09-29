<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CameraImportPersonnelTest extends TestCase
{
    use RefreshDatabase;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'device_id' => 'CAM-IMPORT-TEST',
            'name' => 'Import Test Camera',
            'scheme' => 'http',
            'ip_address' => '192.168.1.205',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);
    }

    public function test_import_personnel_from_camera_service(): void
    {
        Http::fake([
            'http://192.168.1.205:8080/action/SearchPersonList' => Http::response([
                'operator' => 'SearchPersonList',
                'code' => 200,
                'info' => [
                    'Result' => 'Ok',
                    'TotalNum' => 2,
                    'Personinfo_0' => [
                        'CustomizeID' => 7701,
                        'Name' => 'Camera User One',
                        'PersonType' => 0,
                        'Gender' => 0,
                        'IDCard' => 'ID7701',
                        'TelNum' => '123456',
                        'Address' => 'Building A',
                        'picinfo' => 'data:image/jpeg;base64,' . base64_encode('fake-face-jpeg-one'),
                    ],
                    'Personinfo_1' => [
                        'CustomizeID' => 7702,
                        'Name' => 'Camera User Two',
                        'PersonType' => 1,
                        'Gender' => 1,
                        'IDCard' => 'ID7702',
                    ],
                ],
            ], 200),
            'http://192.168.1.205:8080/action/SearchPerson' => Http::response([
                'operator' => 'SearchPerson',
                'code' => 200,
                'info' => [
                    'Personinfo_0' => [
                        'CustomizeID' => 7702,
                        'Name' => 'Camera User Two',
                        'picinfo' => 'data:image/jpeg;base64,' . base64_encode('fake-face-jpeg-two'),
                    ],
                ],
            ], 200),
        ]);

        /** @var \App\Services\CameraService $service */
        $service = app(\App\Services\CameraService::class);
        $result = $service->importPersonnelFromCamera($this->device);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['imported_count']);

        $this->assertDatabaseHas('personnel', [
            'customize_id' => 7701,
            'name' => 'Camera User One',
            'person_type' => 0,
        ]);

        $this->assertDatabaseHas('personnel', [
            'customize_id' => 7702,
            'name' => 'Camera User Two',
            'person_type' => 1,
        ]);
    }

    public function test_import_personnel_api_endpoint(): void
    {
        Http::fake([
            'http://192.168.1.205:8080/action/SearchPersonList' => Http::response([
                'operator' => 'SearchPersonList',
                'code' => 200,
                'info' => [
                    'Result' => 'Ok',
                    'Personinfo_0' => [
                        'CustomizeID' => 8801,
                        'Name' => 'API Import User',
                        'PersonType' => 0,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/devices/{$this->device->id}/import-personnel");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('imported_count', 1);

        $this->assertDatabaseHas('personnel', [
            'customize_id' => 8801,
            'name' => 'API Import User',
        ]);
    }
}
