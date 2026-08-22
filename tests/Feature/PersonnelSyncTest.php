<?php

namespace Tests\Feature;

use App\Jobs\SyncPersonnelJob;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Services\CameraHttpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PersonnelSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_personnel_and_dispatch_sync_with_root_picinfo(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-SYNC-01',
            'name' => 'Main Gate Camera',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.1.100:8080/action/EditPersonNew' => Http::response([
                'operator' => 'EditPersonNew',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $person = Personnel::create([
            'name' => 'John Doe',
            'customize_id' => 101,
            'person_type' => 0,
            'gender' => 0,
            'id_card' => '123456789012345678',
            'photo_base64' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $job = new SyncPersonnelJob($person->id, 'ADD');
        $job->handle(app(CameraHttpService::class));

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://192.168.1.100:8080/action/EditPersonNew'
                && $data['operator'] === 'EditPersonNew'
                && isset($data['info']['CustomizeID'])
                && $data['info']['CustomizeID'] === 101
                && isset($data['picinfo'])
                && !isset($data['info']['picinfo']);
        });

        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-01',
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);
    }

    public function test_can_delete_personnel_and_sync_delete_to_device(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-SYNC-02',
            'name' => 'Back Gate Camera',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.1.100:8080/action/DeletePerson' => Http::response([
                'operator' => 'DeletePerson',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $person = Personnel::create([
            'name' => 'Jane Doe',
            'customize_id' => 202,
            'person_type' => 0,
        ]);

        $personId = $person->id;
        $customizeId = $person->customize_id;

        // Delete from local database first to simulate async queue execution
        $person->delete();

        $job = new SyncPersonnelJob($personId, 'DELETE', null, $customizeId);
        $job->handle(app(CameraHttpService::class));

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://192.168.1.100:8080/action/DeletePerson'
                && $data['operator'] === 'DeletePerson'
                && $data['info']['TotalNum'] === 1
                && $data['info']['IdType'] === 0
                && $data['info']['CustomizeID'] === [202];
        });

        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-02',
            'action' => 'DELETE',
            'status' => 'COMPLETED',
        ]);
    }
}
