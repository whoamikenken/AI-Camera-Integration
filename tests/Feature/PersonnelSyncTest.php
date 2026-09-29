<?php

namespace Tests\Feature;

use App\Jobs\SyncPersonnelJob;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Services\CameraMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $person = Personnel::create([
            'name' => 'John Doe',
            'customize_id' => 101,
            'person_type' => 0,
            'gender' => 0,
            'id_card' => '123456789012345678',
            'photo_base64' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $job = new SyncPersonnelJob($person->id, 'ADD');
        $job->handle(app(CameraMqttService::class));

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

        $person = Personnel::create([
            'name' => 'Jane Doe',
            'customize_id' => 202,
            'person_type' => 0,
        ]);

        $personId = $person->id;
        $customizeId = $person->customize_id;

        $person->delete();

        $job = new SyncPersonnelJob($personId, 'DELETE', null, $customizeId);
        $job->handle(app(CameraMqttService::class));

        $this->assertDatabaseHas('sync_tasks', [
            'device_id' => 'CAM-SYNC-02',
            'action' => 'DELETE',
            'status' => 'COMPLETED',
        ]);
    }

    public function test_can_enroll_personnel_from_stranger_snap_url(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-SYNC-03',
            'name' => 'Front Gate Camera',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/personnel', [
            'name' => 'Enrolled Stranger',
            'customize_id' => 303,
            'person_type' => 0,
            'gender' => 1,
            'photo_path' => 'strangers/test_stranger.jpg',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('personnel', [
            'customize_id' => 303,
            'name' => 'Enrolled Stranger',
        ]);
    }

    public function test_can_retry_delete_sync_task_without_error(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-RETRY-01',
            'name' => 'Retry Gate Camera',
            'ip_address' => '192.168.1.100',
            'is_active' => true,
        ]);

        $task = SyncTask::create([
            'device_id' => 'CAM-RETRY-01',
            'personnel_id' => null,
            'action' => 'DELETE',
            'status' => 'FAILED',
            'attempts' => 1,
            'error_message' => 'Network error',
        ]);

        $response = $this->postJson("/api/sync-tasks/{$task->id}/retry");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Task queued for retry']);

        $this->assertDatabaseHas('sync_tasks', [
            'id' => $task->id,
            'status' => 'PENDING',
            'attempts' => 2,
        ]);
    }
}
