<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        $response = $this->get('/api/media/strangers/2026/10/04/snap.jpg?token=' . $token);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertEquals('fake-image-content', $response->getContent());
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
}
