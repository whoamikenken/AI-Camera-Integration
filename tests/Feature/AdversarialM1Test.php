<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialM1Test extends TestCase
{
    use RefreshDatabase;

    public bool $disableAutoAuth = true;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(RateLimiter::availableIn(''));
        Storage::fake('public');
    }

    /*
    |--------------------------------------------------------------------------
    | Area 1: Login Rate-Limiting and Brute-Force Protection
    |--------------------------------------------------------------------------
    */

    public function test_login_rate_limiting_enforces_lockout_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'WrongPassword' . $i,
            ]);
            $this->assertEquals(401, $response->status(), "Attempt {$i} should fail with 401");
        }

        // 6th attempt should be blocked by rate limiter
        $response = $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'WrongPassword6',
        ]);

        $this->assertEquals(429, $response->status(), '6th attempt must be throttled with HTTP 429');
        $response->assertJsonFragment(['success' => false]);
    }

    public function test_login_rate_limiting_is_case_insensitive(): void
    {
        $user = User::factory()->create([
            'email' => 'casevictim@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => true,
        ]);

        // Alternate casing across 5 attempts
        $emails = [
            'casevictim@example.com',
            'CASEVICTIM@example.com',
            'CaseVictim@example.com',
            'CASEvictim@EXAMPLE.com',
            'caseVICTIM@example.COM',
        ];

        foreach ($emails as $email) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'WrongPassword',
            ]);
        }

        // Next attempt with original casing must be blocked
        $response = $this->postJson('/api/auth/login', [
            'email' => 'casevictim@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        $this->assertEquals(429, $response->status(), 'Rate limiter must normalize email casing to prevent brute-force bypass');
    }

    public function test_successful_login_clears_rate_limiter_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'resetvictim@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => true,
        ]);

        // 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'resetvictim@example.com',
                'password' => 'wrong',
            ]);
        }

        // 4th attempt is successful
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'resetvictim@example.com',
            'password' => 'CorrectPassword123!',
        ]);
        $this->assertEquals(200, $loginRes->status());

        // Now next 4 failed attempts should not trigger 429 because counter was cleared
        for ($i = 0; $i < 4; $i++) {
            $res = $this->postJson('/api/auth/login', [
                'email' => 'resetvictim@example.com',
                'password' => 'wrong_again',
            ]);
            $this->assertEquals(401, $res->status());
        }
    }

    public function test_login_ip_wide_rate_limiting_across_different_emails(): void
    {
        // Route has middleware('throttle:10,1')
        // Attacker sprays 10 different email addresses from the same IP
        for ($i = 1; $i <= 10; $i++) {
            $res = $this->postJson('/api/auth/login', [
                'email' => "spray_{$i}@example.com",
                'password' => 'password123',
            ]);
            $this->assertEquals(401, $res->status(), "Spray attempt {$i} should return 401");
        }

        // 11th attempt from same IP must be throttled by route-level throttle:10,1
        $res11 = $this->postJson('/api/auth/login', [
            'email' => 'spray_11@example.com',
            'password' => 'password123',
        ]);

        $this->assertEquals(429, $res11->status(), 'Route-level throttle:10,1 must block 11th request across different emails from the same IP');
    }

    public function test_login_validation_rejects_malformed_and_empty_payloads(): void
    {
        $response = $this->postJson('/api/auth/login', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    /*
    |--------------------------------------------------------------------------
    | Area 2: Privilege Escalation Attempts on Guarded Endpoints
    |--------------------------------------------------------------------------
    */

    public function test_unprivileged_employee_cannot_create_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->postJson('/api/roles', [
            'name' => 'Escalated Super Admin',
            'slug' => 'escalated-super-admin',
            'description' => 'Created by low-privilege employee',
        ]);

        // Security requirement: Non-admin users must be rejected with 403 Forbidden
        // If it returns 201, this proves privilege escalation vulnerability
        $this->assertEquals(403, $response->status(), "Unprivileged employee must receive HTTP 403 when attempting to create roles, but received {$response->status()}: " . $response->getContent());
    }

    public function test_unprivileged_employee_cannot_modify_role_details(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        // Attacker attempts to modify the employee role
        $response = $this->putJson("/api/roles/{$employeeRole->id}", [
            'name' => 'Employee With Modified Name',
            'description' => 'Tampered by unprivileged employee',
        ]);

        // Security requirement: Must be rejected with 403 Forbidden
        // If it returns 200, this proves privilege escalation / unauthorized modification vulnerability
        $this->assertEquals(403, $response->status(), "Unprivileged employee must receive HTTP 403 when attempting to modify role details, but received {$response->status()}: " . $response->getContent());
    }

    public function test_role_controller_validation_defect_makes_permissions_assignment_impossible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // Super-admin attempts to assign permissions using permission slugs
        $superAdmin = User::where('email', 'admin@camera.hub')->firstOrFail();
        Sanctum::actingAs($superAdmin, ['*']);

        $role = Role::where('slug', 'employee')->firstOrFail();

        // 1. Sending strings (e.g. ['selfservice.view']) succeeds with 200
        $stringRes = $this->putJson("/api/roles/{$role->id}", [
            'permissions' => ['selfservice.view'],
        ]);
        $this->assertEquals(200, $stringRes->status());

        // 2. Sending integers (e.g. [1]) succeeds with 200
        $intRes = $this->putJson("/api/roles/{$role->id}", [
            'permissions' => [1],
        ]);
        $this->assertEquals(200, $intRes->status());
    }

    public function test_unprivileged_employee_cannot_delete_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $customRole = Role::create([
            'name' => 'Custom Role',
            'slug' => 'custom-role',
            'is_system' => false,
        ]);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->deleteJson("/api/roles/{$customRole->id}");

        // Security requirement: Must be rejected with 403 Forbidden
        $this->assertEquals(403, $response->status(), 'Unprivileged employee must receive HTTP 403 when attempting to delete roles');
    }

    public function test_unprivileged_employee_cannot_modify_system_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->putJson('/api/settings', [
            'settings' => [
                'attendance.auto_process' => false,
            ],
        ]);

        // Security requirement: Must be rejected with 403 Forbidden
        $this->assertEquals(403, $response->status(), 'Unprivileged employee must receive HTTP 403 when attempting to update settings');
    }

    public function test_unprivileged_employee_cannot_view_audit_logs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->getJson('/api/audit-logs');

        // Security requirement: Must be rejected with 403 Forbidden
        $this->assertEquals(403, $response->status(), 'Unprivileged employee must receive HTTP 403 when attempting to view audit logs');
    }

    public function test_unprivileged_employee_cannot_delete_devices(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $device = Device::create([
            'device_id' => 'CAM-ESC-001',
            'name' => 'High Security Camera',
            'ip_address' => '192.168.1.150',
            'is_active' => true,
        ]);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->deleteJson("/api/devices/{$device->id}");

        // Security requirement: Must be rejected with 403 Forbidden
        $this->assertEquals(403, $response->status(), 'Unprivileged employee must receive HTTP 403 when attempting to delete devices');
    }

    public function test_unprivileged_employee_cannot_create_organizations(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $employeeUser = User::where('email', 'employee@camera.hub')->firstOrFail();
        Sanctum::actingAs($employeeUser, ['*']);

        $response = $this->postJson('/api/organizations', [
            'name' => 'Rogue Organization',
            'code' => 'ROGUE-01',
            'timezone' => 'UTC',
        ]);

        // Security requirement: Must be rejected with 403 Forbidden
        $this->assertEquals(403, $response->status(), 'Unprivileged employee must receive HTTP 403 when attempting to create organizations');
    }

    /*
    |--------------------------------------------------------------------------
    | Area 3: Expired / Forged Bearer Tokens
    |--------------------------------------------------------------------------
    */

    public function test_request_with_forged_bearer_token_is_rejected_with_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer 9999|completelyForgedRandomTokenString1234567890')
            ->getJson('/api/devices');

        $this->assertEquals(401, $response->status(), 'Forged token must return 401');
    }

    public function test_request_with_expired_bearer_token_is_rejected_with_401(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Issue token and artificially expire it in personal_access_tokens table
        $newAccessToken = $user->createToken('test-device');
        $plainToken = $newAccessToken->plainTextToken;

        // Set expires_at in the past
        $newAccessToken->accessToken->update([
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/auth/me');

        $this->assertEquals(401, $response->status(), 'Expired token must return 401 Unauthenticated');
    }

    public function test_request_with_revoked_bearer_token_is_rejected_with_401(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $newAccessToken = $user->createToken('test-device');
        $plainToken = $newAccessToken->plainTextToken;

        // Verify token works
        $okRes = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/auth/me');
        $this->assertEquals(200, $okRes->status());

        // Revoke token (delete from personal_access_tokens)
        $newAccessToken->accessToken->delete();

        // Clear cached auth guards in current request cycle so Sanctum re-evaluates database token
        $this->app['auth']->forgetGuards();

        // Second request with revoked token must fail with 401
        $revokedRes = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/auth/me');
        $this->assertEquals(401, $revokedRes->status(), 'Revoked token must return 401');
    }

    public function test_request_with_malformed_auth_header_is_rejected_with_401(): void
    {
        $badHeaders = [
            'Bearer',
            'Bearer ',
            'Token 123456',
            'Basic YWRtaW46cGFzc3dvcmQ=',
            'Bearer invalid.jwt.structure',
        ];

        foreach ($badHeaders as $header) {
            $response = $this->withHeader('Authorization', $header)->getJson('/api/devices');
            $this->assertEquals(401, $response->status(), "Header '{$header}' must return 401");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Area 4: Camera Webhook Payload Validation Without Auth
    |--------------------------------------------------------------------------
    */

    public function test_camera_heartbeat_arbitrary_device_injection(): void
    {
        // Unauthenticated attacker probes heartbeat with arbitrary rogue device ID
        $response = $this->postJson('/api/Subscribe/heartbeat', [
            'DeviceID' => 'ROGUE-CAMERA-999',
            'info' => [
                'facesname' => 'Injected Attacker Camera',
            ],
        ]);

        $this->assertEquals(200, $response->status());
        // Verify whether arbitrary device was injected into database without authentication
        $deviceExists = Device::where('device_id', 'ROGUE-CAMERA-999')->exists();
        $this->assertTrue($deviceExists, 'Empirically verify if unauthenticated heartbeat auto-provisions devices');
    }

    public function test_camera_heartbeat_rejects_or_crashes_on_oversized_device_id(): void
    {
        $oversizedDeviceId = str_repeat('A', 1000); // 1000 characters (schema max is 64)

        $response = $this->postJson('/api/Subscribe/heartbeat', [
            'DeviceID' => $oversizedDeviceId,
            'info' => [
                'facesname' => 'Oversized Camera',
            ],
        ]);

        // If unvalidated, SQL query exception causes 500
        $this->assertNotEquals(500, $response->status(), 'Oversized device_id in webhook must not trigger unhandled 500 DB crash');
    }

    public function test_camera_webhook_with_nonexistent_device_id_causes_foreign_key_crash(): void
    {
        // Webhook received for non-existent device ID
        $response = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'NON-EXISTENT-DEVICE-ID-999',
            'info' => [
                'PersonID' => 123,
                'VerifyStatus' => 1,
            ],
        ]);

        // SEC-01: Unregistered camera must be rejected with 401/403 and must NOT crash with 500
        $this->assertContains($response->status(), [401, 403], 'Unregistered camera should be rejected with 401/403');
        $this->assertDatabaseMissing('devices', ['device_id' => 'NON-EXISTENT-DEVICE-ID-999']);
    }

    public function test_camera_webhook_arbitrary_file_extension_upload(): void
    {
        // Ensure device exists so FK check passes
        Device::create([
            'device_id' => 'CAM-TEST-001',
            'name' => 'Test Camera',
            'ip_address' => '192.168.1.100',
            'is_active' => true,
        ]);

        // Attacker sends webhook with a PHP file base64 data URI
        $phpCode = '<?php phpinfo(); ?>';
        $payload = [
            'DeviceID' => 'CAM-TEST-001',
            'SnapPic' => 'data:image/php;base64,' . base64_encode($phpCode),
        ];

        $response = $this->postJson('/api/Subscribe/Snap', $payload);
        $this->assertEquals(200, $response->status());

        // Check if any .php file was written to public storage
        $files = Storage::disk('public')->allFiles();
        $phpFiles = array_filter($files, fn ($file) => str_ends_with($file, '.php'));

        $this->assertEmpty($phpFiles, 'Arbitrary .php file must NOT be written to public storage via base64 upload, but found: ' . implode(', ', $phpFiles));
    }

    /*
    |--------------------------------------------------------------------------
    | Area 5: Deactivated User Lockout
    |--------------------------------------------------------------------------
    */

    public function test_deactivated_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'locked@example.com',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'locked@example.com',
            'password' => 'password123',
        ]);

        $this->assertEquals(403, $response->status(), 'Deactivated user must receive 403 on login');
        $response->assertJsonFragment(['success' => false]);
    }

    public function test_deactivated_user_with_existing_valid_token_is_immediately_locked_out(): void
    {
        $user = User::factory()->create([
            'name' => 'Active Then Deactivated',
            'email' => 'active-then-deactivated@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        // Log in while active, get valid token
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'active-then-deactivated@example.com',
            'password' => 'password123',
        ]);
        $this->assertEquals(200, $loginRes->status());
        $token = $loginRes->json('token');
        $this->assertNotEmpty($token);

        // Verify token works on guarded endpoint
        $profileRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');
        $this->assertEquals(200, $profileRes->status());

        // Administrator deactivates user
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        // Immediate subsequent request with existing token to /api/auth/me
        $blockedMeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');

        // Immediate subsequent request with existing token to /api/devices
        $blockedDevicesRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/devices');

        // Security requirement: Existing token must NOT grant access once account is deactivated!
        $this->assertEquals(403, $blockedMeRes->status(), 'Deactivated user with active token must be locked out of /api/auth/me with 403');
        $this->assertEquals(403, $blockedDevicesRes->status(), 'Deactivated user with active token must be locked out of /api/devices with 403');
    }
}
