<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Challenger2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1. Department Tree Cycle Attacks
    // =========================================================================

    public function test_department_cannot_be_its_own_parent(): void
    {
        $org = Organization::create([
            'name' => 'Org A',
            'code' => 'ORG-A',
        ]);

        $dept = Department::create([
            'organization_id' => $org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $response = $this->putJson("/api/departments/{$dept->id}", [
            'name' => 'Engineering',
            'parent_id' => $dept->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'A department cannot be its own parent.',
            ]);
    }

    public function test_department_direct_2_node_circular_loop_rejected(): void
    {
        $org = Organization::create([
            'name' => 'Org A',
            'code' => 'ORG-A',
        ]);

        $deptA = Department::create([
            'organization_id' => $org->id,
            'name' => 'Dept A',
            'code' => 'A',
        ]);

        $deptB = Department::create([
            'organization_id' => $org->id,
            'name' => 'Dept B',
            'code' => 'B',
            'parent_id' => $deptA->id,
        ]);

        // Attempt A -> B (since B already has parent A, this creates A -> B -> A)
        $response = $this->putJson("/api/departments/{$deptA->id}", [
            'name' => 'Dept A',
            'parent_id' => $deptB->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'Circular dependency detected: cannot set parent to one of its sub-departments.',
            ]);
    }

    public function test_department_indirect_3_node_circular_loop_rejected(): void
    {
        $org = Organization::create([
            'name' => 'Org A',
            'code' => 'ORG-A',
        ]);

        $deptA = Department::create([
            'organization_id' => $org->id,
            'name' => 'Dept A',
            'code' => 'A',
        ]);

        $deptB = Department::create([
            'organization_id' => $org->id,
            'name' => 'Dept B',
            'code' => 'B',
            'parent_id' => $deptA->id,
        ]);

        $deptC = Department::create([
            'organization_id' => $org->id,
            'name' => 'Dept C',
            'code' => 'C',
            'parent_id' => $deptB->id,
        ]);

        // Attempt A -> C (creating A -> B -> C -> A)
        $response = $this->putJson("/api/departments/{$deptA->id}", [
            'name' => 'Dept A',
            'parent_id' => $deptC->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'Circular dependency detected: cannot set parent to one of its sub-departments.',
            ]);
    }

    public function test_department_deep_5_node_circular_loop_rejected(): void
    {
        $org = Organization::create([
            'name' => 'Org A',
            'code' => 'ORG-A',
        ]);

        $d1 = Department::create(['organization_id' => $org->id, 'name' => 'D1', 'code' => 'D1']);
        $d2 = Department::create(['organization_id' => $org->id, 'name' => 'D2', 'code' => 'D2', 'parent_id' => $d1->id]);
        $d3 = Department::create(['organization_id' => $org->id, 'name' => 'D3', 'code' => 'D3', 'parent_id' => $d2->id]);
        $d4 = Department::create(['organization_id' => $org->id, 'name' => 'D4', 'code' => 'D4', 'parent_id' => $d3->id]);
        $d5 = Department::create(['organization_id' => $org->id, 'name' => 'D5', 'code' => 'D5', 'parent_id' => $d4->id]);

        // Attempt to make D2's parent D5 (creating D2 -> D3 -> D4 -> D5 -> D2)
        $response = $this->putJson("/api/departments/{$d2->id}", [
            'name' => 'D2',
            'parent_id' => $d5->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'Circular dependency detected: cannot set parent to one of its sub-departments.',
            ]);

        // Root D1 attempted parent D5
        $responseRoot = $this->putJson("/api/departments/{$d1->id}", [
            'name' => 'D1',
            'parent_id' => $d5->id,
        ]);

        $responseRoot->assertStatus(422);
    }

    public function test_department_legitimate_hierarchy_reassignment_succeeds(): void
    {
        $org = Organization::create([
            'name' => 'Org A',
            'code' => 'ORG-A',
        ]);

        $d1 = Department::create(['organization_id' => $org->id, 'name' => 'D1', 'code' => 'D1']);
        $d2 = Department::create(['organization_id' => $org->id, 'name' => 'D2', 'code' => 'D2', 'parent_id' => $d1->id]);
        $d3 = Department::create(['organization_id' => $org->id, 'name' => 'D3', 'code' => 'D3']);

        // Move D2 to have D3 as parent (legitimate reassignment, no cycle)
        $response = $this->putJson("/api/departments/{$d2->id}", [
            'name' => 'D2',
            'parent_id' => $d3->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals($d3->id, $d2->fresh()->parent_id);
    }

    // =========================================================================
    // 2. Settings Cache Invalidation and Concurrent Updates
    // =========================================================================

    public function test_settings_cache_invalidates_immediately_on_update(): void
    {
        Cache::flush();

        SettingService::set('attendance.grace_period', 10, null, 'integer');

        // First read populates cache
        $read1 = SettingService::get('attendance.grace_period');
        $this->assertEquals(10, $read1);
        $this->assertEquals(10, Cache::get('settings.global.attendance.grace_period'));

        // Update setting to 25
        SettingService::set('attendance.grace_period', 25, null, 'integer');

        // Cache must have been cleared
        $this->assertNull(Cache::get('settings.global.attendance.grace_period'));

        // Next read must immediately return 25 without stale read
        $read2 = SettingService::get('attendance.grace_period');
        $this->assertEquals(25, $read2);
    }

    public function test_settings_tenant_override_isolation_and_fallback(): void
    {
        Cache::flush();

        $org1 = Organization::create(['name' => 'Org 1', 'code' => 'ORG-1']);
        $org2 = Organization::create(['name' => 'Org 2', 'code' => 'ORG-2']);

        // Set global setting
        SettingService::set('visitor.require_host_approval', true, null, 'boolean');

        // Org 1 and Org 2 read global
        $this->assertTrue(SettingService::get('visitor.require_host_approval', false, $org1->id));
        $this->assertTrue(SettingService::get('visitor.require_host_approval', false, $org2->id));

        // Org 1 overrides to false
        SettingService::set('visitor.require_host_approval', false, $org1->id, 'boolean');

        // Org 1 must read false immediately
        $this->assertFalse(SettingService::get('visitor.require_host_approval', true, $org1->id));

        // Org 2 must still read true (global)
        $this->assertTrue(SettingService::get('visitor.require_host_approval', false, $org2->id));

        // Reset Org 1 settings
        SettingService::reset('visitor', $org1->id);

        // Org 1 must now fall back to global true immediately
        $this->assertTrue(SettingService::get('visitor.require_host_approval', false, $org1->id));
    }

    public function test_settings_rapid_consecutive_updates_never_return_stale_cache(): void
    {
        Cache::flush();

        $key = 'stress.test.counter';

        for ($i = 1; $i <= 30; $i++) {
            $val = "iteration-{$i}-".Str::random(8);
            SettingService::set($key, $val);

            $read = SettingService::get($key);
            $this->assertEquals($val, $read, "Stale cache detected at iteration {$i}");
        }
    }

    public function test_settings_api_bulk_update_invalidates_all_keys(): void
    {
        Cache::flush();

        // Seed 3 keys
        SettingService::set('bulk.key_a', 'initial_a');
        SettingService::set('bulk.key_b', 'initial_b');
        SettingService::set('bulk.key_c', 'initial_c');

        // Cache them
        $this->assertEquals('initial_a', SettingService::get('bulk.key_a'));
        $this->assertEquals('initial_b', SettingService::get('bulk.key_b'));
        $this->assertEquals('initial_c', SettingService::get('bulk.key_c'));

        // Bulk update via API
        $response = $this->putJson('/api/settings', [
            'settings' => [
                'bulk.key_a' => 'updated_a',
                'bulk.key_b' => 'updated_b',
                'bulk.key_c' => 'updated_c',
            ],
        ]);

        $response->assertStatus(200);

        // Direct get must reflect all 3 immediately
        $this->assertEquals('updated_a', SettingService::get('bulk.key_a'));
        $this->assertEquals('updated_b', SettingService::get('bulk.key_b'));
        $this->assertEquals('updated_c', SettingService::get('bulk.key_c'));
    }

    // =========================================================================
    // 3. Audit Log Sensitive Data Leak Prevention
    // =========================================================================

    public function test_audit_log_user_creation_does_not_leak_plaintext_password(): void
    {
        $plainPassword = 'SuperSecretPlainPassword123!';

        $user = User::create([
            'name' => 'Security Sensitive User',
            'email' => 'sensitive@example.com',
            'password' => $plainPassword,
        ]);

        $logs = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', (string) $user->id)
            ->where('action', 'create')
            ->get();

        $this->assertNotEmpty($logs, 'Audit log was not created for User creation.');

        foreach ($logs as $log) {
            $rawJson = json_encode($log->toArray());
            $this->assertStringNotContainsString(
                $plainPassword,
                $rawJson,
                'Plaintext password leaked into audit log JSON representation!'
            );

            if ($log->new_values) {
                $this->assertArrayNotHasKey(
                    'password',
                    $log->new_values,
                    'Password key should be excluded from audit log new_values.'
                );
            }
        }
    }

    public function test_audit_log_password_update_does_not_leak_password_in_diff(): void
    {
        $oldPassword = 'OldSecretPassword123!';
        $newPassword = 'NewSecretPassword456!';

        $user = User::create([
            'name' => 'Password Changer',
            'email' => 'changer@example.com',
            'password' => $oldPassword,
        ]);

        // Change password along with another audited field (e.g. name)
        $user->update([
            'name' => 'Password Changer Updated',
            'password' => Hash::make($newPassword),
        ]);

        $logs = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', (string) $user->id)
            ->where('action', 'update')
            ->get();

        $this->assertNotEmpty($logs, 'Audit log should record the update when audited attributes change.');

        foreach ($logs as $log) {
            $rawJson = json_encode($log->toArray());
            $this->assertStringNotContainsString(
                $oldPassword,
                $rawJson,
                'Old plaintext password found in audit log!'
            );
            $this->assertStringNotContainsString(
                $newPassword,
                $rawJson,
                'New plaintext password found in audit log!'
            );

            if ($log->new_values) {
                $this->assertArrayNotHasKey('password', $log->new_values);
                $this->assertArrayHasKey('name', $log->new_values);
            }
            if ($log->old_values) {
                $this->assertArrayNotHasKey('password', $log->old_values);
                $this->assertArrayHasKey('name', $log->old_values);
            }
        }
    }

    public function test_audit_log_remember_token_and_secrets_are_excluded(): void
    {
        $user = User::create([
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => 'secret123',
        ]);

        $token = Str::random(60);
        $user->update([
            'name' => 'Token User Updated',
            'remember_token' => $token,
        ]);

        $logs = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', (string) $user->id)
            ->where('action', 'update')
            ->get();

        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $rawJson = json_encode($log->toArray());
            $this->assertStringNotContainsString($token, $rawJson);

            if ($log->new_values) {
                $this->assertArrayNotHasKey('remember_token', $log->new_values);
                $this->assertArrayHasKey('name', $log->new_values);
            }
        }
    }

    public function test_audit_log_device_password_is_excluded_from_audit_diff(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-TEST-AUDIT',
            'name' => 'Audit Cam',
            'ip_address' => '192.168.1.200',
            'username' => 'admin',
            'password' => 'CameraHardwareSecretPass999!',
        ]);

        $logs = AuditLog::where('auditable_type', Device::class)
            ->where('auditable_id', (string) $device->id)
            ->get();

        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $rawJson = json_encode($log->toArray());
            $this->assertStringNotContainsString(
                'CameraHardwareSecretPass999!',
                $rawJson,
                'Device plaintext hardware password leaked into audit log!'
            );
        }
    }

    // =========================================================================
    // 4. Multi-Tenant Location & Department Scoping
    // =========================================================================

    public function test_department_cannot_have_parent_from_another_organization_on_store(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'code' => 'ORG-A']);
        $orgB = Organization::create(['name' => 'Org B', 'code' => 'ORG-B']);

        $deptA = Department::create([
            'organization_id' => $orgA->id,
            'name' => 'Dept Org A',
            'code' => 'DEPT-A',
        ]);

        // Attempt to create a department in Org B with parent in Org A
        $response = $this->postJson('/api/departments', [
            'organization_id' => $orgB->id,
            'name' => 'Dept Org B',
            'code' => 'DEPT-B',
            'parent_id' => $deptA->id,
        ]);

        // Assert 422 to prevent cross-tenant tree leakage
        $response->assertStatus(422);
    }

    public function test_department_cannot_have_parent_from_another_organization_on_update(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'code' => 'ORG-A']);
        $orgB = Organization::create(['name' => 'Org B', 'code' => 'ORG-B']);

        $deptA = Department::create([
            'organization_id' => $orgA->id,
            'name' => 'Dept Org A',
            'code' => 'DEPT-A',
        ]);

        $deptB = Department::create([
            'organization_id' => $orgB->id,
            'name' => 'Dept Org B',
            'code' => 'DEPT-B',
        ]);

        // Attempt to update Dept B's parent to Dept A (cross-tenant)
        $response = $this->putJson("/api/departments/{$deptB->id}", [
            'name' => 'Dept Org B',
            'parent_id' => $deptA->id,
        ]);

        // Assert 422 to prevent cross-tenant tree leakage
        $response->assertStatus(422);
    }

    public function test_location_cannot_be_reassigned_to_another_organization_via_update(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'code' => 'ORG-A']);
        $orgB = Organization::create(['name' => 'Org B', 'code' => 'ORG-B']);

        $location = Location::create([
            'organization_id' => $orgA->id,
            'name' => 'HQ Building',
            'code' => 'HQ-A',
        ]);

        // Attempt to move location from Org A to Org B
        $response = $this->putJson("/api/locations/{$location->id}", [
            'name' => 'HQ Building',
            'organization_id' => $orgB->id,
        ]);

        $response->assertStatus(200);

        // Verify organization_id was NOT changed
        $this->assertEquals(
            $orgA->id,
            $location->fresh()->organization_id,
            'Location organization_id was unexpectedly mutated via API update!'
        );
    }

    // =========================================================================
    // 5. Device Role / Direction Edge Cases & Validation
    // =========================================================================

    public function test_device_role_validation_rejects_invalid_roles(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-ROLE-TEST-01',
            'name' => 'Test Cam',
            'ip_address' => '192.168.1.105',
        ]);

        // Attempt to set invalid device role
        $response = $this->putJson("/api/devices/{$device->id}", [
            'name' => 'Test Cam',
            'ip_address' => '192.168.1.105',
            'device_role' => 'invalid_role_xyz',
        ]);

        // Should return 422 Unprocessable Entity due to validation failure
        $response->assertStatus(422);
    }

    public function test_device_role_validation_accepts_all_valid_roles(): void
    {
        $validRoles = ['entry', 'exit', 'bidirectional', 'visitor_kiosk'];

        $device = Device::create([
            'device_id' => 'CAM-ROLE-TEST-02',
            'name' => 'Test Cam 2',
            'ip_address' => '192.168.1.106',
        ]);

        foreach ($validRoles as $role) {
            $response = $this->putJson("/api/devices/{$device->id}", [
                'name' => 'Test Cam 2',
                'ip_address' => '192.168.1.106',
                'device_role' => $role,
            ]);

            $response->assertStatus(200);
            $this->assertEquals($role, $device->fresh()->device_role, "Failed to persist valid device role '{$role}' via API update!");
        }
    }

    // =========================================================================
    // 6. RBAC Permission Middleware Guarding on API Routes
    // =========================================================================

    public function test_unprivileged_employee_cannot_access_settings_api(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $employeeUser = User::factory()->create([
            'is_active' => true,
        ]);
        $employeeUser->roles()->sync([$employeeRole->id]);

        Sanctum::actingAs($employeeUser, ['*']);

        // An employee without settings.manage should NOT be able to update settings
        $response = $this->putJson('/api/settings', [
            'settings' => [
                'attendance.auto_process' => false,
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_unprivileged_employee_cannot_delete_organization(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::create([
            'name' => 'Victim Org',
            'code' => 'VICTIM-ORG',
        ]);

        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $employeeUser = User::factory()->create([
            'is_active' => true,
        ]);
        $employeeUser->roles()->sync([$employeeRole->id]);

        Sanctum::actingAs($employeeUser, ['*']);

        // An employee without org.delete / org.manage should NOT be able to delete organizations
        $response = $this->deleteJson("/api/organizations/{$org->id}");

        $response->assertStatus(403);
    }
}
