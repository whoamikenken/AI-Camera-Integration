<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationAndRbacTest extends TestCase
{
    use RefreshDatabase;

    public bool $disableAutoAuth = true;

    public function test_user_can_login_with_valid_credentials_and_receive_token(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonFragment(['email' => 'jane@example.com']);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_login_rate_limiting_triggers_after_multiple_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'ratelimit@example.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_authenticated_user_can_view_profile_and_permissions(): void
    {
        $role = Role::create(['name' => 'HR Manager', 'slug' => 'hr-manager']);
        $perm = Permission::create(['name' => 'View Employees', 'slug' => 'employees.view', 'group' => 'employees']);
        $role->permissions()->sync([$perm->id]);

        $user = User::factory()->create(['email' => 'hr@example.com']);
        $user->roles()->sync([$role->id]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(200)
            ->assertJsonFragment(['email' => 'hr@example.com']);

        $permResponse = $this->getJson('/api/auth/permissions');
        $permResponse->assertStatus(200)
            ->assertJsonFragment(['employees.view']);
    }

    public function test_authenticated_user_can_logout_and_token_is_revoked(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/logout');
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_unauthenticated_request_to_protected_routes_returns_401(): void
    {
        $response = $this->getJson('/api/devices');
        $response->assertStatus(401);
    }

    public function test_camera_webhooks_remain_accessible_without_authentication(): void
    {
        $response = $this->postJson('/api/Subscribe/heartbeat', [
            'facesluiceId' => 'CAM-TEST-001',
            'time' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200);
    }

    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'is_system' => true]);
        $user = User::factory()->create();
        $user->roles()->sync([$superAdminRole->id]);

        $this->assertTrue($user->hasPermission('any.nonexistent.permission'));
        $this->assertTrue($user->hasRole('super-admin'));
    }

    public function test_wildcard_permission_grants_access_to_sub_actions(): void
    {
        $role = Role::create(['name' => 'Attendance Admin', 'slug' => 'attendance-admin']);
        $perm = Permission::create(['name' => 'All Attendance', 'slug' => 'attendance.*', 'group' => 'attendance']);
        $role->permissions()->sync([$perm->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        $this->assertTrue($user->hasPermission('attendance.view'));
        $this->assertTrue($user->hasPermission('attendance.manage'));
        $this->assertFalse($user->hasPermission('employees.view'));
    }

    public function test_database_seeder_creates_all_roles_permissions_and_default_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->assertDatabaseHas('roles', ['slug' => 'super-admin']);
        $this->assertDatabaseHas('roles', ['slug' => 'hr-manager']);
        $this->assertDatabaseHas('roles', ['slug' => 'security']);
        $this->assertDatabaseHas('users', ['email' => 'admin@camera.hub']);
        $this->assertDatabaseHas('settings', ['key' => 'attendance.auto_process']);
    }

    public function test_organization_hierarchy_tree_structure(): void
    {
        $org = Organization::create([
            'name' => 'Global Corp',
            'code' => 'GLOBAL-01',
            'timezone' => 'Asia/Manila',
        ]);

        $parentDept = Department::create([
            'organization_id' => $org->id,
            'name' => 'Technology',
            'code' => 'TECH',
        ]);

        $childDept = Department::create([
            'organization_id' => $org->id,
            'name' => 'Software Engineering',
            'code' => 'SE',
            'parent_id' => $parentDept->id,
        ]);

        $descendants = $parentDept->getDescendantIds();
        $this->assertContains($childDept->id, $descendants);

        $ancestors = $childDept->getAncestors();
        $this->assertEquals($parentDept->id, $ancestors->first()->id);
    }

    public function test_department_circular_dependency_is_blocked(): void
    {
        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'is_system' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        Sanctum::actingAs($user, ['*']);

        $org = Organization::create([
            'name' => 'Global Corp',
            'code' => 'GLOBAL-01',
            'timezone' => 'Asia/Manila',
        ]);

        $parentDept = Department::create([
            'organization_id' => $org->id,
            'name' => 'Department A',
            'code' => 'DEPT-A',
        ]);

        $childDept = Department::create([
            'organization_id' => $org->id,
            'name' => 'Department B',
            'code' => 'DEPT-B',
            'parent_id' => $parentDept->id,
        ]);

        // Attempt to make parent's parent the child
        $response = $this->putJson("/api/departments/{$parentDept->id}", [
            'name' => 'Department A',
            'parent_id' => $childDept->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_setting_service_caching_and_fallback(): void
    {
        $org = Organization::create([
            'name' => 'Global Corp',
            'code' => 'GLOBAL-01',
        ]);

        Setting::create([
            'group' => 'attendance',
            'key' => 'attendance.late_grace_minutes',
            'value' => '15',
            'type' => 'integer',
        ]);

        // Global fallback
        $val = SettingService::get('attendance.late_grace_minutes', 0, $org->id);
        $this->assertEquals(15, $val);

        // Org override
        SettingService::set('attendance.late_grace_minutes', 25, $org->id);
        $orgVal = SettingService::get('attendance.late_grace_minutes', 0, $org->id);
        $this->assertEquals(25, $orgVal);

        // Another org gets global
        $globalVal = SettingService::get('attendance.late_grace_minutes', 0, 9999);
        $this->assertEquals(15, $globalVal);
    }

    public function test_audit_log_records_model_changes(): void
    {
        $org = Organization::create([
            'name' => 'Global Corp',
            'code' => 'GLOBAL-01',
        ]);

        $location = Location::create([
            'organization_id' => $org->id,
            'name' => 'Main Gate',
            'code' => 'GATE-1',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'auditable_type' => Location::class,
            'auditable_id' => (string) $location->id,
        ]);

        $location->update(['name' => 'Updated Main Gate']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'auditable_type' => Location::class,
            'auditable_id' => (string) $location->id,
        ]);
    }
}
