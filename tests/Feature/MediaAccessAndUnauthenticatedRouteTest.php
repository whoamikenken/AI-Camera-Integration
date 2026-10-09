<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\AccessLog;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StrangerSnap;
use App\Models\User;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaAccessAndUnauthenticatedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_401_and_does_not_throw_route_not_found(): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/devices');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_img_request_without_accept_json_returns_401_instead_of_route_login_not_defined(): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->get('/api/devices', [
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_stranger_snap_media_with_token_in_query(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('strangers/2026/10/04/snap.jpg', 'fake-image-content');

        $role = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_system' => true]);
        $permission = Permission::create(['name' => 'View Devices', 'slug' => 'devices.view', 'group' => 'devices']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        $token = $user->createToken('test-token')->plainTextToken;

        $storage = app(ImageStorageService::class);
        $signedUrl = $storage->signedMediaUrl('strangers/2026/10/04/snap.jpg', 60);

        // 1. Valid signed URL works (200) without Authorization header
        $this->app['auth']->forgetGuards();
        $responseSigned = $this->get($signedUrl);
        $responseSigned->assertStatus(200);
        $responseSigned->assertHeader('Content-Type', 'image/jpeg');
        $this->assertEquals('fake-image-content', $responseSigned->getContent());

        // 2. Bearer token in Authorization header works (200)
        $this->app['auth']->forgetGuards();
        $responseBearer = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/strangers/2026/10/04/snap.jpg');
        $responseBearer->assertStatus(200);
        $responseBearer->assertHeader('Content-Type', 'image/jpeg');
        $this->assertEquals('fake-image-content', $responseBearer->getContent());

        // 3. Unsigned ?token= query parameter fails with 401 (SEC-14 deprecation of query-token auth)
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $responseQuery = $this->get('/api/media/strangers/2026/10/04/snap.jpg?token=' . $token);
        $responseQuery->assertStatus(401);
    }

    public function test_expired_and_tampered_signed_media_urls_return_401(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('strangers/2026/10/04/snap.jpg', 'fake-image-content');

        $storage = app(ImageStorageService::class);

        // Expired signature
        $expiredUrl = $storage->signedMediaUrl('strangers/2026/10/04/snap.jpg', -5);
        $this->app['auth']->forgetGuards();
        $responseExpired = $this->get($expiredUrl);
        $responseExpired->assertStatus(401);

        // Tampered signature
        $validUrl = $storage->signedMediaUrl('strangers/2026/10/04/snap.jpg', 60);
        $tamperedUrl = $validUrl . 'tampered';
        $this->app['auth']->forgetGuards();
        $responseTampered = $this->get($tamperedUrl);
        $responseTampered->assertStatus(401);
    }

    public function test_access_log_and_stranger_snap_models_generate_temporary_signed_urls(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('verification_snaps/2026/10/04/test_snap.jpg', 'snap-image-bytes');
        Storage::disk('biometrics')->put('verification_scenes/2026/10/04/test_scene.jpg', 'scene-image-bytes');
        Storage::disk('biometrics')->put('strangers/2026/10/04/stranger_snap.jpg', 'stranger-snap-bytes');

        $log = new AccessLog([
            'device_id' => 'DEV-001',
            'snap_pic_url' => 'verification_snaps/2026/10/04/test_snap.jpg',
            'scene_pic_url' => 'verification_scenes/2026/10/04/test_scene.jpg',
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        $this->assertStringContainsString('signature=', $log->snap_pic_url);
        $this->assertStringContainsString('expires=', $log->snap_pic_url);
        $this->assertStringContainsString('signature=', $log->scene_pic_url);
        $this->assertStringContainsString('expires=', $log->scene_pic_url);

        // Verify the generated signed URL fetches the media directly
        $this->app['auth']->forgetGuards();
        $response = $this->get($log->snap_pic_url);
        $response->assertStatus(200);
        $this->assertEquals('snap-image-bytes', $response->getContent());

        $snap = new StrangerSnap([
            'device_id' => 'DEV-001',
            'snap_pic_url' => 'strangers/2026/10/04/stranger_snap.jpg',
            'scene_pic_url' => null,
            'captured_at' => now(),
        ]);

        $this->assertStringContainsString('signature=', $snap->snap_pic_url);
        $this->assertStringContainsString('expires=', $snap->snap_pic_url);
        $this->assertNull($snap->scene_pic_url);

        $this->app['auth']->forgetGuards();
        $snapResponse = $this->get($snap->snap_pic_url);
        $snapResponse->assertStatus(200);
        $this->assertEquals('stranger-snap-bytes', $snapResponse->getContent());
    }

    public function test_stranger_snap_and_scene_subfolders_are_allowed_in_get_media(): void
    {
        $storage = new ImageStorageService();

        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('stranger_snaps/2026/10/04/snap.jpg', 'snap-data');
        Storage::disk('biometrics')->put('stranger_scenes/2026/10/04/scene.jpg', 'scene-data');

        $snapMedia = $storage->getMedia('stranger_snaps/2026/10/04/snap.jpg');
        $this->assertNotNull($snapMedia);
        $this->assertEquals('snap-data', $snapMedia['content']);

        $sceneMedia = $storage->getMedia('stranger_scenes/2026/10/04/scene.jpg');
        $this->assertNotNull($sceneMedia);
        $this->assertEquals('scene-data', $sceneMedia['content']);
    }

    public function test_employee_attendance_summary_bola_protection_sec_11(): void
    {
        $shift = Shift::create([
            'name' => 'Standard Shift',
            'code' => 'STD-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        $emp1 = Employee::create([
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-BOLA-01',
            'first_name' => 'Alice',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-BOLA-02',
            'first_name' => 'Bob',
            'last_name' => 'Peer',
            'employment_status' => 'active',
        ]);

        $empRole = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true]);
        $selfservicePerm = Permission::firstOrCreate(['slug' => 'selfservice.view'], ['name' => 'View Self Service', 'group' => 'selfservice']);
        $empRole->permissions()->syncWithoutDetaching([$selfservicePerm->id]);

        $user1 = User::factory()->create(['is_active' => true]);
        $user1->roles()->sync([$empRole->id]);
        $emp1->update(['user_id' => $user1->id]);

        // User 1 cannot view Employee 2's attendance summary (BOLA/IDOR protection)
        Sanctum::actingAs($user1);
        $forbiddenResponse = $this->getJson("/api/employees/{$emp2->id}/attendance-summary");
        $forbiddenResponse->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. You may only view your own attendance summary.',
            ]);

        // User 1 CAN view their own attendance summary
        $ownResponse = $this->getJson("/api/employees/{$emp1->id}/attendance-summary");
        $ownResponse->assertStatus(200)
            ->assertJsonPath('employee_id', $emp1->id);

        // User without linked employee record receives 403
        $unlinkedUser = User::factory()->create(['is_active' => true]);
        $unlinkedUser->roles()->sync([$empRole->id]);
        Sanctum::actingAs($unlinkedUser);
        $unlinkedResponse = $this->getJson("/api/employees/{$emp1->id}/attendance-summary");
        $unlinkedResponse->assertStatus(403);

        // Super Admin can view any employee's attendance summary
        $adminUser = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrator', 'is_system' => true]);
        $adminUser->roles()->sync([$adminRole->id]);
        Sanctum::actingAs($adminUser);
        $adminResponse = $this->getJson("/api/employees/{$emp2->id}/attendance-summary");
        $adminResponse->assertStatus(200)
            ->assertJsonPath('employee_id', $emp2->id);
    }

    public function test_websocket_notification_broadcast_channel_isolation_sec_12(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $notifData = (object) [
            'id' => 'notif-uuid-1234',
            'user_id' => $user->id,
            'type' => 'disciplinary',
            'title' => 'Confidential Alert',
            'message' => 'Restricted security alert for this user only',
            'data' => null,
            'read_at' => null,
            'created_at' => now(),
        ];

        $event = new NotificationCreated($notifData);
        $channels = $event->broadcastOn();

        // Must broadcast strictly to per-user private channel
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals("private-notifications.{$user->id}", $channels[0]->name);
        $this->assertNotEquals('private-notifications', $channels[0]->name);

        // Channel authorization check
        \Illuminate\Support\Facades\Config::set('broadcasting.default', 'reverb');
        \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.key', 'test-key');
        \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.secret', 'test-secret');
        \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.app_id', 'test-app');
        require base_path('routes/channels.php');

        // Authorized user succeeds
        $this->actingAs($user);
        $authResponse = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user->id}",
            'socket_id' => '1234.5678',
        ]);
        $this->assertEquals(200, $authResponse->status());

        // Other user fails with 403
        $otherUser = User::factory()->create(['is_active' => true]);
        $this->actingAs($otherUser);
        $deniedResponse = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user->id}",
            'socket_id' => '1234.5678',
        ]);
        $this->assertEquals(403, $deniedResponse->status());

        // Global un-scoped notifications channel is rejected (403)
        $this->actingAs($user);
        $globalDeniedResponse = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications',
            'socket_id' => '1234.5678',
        ]);
        $this->assertEquals(403, $globalDeniedResponse->status());
    }
}
