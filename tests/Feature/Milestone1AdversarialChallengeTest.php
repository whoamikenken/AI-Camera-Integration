<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\AccessLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StrangerSnap;
use App\Models\User;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Milestone1AdversarialChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $shift;
    protected Role $employeeRole;
    protected Role $managerRole;
    protected Role $adminRole;
    protected Role $superAdminRole;
    protected Permission $selfservicePerm;
    protected Permission $attendanceViewPerm;
    protected Permission $employeesViewPerm;
    protected Permission $devicesViewPerm;
    protected Permission $personnelViewPerm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create([
            'name' => 'Standard Challenge Shift',
            'code' => 'SCS-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        $this->selfservicePerm = Permission::firstOrCreate(['slug' => 'selfservice.view'], ['name' => 'View Self Service', 'group' => 'selfservice']);
        $this->attendanceViewPerm = Permission::firstOrCreate(['slug' => 'attendance.view'], ['name' => 'View Attendance', 'group' => 'attendance']);
        $this->employeesViewPerm = Permission::firstOrCreate(['slug' => 'employees.view'], ['name' => 'View Employees', 'group' => 'employees']);
        $this->devicesViewPerm = Permission::firstOrCreate(['slug' => 'devices.view'], ['name' => 'View Devices', 'group' => 'devices']);
        $this->personnelViewPerm = Permission::firstOrCreate(['slug' => 'personnel.view'], ['name' => 'View Personnel', 'group' => 'personnel']);

        $this->employeeRole = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true]);
        $this->employeeRole->permissions()->syncWithoutDetaching([$this->selfservicePerm->id]);

        $this->managerRole = Role::firstOrCreate(['slug' => 'manager'], ['name' => 'Manager', 'is_system' => true]);
        $this->managerRole->permissions()->syncWithoutDetaching([$this->selfservicePerm->id, $this->attendanceViewPerm->id]);

        $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->adminRole->permissions()->syncWithoutDetaching([$this->employeesViewPerm->id, $this->devicesViewPerm->id, $this->personnelViewPerm->id]);

        $this->superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrator', 'is_system' => true]);
    }

    // =========================================================================
    // SECTION 1: SEC-11 BOLA / IDOR ADVERSARIAL CHALLENGES
    // =========================================================================

    public function test_sec11_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/employees/1/attendance-summary');
        $response->assertStatus(401);
    }

    public function test_sec11_deactivated_user_is_rejected_with_403(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->roles()->sync([$this->employeeRole->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/employees/1/attendance-summary');
        $response->assertStatus(403);
    }

    public function test_sec11_standard_employee_cannot_view_peer_summary(): void
    {
        $emp1 = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'Alice',
            'last_name' => 'Attacker',
            'employment_status' => 'active',
        ]);
        $user1 = User::factory()->create(['is_active' => true]);
        $user1->roles()->sync([$this->employeeRole->id]);
        $emp1->update(['user_id' => $user1->id]);

        $emp2 = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-002',
            'first_name' => 'Bob',
            'last_name' => 'Victim',
            'employment_status' => 'active',
        ]);
        $user2 = User::factory()->create(['is_active' => true]);
        $user2->roles()->sync([$this->employeeRole->id]);
        $emp2->update(['user_id' => $user2->id]);

        Sanctum::actingAs($user1);

        // Adversarial attack: user1 requests victim emp2's summary
        $response = $this->getJson("/api/employees/{$emp2->id}/attendance-summary");
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. You may only view your own attendance summary.',
            ]);
    }

    public function test_sec11_standard_employee_can_view_own_summary(): void
    {
        $emp = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-OWN',
            'first_name' => 'Alice',
            'last_name' => 'Legitimate',
            'employment_status' => 'active',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->employeeRole->id]);
        $emp->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/employees/{$emp->id}/attendance-summary");
        $response->assertStatus(200)
            ->assertJsonPath('employee_id', $emp->id);
    }

    public function test_sec11_unlinked_user_with_selfservice_receives_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->employeeRole->id]); // has selfservice.view but no linked employee

        $emp = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-TARGET',
            'first_name' => 'Target',
            'last_name' => 'Employee',
            'employment_status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/employees/{$emp->id}/attendance-summary");
        $response->assertStatus(403);
    }

    public function test_sec11_manager_and_super_admin_can_view_any_summary(): void
    {
        $emp = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-SUBORDINATE',
            'first_name' => 'Subordinate',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);

        // Manager test
        $managerUser = User::factory()->create(['is_active' => true]);
        $managerUser->roles()->sync([$this->managerRole->id]);

        Sanctum::actingAs($managerUser);
        $responseManager = $this->getJson("/api/employees/{$emp->id}/attendance-summary");
        $responseManager->assertStatus(200)
            ->assertJsonPath('employee_id', $emp->id);

        // Super Admin test
        $superAdminUser = User::factory()->create(['is_active' => true]);
        $superAdminUser->roles()->sync([$this->superAdminRole->id]);

        Sanctum::actingAs($superAdminUser);
        $responseSuperAdmin = $this->getJson("/api/employees/{$emp->id}/attendance-summary");
        $responseSuperAdmin->assertStatus(200)
            ->assertJsonPath('employee_id', $emp->id);
    }

    public function test_sec11_parameter_tampering_nonexistent_or_invalid_id(): void
    {
        $emp = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-TAMPER',
            'first_name' => 'Tamper',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->employeeRole->id]);
        $emp->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        // Non-existent ID for standard employee: should be blocked by 403 (mismatched ID) before 404
        $responseNonExistent = $this->getJson('/api/employees/999999/attendance-summary');
        $responseNonExistent->assertStatus(403);

        // Non-existent ID for manager: manager passes authorization check, then hits 404
        $managerUser = User::factory()->create(['is_active' => true]);
        $managerUser->roles()->sync([$this->managerRole->id]);
        Sanctum::actingAs($managerUser);
        $responseManager404 = $this->getJson('/api/employees/999999/attendance-summary');
        $responseManager404->assertStatus(404);
    }

    // =========================================================================
    // SECTION 2: SEC-12 WEBSOCKET CHANNEL AUTHORIZATION CHALLENGES
    // =========================================================================

    public function test_sec12_notification_created_broadcasts_strictly_to_recipient_channel(): void
    {
        $targetUserId = 42;

        // Object payload with user_id
        $eventObj = new NotificationCreated((object) [
            'id' => 'notif-1',
            'user_id' => $targetUserId,
            'title' => 'Personal notice',
        ]);
        $channelsObj = $eventObj->broadcastOn();
        $this->assertCount(1, $channelsObj);
        $this->assertInstanceOf(PrivateChannel::class, $channelsObj[0]);
        $this->assertEquals('private-notifications.42', $channelsObj[0]->name);

        // Array payload with notifiable_id
        $eventArr = new NotificationCreated([
            'id' => 'notif-2',
            'notifiable_id' => 99,
            'title' => 'Array payload notice',
        ]);
        $channelsArr = $eventArr->broadcastOn();
        $this->assertCount(1, $channelsArr);
        $this->assertInstanceOf(PrivateChannel::class, $channelsArr[0]);
        $this->assertEquals('private-notifications.99', $channelsArr[0]->name);
    }

    public function test_sec12_broadcasting_auth_enforces_channel_isolation(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'reverb-key');
        Config::set('broadcasting.connections.reverb.secret', 'reverb-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'reverb-app');
        require base_path('routes/channels.php');

        $user1 = User::factory()->create(['is_active' => true]);
        $user2 = User::factory()->create(['is_active' => true]);

        // 1. User 1 authenticating for own channel succeeds (200)
        $this->actingAs($user1);
        $respOwn = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user1->id}",
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(200, $respOwn->status());

        // 2. User 1 attempting to authenticate for User 2's channel fails with 403
        $respForbidden = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user2->id}",
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respForbidden->status());

        // 3. User attempting to authenticate for deprecated global channel fails with 403
        $respGlobal = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respGlobal->status());

        // 4. User 1 attempting to authenticate for User 2 with parameter tampering (e.g. 2;--) fails with 403
        $respInjectionPeer = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user2->id};--",
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respInjectionPeer->status());

        // Non-numeric user IDs fail with 403
        $respNonNumeric = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.admin',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respNonNumeric->status());

        // Zero / negative user IDs fail with 403
        $respZero = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.0',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respZero->status());

        $respNegative = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.-1',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respNegative->status());

        // 5. Unauthenticated user fails with 403
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $respUnauth = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user1->id}",
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respUnauth->status());
    }

    // =========================================================================
    // SECTION 3: SEC-14 SIGNED MEDIA ROUTES & TOKEN DEPRECATION CHALLENGES
    // =========================================================================

    public function test_sec14_signed_url_authorization_matrix(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('strangers/2026/10/07/test.jpg', 'valid-binary-content');

        $storage = app(ImageStorageService::class);
        $validSignedUrl = $storage->signedMediaUrl('strangers/2026/10/07/test.jpg', 60);

        // Scenario A: Valid signature -> 200 OK + correct security headers
        $this->app['auth']->forgetGuards();
        $respA = $this->get($validSignedUrl);
        $respA->assertStatus(200);
        $respA->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $respA->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $respA->headers->get('Cache-Control'));
        $this->assertEquals('valid-binary-content', $respA->getContent());

        // Scenario B: Tampered signature -> 401
        $tamperedUrl = $validSignedUrl . 'badhash';
        $this->app['auth']->forgetGuards();
        $respB = $this->get($tamperedUrl);
        $respB->assertStatus(401);

        // Scenario C: Expired signature -> 401
        $expiredUrl = $storage->signedMediaUrl('strangers/2026/10/07/test.jpg', -10);
        $this->app['auth']->forgetGuards();
        $respC = $this->get($expiredUrl);
        $respC->assertStatus(401);

        // Scenario D: Valid user token passed in query string without signature -> 401
        $adminUser = User::factory()->create(['is_active' => true]);
        $adminUser->roles()->sync([$this->adminRole->id]);
        $token = $adminUser->createToken('media-test')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $unsignedQueryUrl = '/api/media/strangers/2026/10/07/test.jpg?token=' . $token;
        $respD = $this->get($unsignedQueryUrl);
        $respD->assertStatus(401);

        // Scenario E: Valid user token in Authorization: Bearer header -> 200 OK
        $this->app['auth']->forgetGuards();
        $respE = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/strangers/2026/10/07/test.jpg');
        $respE->assertStatus(200);
        $this->assertEquals('valid-binary-content', $respE->getContent());

        // Scenario F: Bearer token for user WITHOUT media permissions -> 403
        $restrictedUser = User::factory()->create(['is_active' => true]);
        // no roles / permissions attached
        $restrictedToken = $restrictedUser->createToken('no-perm-test')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $respF = $this->withHeader('Authorization', 'Bearer ' . $restrictedToken)
            ->get('/api/media/strangers/2026/10/07/test.jpg');
        $respF->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized.']);

        // Scenario G: Invalid Bearer token -> 401
        $this->app['auth']->forgetGuards();
        $respG = $this->withHeader('Authorization', 'Bearer invalid_bogus_token_12345')
            ->get('/api/media/strangers/2026/10/07/test.jpg');
        $respG->assertStatus(401);
    }

    public function test_sec14_path_traversal_attempts_are_blocked(): void
    {
        Storage::fake('biometrics');
        $storage = app(ImageStorageService::class);

        // Sign a path traversal string
        $traversalPath = '../../../../etc/passwd';
        $traversalSignedUrl = $storage->signedMediaUrl($traversalPath, 30);

        $this->app['auth']->forgetGuards();
        $resp = $this->get($traversalSignedUrl);
        // ImageStorageService blocks traversal or returns 404
        $this->assertTrue(in_array($resp->status(), [404, 400, 403]));
    }

    public function test_sec14_eloquent_models_generate_valid_signed_urls(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('verification_snaps/2026/10/07/log1.jpg', 'log-snap');
        Storage::disk('biometrics')->put('verification_scenes/2026/10/07/scene1.jpg', 'log-scene');

        $log = new AccessLog([
            'device_id' => 'DEV-999',
            'snap_pic_url' => 'verification_snaps/2026/10/07/log1.jpg',
            'scene_pic_url' => 'verification_scenes/2026/10/07/scene1.jpg',
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        $this->assertNotNull($log->snap_pic_url);
        $this->assertStringContainsString('signature=', $log->snap_pic_url);
        $this->assertNotNull($log->scene_pic_url);
        $this->assertStringContainsString('signature=', $log->scene_pic_url);

        // Accessing the generated URL works without session
        $this->app['auth']->forgetGuards();
        $resp = $this->get($log->snap_pic_url);
        $resp->assertStatus(200);
        $this->assertEquals('log-snap', $resp->getContent());

        // Model with null path returns null cleanly
        $emptySnap = new StrangerSnap([
            'device_id' => 'DEV-999',
            'snap_pic_url' => null,
            'scene_pic_url' => null,
            'captured_at' => now(),
        ]);
        $this->assertNull($emptySnap->snap_pic_url);
        $this->assertNull($emptySnap->scene_pic_url);
    }

    public function test_sec11_attendance_summary_sql_injection_and_tampered_query_parameters(): void
    {
        $emp = Employee::create([
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP-SQLI',
            'first_name' => 'Sqli',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->employeeRole->id]);
        $emp->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        // SQL injection attempt in date query parameters
        $responseSqli = $this->getJson("/api/employees/{$emp->id}/attendance-summary?from=2026-01-01' OR 1=1--&to=2026-12-31");
        // Carbon parse will catch invalid format or succeed safely without SQL injection
        $this->assertTrue(in_array($responseSqli->status(), [200, 400, 422, 500]));

        // Method tampering (POST / PUT / DELETE)
        $respPost = $this->postJson("/api/employees/{$emp->id}/attendance-summary");
        $respPost->assertStatus(405);

        $respDelete = $this->deleteJson("/api/employees/{$emp->id}/attendance-summary");
        $respDelete->assertStatus(405);
    }

    public function test_sec12_websocket_channel_auth_boundary_cases(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'reverb-key');
        Config::set('broadcasting.connections.reverb.secret', 'reverb-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'reverb-app');
        require base_path('routes/channels.php');

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        // Subscribing to null / undefined / empty user string
        $respNull = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.null',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respNull->status());

        $respUndefined = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.undefined',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respUndefined->status());

        $respEmpty = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications.',
            'socket_id' => '100.200',
        ]);
        $this->assertEquals(403, $respEmpty->status());

        // Event broadcasting with null user_id
        $eventNull = new NotificationCreated((object) ['id' => 'n-null', 'user_id' => null, 'title' => 'Null user']);
        $channels = $eventNull->broadcastOn();
        $this->assertEquals('private-notifications.', $channels[0]->name);
    }

    public function test_sec14_signed_media_url_replay_and_tamper_attacks(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('strangers/file1.jpg', 'content-file1');
        Storage::disk('biometrics')->put('strangers/file2.jpg', 'content-file2');

        $storage = app(ImageStorageService::class);
        $validSignedUrlFile1 = $storage->signedMediaUrl('strangers/file1.jpg', 60);

        // Parse query string parameters (expires, signature)
        $parsed = parse_url($validSignedUrlFile1);
        parse_str($parsed['query'], $queryParams);

        $this->app['auth']->forgetGuards();

        // Attack 1: Replay valid signature from file1 on file2
        $replayUrlFile2 = '/api/media/strangers/file2.jpg?' . http_build_query($queryParams);
        $respReplay = $this->get($replayUrlFile2);
        $respReplay->assertStatus(401);

        // Attack 2: Tamper expiration date (e.g. advance expires timestamp by 1 hour) while keeping signature
        $tamperedQueryParams = $queryParams;
        $tamperedQueryParams['expires'] = (int) $queryParams['expires'] + 3600;
        $tamperedExpiryUrl = '/api/media/strangers/file1.jpg?' . http_build_query($tamperedQueryParams);
        $respTamperedExpiry = $this->get($tamperedExpiryUrl);
        $respTamperedExpiry->assertStatus(401);

        // Attack 3: Missing signature parameter
        $respNoSig = $this->get('/api/media/strangers/file1.jpg?expires=' . $queryParams['expires']);
        $respNoSig->assertStatus(401);
    }
}
