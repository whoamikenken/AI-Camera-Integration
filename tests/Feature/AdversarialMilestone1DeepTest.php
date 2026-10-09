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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialMilestone1DeepTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected Shift $shift;
    protected Role $superAdminRole;
    protected Role $adminRole;
    protected Role $managerRole;
    protected Role $hrManagerRole;
    protected Role $employeeRole;
    protected Permission $selfServicePerm;
    protected Permission $attendanceViewPerm;
    protected Permission $employeesViewPerm;
    protected Permission $employeesManagePerm;
    protected Permission $devicesViewPerm;

    protected function setUp(): void
    {
        parent::setUp();

        // Organizations
        $this->orgA = Organization::create([
            'name' => 'Adversarial Org Alpha',
            'code' => 'ADV-A',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->orgB = Organization::create([
            'name' => 'Adversarial Org Beta',
            'code' => 'ADV-B',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'name' => 'Standard Shift',
            'code' => 'STD-ADV',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        // Permissions
        $this->selfServicePerm = Permission::firstOrCreate(['slug' => 'selfservice.view'], ['name' => 'View Self Service', 'group' => 'selfservice']);
        $this->attendanceViewPerm = Permission::firstOrCreate(['slug' => 'attendance.view'], ['name' => 'View Attendance', 'group' => 'attendance']);
        $this->employeesViewPerm = Permission::firstOrCreate(['slug' => 'employees.view'], ['name' => 'View Employees', 'group' => 'employees']);
        $this->employeesManagePerm = Permission::firstOrCreate(['slug' => 'employees.manage'], ['name' => 'Manage Employees', 'group' => 'employees']);
        $this->devicesViewPerm = Permission::firstOrCreate(['slug' => 'devices.view'], ['name' => 'View Devices', 'group' => 'devices']);

        // Roles with their respective real permissions
        $this->superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrator', 'is_system' => true]);

        $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator', 'is_system' => true]);
        $this->adminRole->permissions()->syncWithoutDetaching([$this->employeesManagePerm->id, $this->attendanceViewPerm->id]);

        $this->managerRole = Role::firstOrCreate(['slug' => 'manager'], ['name' => 'Manager', 'is_system' => true]);
        $this->managerRole->permissions()->syncWithoutDetaching([$this->employeesViewPerm->id, $this->attendanceViewPerm->id]);

        $this->hrManagerRole = Role::firstOrCreate(['slug' => 'hr-manager'], ['name' => 'HR Manager', 'is_system' => true]);
        $this->hrManagerRole->permissions()->syncWithoutDetaching([$this->employeesManagePerm->id, $this->attendanceViewPerm->id]);

        $this->employeeRole = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true]);
        $this->employeeRole->permissions()->syncWithoutDetaching([$this->selfServicePerm->id]);
    }

    private function createEmployeeUser(string $code, Organization $org, ?Role $role = null): array
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        $emp = Employee::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'shift_id' => $this->shift->id,
            'employee_code' => $code,
            'first_name' => 'Emp_' . $code,
            'last_name' => 'Test',
            'employment_status' => 'active',
        ]);

        return [$user, $emp];
    }

    // ==========================================
    // SEC-11: ATTENDANCE SUMMARY BOLA/IDOR TESTS
    // ==========================================

    public function test_sec11_employee_cannot_access_peer_summary_same_organization(): void
    {
        [$userA, $empA] = $this->createEmployeeUser('EMP-A1', $this->orgA, $this->employeeRole);
        [$userB, $empB] = $this->createEmployeeUser('EMP-A2', $this->orgA, $this->employeeRole);

        Sanctum::actingAs($userA);

        $response = $this->getJson("/api/employees/{$empB->id}/attendance-summary");
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. You may only view your own attendance summary.',
            ]);
    }

    public function test_sec11_employee_cannot_access_peer_summary_cross_tenant_organization(): void
    {
        [$userA, $empA] = $this->createEmployeeUser('EMP-A1', $this->orgA, $this->employeeRole);
        [$userB, $empB] = $this->createEmployeeUser('EMP-B1', $this->orgB, $this->employeeRole);

        Sanctum::actingAs($userA);

        $response = $this->getJson("/api/employees/{$empB->id}/attendance-summary");
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. You may only view your own attendance summary.',
            ]);
    }

    public function test_sec11_employee_can_access_own_attendance_summary(): void
    {
        [$userA, $empA] = $this->createEmployeeUser('EMP-A1', $this->orgA, $this->employeeRole);

        Sanctum::actingAs($userA);

        $response = $this->getJson("/api/employees/{$empA->id}/attendance-summary");
        $response->assertStatus(200)
            ->assertJsonPath('employee_id', $empA->id);
    }

    public function test_sec11_user_without_linked_employee_receives_403_on_any_summary(): void
    {
        $unlinkedUser = User::factory()->create(['is_active' => true]);
        $unlinkedUser->roles()->sync([$this->employeeRole->id]);

        [$targetUser, $targetEmp] = $this->createEmployeeUser('EMP-TGT', $this->orgA, $this->employeeRole);

        Sanctum::actingAs($unlinkedUser);

        $response = $this->getJson("/api/employees/{$targetEmp->id}/attendance-summary");
        $response->assertStatus(403);
    }

    public function test_sec11_managerial_roles_can_access_any_employee_summary(): void
    {
        [$userTarget, $empTarget] = $this->createEmployeeUser('EMP-TARGET', $this->orgA, $this->employeeRole);

        $rolesToTest = [$this->superAdminRole, $this->adminRole, $this->managerRole, $this->hrManagerRole];

        foreach ($rolesToTest as $role) {
            $privilegedUser = User::factory()->create(['is_active' => true]);
            $privilegedUser->roles()->sync([$role->id]);

            Sanctum::actingAs($privilegedUser);

            $response = $this->getJson("/api/employees/{$empTarget->id}/attendance-summary");
            $response->assertStatus(200)
                ->assertJsonPath('employee_id', $empTarget->id);
        }
    }

    public function test_sec11_user_with_specific_view_permissions_can_access_employee_summary(): void
    {
        [$userTarget, $empTarget] = $this->createEmployeeUser('EMP-TARGET2', $this->orgA, $this->employeeRole);

        $customRole = Role::create(['name' => 'Attendance Auditor', 'slug' => 'auditor', 'is_system' => false]);
        $customRole->permissions()->sync([$this->attendanceViewPerm->id]);

        $auditorUser = User::factory()->create(['is_active' => true]);
        $auditorUser->roles()->sync([$customRole->id]);

        Sanctum::actingAs($auditorUser);

        $response = $this->getJson("/api/employees/{$empTarget->id}/attendance-summary");
        $response->assertStatus(200)
            ->assertJsonPath('employee_id', $empTarget->id);
    }

    public function test_sec11_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/employees/1/attendance-summary');
        $response->assertStatus(401);
    }

    public function test_sec11_nonexistent_employee_returns_404_for_manager_but_403_for_standard_employee(): void
    {
        [$userA, $empA] = $this->createEmployeeUser('EMP-A1', $this->orgA, $this->employeeRole);
        Sanctum::actingAs($userA);

        // Standard employee requesting nonexistent ID 99999 -> rejected 403 before DB query (BOLA defense)
        $response = $this->getJson('/api/employees/99999/attendance-summary');
        $response->assertStatus(403);

        // Manager requesting nonexistent ID 99999 -> passes permission check, fails at findOrFail -> 404 Not Found
        $managerUser = User::factory()->create(['is_active' => true]);
        $managerUser->roles()->sync([$this->managerRole->id]);
        Sanctum::actingAs($managerUser);

        $responseMgr = $this->getJson('/api/employees/99999/attendance-summary');
        $responseMgr->assertStatus(404);
    }

    // ==========================================
    // SEC-12: WEBSOCKET NOTIFICATION CHANNEL ISOLATION
    // ==========================================

    public function test_sec12_notification_created_broadcasts_strictly_to_recipient_channel(): void
    {
        $userA = User::factory()->create(['is_active' => true]);

        // 1. Eloquent object with user_id
        $notif1 = (object) ['user_id' => $userA->id, 'title' => 'Test 1'];
        $event1 = new NotificationCreated($notif1);
        $channels1 = $event1->broadcastOn();
        $this->assertCount(1, $channels1);
        $this->assertEquals("private-notifications.{$userA->id}", $channels1[0]->name);

        // 2. Array with user_id
        $notif2 = ['user_id' => $userA->id, 'title' => 'Test 2'];
        $event2 = new NotificationCreated($notif2);
        $channels2 = $event2->broadcastOn();
        $this->assertCount(1, $channels2);
        $this->assertEquals("private-notifications.{$userA->id}", $channels2[0]->name);

        // 3. Object with notifiable_id
        $notif3 = (object) ['notifiable_id' => $userA->id, 'title' => 'Test 3'];
        $event3 = new NotificationCreated($notif3);
        $channels3 = $event3->broadcastOn();
        $this->assertCount(1, $channels3);
        $this->assertEquals("private-notifications.{$userA->id}", $channels3[0]->name);

        // 4. Array with notifiable_id
        $notif4 = ['notifiable_id' => $userA->id, 'title' => 'Test 4'];
        $event4 = new NotificationCreated($notif4);
        $channels4 = $event4->broadcastOn();
        $this->assertCount(1, $channels4);
        $this->assertEquals("private-notifications.{$userA->id}", $channels4[0]->name);
    }

    public function test_sec12_websocket_channel_auth_boundary_enforcement(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'test-reverb-key');
        Config::set('broadcasting.connections.reverb.secret', 'test-reverb-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'test-app-id');
        require base_path('routes/channels.php');

        $user1 = User::factory()->create(['is_active' => true]);
        $user2 = User::factory()->create(['is_active' => true]);

        // User 1 authorized on their own channel
        $this->actingAs($user1);
        $res1 = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user1->id}",
            'socket_id' => '1111.2222',
        ]);
        $this->assertEquals(200, $res1->status());

        // User 2 BLOCKED on User 1's channel
        $this->actingAs($user2);
        $resDenied = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user1->id}",
            'socket_id' => '1111.2222',
        ]);
        $this->assertEquals(403, $resDenied->status());

        // Attacker attempting subscription to removed global channel
        $this->actingAs($user1);
        $resGlobal = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-notifications',
            'socket_id' => '1111.2222',
        ]);
        $this->assertEquals(403, $resGlobal->status());

        // Unauthenticated client blocked
        $this->app['auth']->forgetGuards();
        $resUnauth = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user1->id}",
            'socket_id' => '1111.2222',
        ]);
        $this->assertEquals(403, $resUnauth->status());
    }

    // ==========================================
    // SEC-14: SECURE MEDIA STREAMING & ADVERSARIAL ATTACKS
    // ==========================================

    public function test_sec14_path_traversal_attempts_are_blocked(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('personnel/2026/10/valid.jpg', 'valid-photo');

        $storage = app(ImageStorageService::class);

        // Direct getMedia traversal attempts
        $this->assertNull($storage->getMedia('../.env'));
        $this->assertNull($storage->getMedia('personnel/../../.env'));
        $this->assertNull($storage->getMedia('personnel/../config/app.php'));
        $this->assertNull($storage->getMedia('snaps/../../../../etc/passwd'));
        $this->assertNull($storage->getMedia('non_allowed_prefix/test.jpg'));

        // Route level traversal attempts
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->superAdminRole->id]);
        $token = $user->createToken('admin-token')->plainTextToken;

        // Try traversal through API route with Bearer auth
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/personnel/../../.env');
        // Either 404 or redirected/blocked (not 200 with sensitive data)
        $this->assertTrue($res1->status() === 404 || $res1->status() === 401 || $res1->status() === 400);

        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/../.env');
        $this->assertTrue($res2->status() === 404 || $res2->status() === 401 || $res2->status() === 400);
    }

    public function test_sec14_signed_url_cryptographic_integrity_and_tampering(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('strangers/2026/10/target.jpg', 'target-content');
        Storage::disk('biometrics')->put('personnel/2026/10/secret.jpg', 'secret-content');

        $storage = app(ImageStorageService::class);
        $signedUrl = $storage->signedMediaUrl('strangers/2026/10/target.jpg', 60);

        // 1. Untampered signed URL succeeds
        $this->app['auth']->forgetGuards();
        $okRes = $this->get($signedUrl);
        $okRes->assertStatus(200);
        $this->assertEquals('target-content', $okRes->getContent());

        // 2. Tampered signature parameter fails with 401
        $tamperedSigUrl = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeefcafe0000', $signedUrl);
        $tamperedSigRes = $this->get($tamperedSigUrl);
        $tamperedSigRes->assertStatus(401);

        // 3. Path swapping: replace path in signed URL with secret file path
        $pathSwappedUrl = str_replace('strangers/2026/10/target.jpg', 'personnel/2026/10/secret.jpg', $signedUrl);
        $pathSwappedRes = $this->get($pathSwappedUrl);
        $pathSwappedRes->assertStatus(401);

        // 4. Expiration extension: tamper 'expires' timestamp
        $futureExpires = time() + 999999;
        $tamperedExpiresUrl = preg_replace('/expires=[0-9]+/', 'expires=' . $futureExpires, $signedUrl);
        $tamperedExpiresRes = $this->get($tamperedExpiresUrl);
        $tamperedExpiresRes->assertStatus(401);

        // 5. Expired signed URL fails with 401
        $expiredUrl = $storage->signedMediaUrl('strangers/2026/10/target.jpg', -10);
        $expiredRes = $this->get($expiredUrl);
        $expiredRes->assertStatus(401);
    }

    public function test_sec14_query_token_is_rejected_without_valid_signature(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/test.jpg', 'snap-content');

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->superAdminRole->id]);
        $token = $user->createToken('token')->plainTextToken;

        $this->app['auth']->forgetGuards();

        // Attempt accessing via query parameter ?token=
        $res = $this->get('/api/media/snaps/2026/10/test.jpg?token=' . $token);
        $res->assertStatus(401);
    }

    public function test_sec14_bearer_access_requires_authorized_permissions(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/test.jpg', 'snap-content');

        // User with no media view permissions (e.g., standard employee with only selfservice.view)
        [$empUser, $emp] = $this->createEmployeeUser('EMP-NO-MEDIA', $this->orgA, $this->employeeRole);
        $token = $empUser->createToken('emp-token')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $resDenied = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/snaps/2026/10/test.jpg');
        $resDenied->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized.',
            ]);

        // User with permission devices.view succeeds
        $roleWithDeviceView = Role::create(['name' => 'Device Viewer', 'slug' => 'dev-viewer', 'is_system' => false]);
        $roleWithDeviceView->permissions()->sync([$this->devicesViewPerm->id]);
        $allowedUser = User::factory()->create(['is_active' => true]);
        $allowedUser->roles()->sync([$roleWithDeviceView->id]);
        $allowedToken = $allowedUser->createToken('allowed-token')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $resAllowed = $this->withHeader('Authorization', 'Bearer ' . $allowedToken)
            ->get('/api/media/snaps/2026/10/test.jpg');
        $resAllowed->assertStatus(200);
        $this->assertEquals('snap-content', $resAllowed->getContent());
    }

    public function test_sec14_deactivated_user_with_valid_bearer_token_is_forbidden(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/test.jpg', 'snap-content');

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$this->superAdminRole->id]);
        $token = $user->createToken('admin-token')->plainTextToken;

        // Deactivate user
        $user->update(['is_active' => false]);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/media/snaps/2026/10/test.jpg');
        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Your account has been deactivated.',
            ]);
    }

    public function test_sec14_media_streaming_security_headers(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/headers_test.jpg', 'image-bytes');

        $storage = app(ImageStorageService::class);
        $signedUrl = $storage->signedMediaUrl('snaps/2026/10/headers_test.jpg', 60);

        $res = $this->get($signedUrl);
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_sec11_attendance_summary_id_manipulation_is_blocked(): void
    {
        [$userA, $empA] = $this->createEmployeeUser('EMP-A1', $this->orgA, $this->employeeRole);
        Sanctum::actingAs($userA);

        // Negative ID
        $resNeg = $this->getJson('/api/employees/-1/attendance-summary');
        $resNeg->assertStatus(403);

        // Zero ID
        $resZero = $this->getJson('/api/employees/0/attendance-summary');
        $resZero->assertStatus(403);
    }

    public function test_sec14_signed_url_invalidation_when_extra_query_params_appended(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/query_attack.jpg', 'image-bytes');

        $storage = app(ImageStorageService::class);
        $validSignedUrl = $storage->signedMediaUrl('snaps/2026/10/query_attack.jpg', 60);

        // Appending extra parameter invalidates HMAC signature
        $attackUrl = $validSignedUrl . '&injected_param=evil';
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $res = $this->get($attackUrl);
        $res->assertStatus(401);
    }

    public function test_sec14_post_request_to_media_stream_is_rejected(): void
    {
        Storage::fake('biometrics');
        Storage::disk('biometrics')->put('snaps/2026/10/post_attack.jpg', 'image-bytes');

        $storage = app(ImageStorageService::class);
        $signedUrl = $storage->signedMediaUrl('snaps/2026/10/post_attack.jpg', 60);

        // POST method should return 405 Method Not Allowed
        $res = $this->post($signedUrl);
        $res->assertStatus(405);
    }

    public function test_sec14_access_log_accessor_preserves_external_and_data_uris(): void
    {
        // Data URI
        $logData = new AccessLog(['snap_pic_url' => 'data:image/jpeg;base64,12345']);
        $this->assertEquals('data:image/jpeg;base64,12345', $logData->snap_pic_url);

        // External S3 URL
        $logExternal = new AccessLog(['snap_pic_url' => 'https://s3.amazonaws.com/my-bucket/snap.jpg']);
        $this->assertEquals('https://s3.amazonaws.com/my-bucket/snap.jpg', $logExternal->snap_pic_url);

        // Null
        $logNull = new AccessLog(['snap_pic_url' => null]);
        $this->assertNull($logNull->snap_pic_url);
    }

    public function test_sec14_stranger_snap_accessor_preserves_external_and_data_uris(): void
    {
        // Data URI
        $snapData = new StrangerSnap(['snap_pic_url' => 'data:image/png;base64,67890']);
        $this->assertEquals('data:image/png;base64,67890', $snapData->snap_pic_url);

        // External S3 URL
        $snapExternal = new StrangerSnap(['snap_pic_url' => 'https://external-cdn.com/stranger.jpg']);
        $this->assertEquals('https://external-cdn.com/stranger.jpg', $snapExternal->snap_pic_url);

        // Null
        $snapNull = new StrangerSnap(['snap_pic_url' => null]);
        $this->assertNull($snapNull->snap_pic_url);
    }
}

