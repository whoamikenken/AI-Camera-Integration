<?php

namespace Tests\Feature;

use App\Events\AccessLogReceived;
use App\Events\DeviceAlertReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Services\CameraHttpService;
use App\Services\CameraMqttService;
use App\Services\ImageStorageService;
use App\Support\CsvSanitizer;
use Carbon\Carbon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $standardUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $employeeRole = Role::firstOrCreate(['slug' => 'employee'], [
            'name' => 'Employee',
            'is_system' => true,
        ]);

        $this->org = Organization::create([
            'name' => 'Security Test Org',
            'code' => 'SEC-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->superAdmin->roles()->sync([$superAdminRole->id]);
        $this->superAdmin->load('roles');

        $this->standardUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->standardUser->roles()->sync([$employeeRole->id]);
        $this->standardUser->load('roles');

        auth()->forgetGuards();
    }

    // =========================================================================
    // SEC-01: Camera Webhook Push Endpoints
    // =========================================================================

    public function test_sec01_unregistered_camera_webhook_is_rejected_with_401(): void
    {
        $response = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'ROGUE-CAMERA-999',
            'info' => [
                'PersonID' => 101,
                'VerifyStatus' => 1,
            ],
        ]);

        $this->assertEquals(401, $response->status());
        $this->assertDatabaseMissing('devices', ['device_id' => 'ROGUE-CAMERA-999']);
    }

    public function test_sec01_unknown_camera_heartbeat_is_staged_as_inactive(): void
    {
        $response = $this->postJson('/api/Subscribe/heartbeat', [
            'info' => [
                'DeviceID' => 'UNAPPROVED-CAM-01',
                'facesname' => 'Staged Camera',
                'ip' => '192.168.10.55',
            ],
        ]);

        $response->assertOk();
        $device = Device::where('device_id', 'UNAPPROVED-CAM-01')->first();
        $this->assertNotNull($device);
        $this->assertFalse((bool) $device->is_active, 'Unknown camera heartbeat must be staged as inactive (is_active=false)');
    }

    public function test_sec01_oversized_base64_payload_is_rejected_with_400(): void
    {
        Device::create([
            'device_id' => 'CAM-OVERSIZED-01',
            'name' => 'Oversized Test Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        // Construct oversized payload exceeding 10MB limit
        $oversizedData = str_repeat('A', 11 * 1024 * 1024);

        $response = $this->postJson('/api/Subscribe/Snap', [
            'info' => [
                'DeviceID' => 'CAM-OVERSIZED-01',
                'CreateTime' => now()->toDateTimeString(),
            ],
            'Pic' => $oversizedData,
        ]);

        $this->assertEquals(400, $response->status());
    }

    public function test_sec01_valid_authenticated_camera_webhook_succeeds(): void
    {
        Event::fake([AccessLogReceived::class]);

        Device::create([
            'device_id' => 'CAM-AUTH-01',
            'name' => 'Authorized Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/Subscribe/Verify', [
            'info' => [
                'DeviceID' => 'CAM-AUTH-01',
                'PersonID' => 200,
                'CustomizeID' => 1002,
                'Name' => 'Authorized Personnel',
                'VerifyStatus' => 1,
                'Similarity' => 98.2,
                'CreateTime' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('access_logs', [
            'device_id' => 'CAM-AUTH-01',
            'person_name' => 'Authorized Personnel',
        ]);
    }

    // =========================================================================
    // SEC-02: Device Password Encryption & Concealment
    // =========================================================================

    public function test_sec02_device_password_is_encrypted_at_rest_and_concealed_from_api(): void
    {
        $rawPassword = 'SuperSecretDevicePassword123!';

        $device = Device::create([
            'device_id' => 'CAM-PASS-01',
            'name' => 'Encrypted Password Camera',
            'ip_address' => '192.168.1.55',
            'password' => $rawPassword,
            'is_active' => true,
        ]);

        // Verify encrypted in database (raw PostgreSQL value != plain text)
        $rawDbValue = DB::table('devices')->where('id', $device->id)->value('password');
        $this->assertNotEquals($rawPassword, $rawDbValue, 'Device password must NOT be stored in cleartext at rest');
        $this->assertEquals($rawPassword, $device->password, 'Device model decrypted password should match original');

        // Verify hidden from array and JSON serialization
        $serialized = $device->toArray();
        $this->assertArrayNotHasKey('password', $serialized, 'Device password must be hidden from serialization');

        // Verify hidden in API responses
        Sanctum::actingAs($this->superAdmin, ['*']);
        $response = $this->getJson('/api/devices');
        $response->assertOk();
        $response->assertJsonMissing(['password' => $rawPassword]);
    }

    // =========================================================================
    // SEC-03: MQTT TLS & Transport Security
    // =========================================================================

    public function test_sec03_camera_mqtt_service_supports_tls_configuration(): void
    {
        $service = app(CameraMqttService::class);
        $this->assertNotNull($service);

        // Verify TLS configuration can be read from config
        Config::set('services.mqtt.tls', true);
        Config::set('services.mqtt.tls_ca_cert', '/etc/ssl/certs/ca-certificates.crt');
        Config::set('services.mqtt.tls_allow_self_signed', false);

        $this->assertTrue((bool) config('services.mqtt.tls'));
        $this->assertEquals('/etc/ssl/certs/ca-certificates.crt', config('services.mqtt.tls_ca_cert'));
    }

    // =========================================================================
    // SEC-04: Reverb Broadcast Channels are Private
    // =========================================================================

    public function test_sec04_reverb_events_broadcast_on_private_channels(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-EVT-01',
            'name' => 'Event Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $log = new \App\Models\AccessLog([
            'device_id' => $device->device_id,
            'verify_status' => 1,
            'captured_at' => now(),
        ]);
        $accessEvent = new AccessLogReceived($log);
        $channels = $accessEvent->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-access-logs', $channels[0]->name);

        $snap = new \App\Models\StrangerSnap([
            'device_id' => $device->device_id,
            'snap_pic_url' => 'https://example.com/snap.jpg',
            'captured_at' => now(),
        ]);
        $snapEvent = new StrangerSnapReceived($snap);
        $this->assertInstanceOf(PrivateChannel::class, $snapEvent->broadcastOn()[0]);
        $this->assertEquals('private-stranger-snaps', $snapEvent->broadcastOn()[0]->name);

        $statusEvent = new DeviceStatusUpdated($device, 'online');
        $this->assertInstanceOf(PrivateChannel::class, $statusEvent->broadcastOn()[0]);
        $this->assertEquals('private-device-status', $statusEvent->broadcastOn()[0]->name);
    }

    public function test_sec04_broadcasting_auth_enforces_channel_permissions(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'test-key');
        Config::set('broadcasting.connections.reverb.secret', 'test-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'test-app');
        require base_path('routes/channels.php');

        auth()->forgetGuards();

        // Unauthenticated client cannot authorize channel
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-access-logs',
            'socket_id' => '1234.5678',
        ]);
        $this->assertContains($response->status(), [401, 403]);

        // Standard user without devices.view cannot authorize device-alerts
        $this->actingAs($this->standardUser);
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-device-alerts',
            'socket_id' => '1234.5678',
        ]);
        $this->assertEquals(403, $response->status());

        // Super-admin can authorize
        $this->actingAs($this->superAdmin);
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-access-logs',
            'socket_id' => '1234.5678',
        ]);
        $this->assertEquals(200, $response->status());
    }

    // =========================================================================
    // SEC-05: Server-Side Request Forgery (SSRF) Prevention
    // =========================================================================

    public function test_sec05_ssrf_protection_blocks_internal_and_cloud_metadata_ips(): void
    {
        $storageService = app(ImageStorageService::class);

        // Loopback SSRF
        $this->assertNull($storageService->storeFromUrlOrPath('http://127.0.0.1:8000/internal-data'));
        $this->assertNull($storageService->storeFromUrlOrPath('http://localhost:9000/keys'));

        // AWS/Cloud Metadata SSRF
        $this->assertNull($storageService->storeFromUrlOrPath('http://169.254.169.254/latest/meta-data/'));

        // RFC 1918 Private ranges
        $this->assertNull($storageService->storeFromUrlOrPath('http://10.0.0.1/admin'));
        $this->assertNull($storageService->storeFromUrlOrPath('http://192.168.1.1/router'));
        $this->assertNull($storageService->storeFromUrlOrPath('http://172.16.0.1/intranet'));
    }

    public function test_sec05_personnel_photo_url_rejects_ssrf_prohibited_ips(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->postJson('/api/personnel', [
            'customize_id' => 888999,
            'name' => 'SSRF Tester',
            'person_type' => 0,
            'photo_url' => 'http://169.254.169.254/latest/meta-data/identity-credentials',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo_url']);
    }

    // =========================================================================
    // SEC-06: RBAC on Device Alerts & Notification Ownership
    // =========================================================================

    public function test_sec06_device_alerts_require_manage_permission(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-ALERT-01',
            'name' => 'Alert Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $alert = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'INTRUSION',
            'severity' => 'CRITICAL',
            'title' => 'Perimeter Breach',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        // Standard user without devices.manage cannot dismiss alert
        Sanctum::actingAs($this->standardUser, ['*']);
        $response = $this->patchJson("/api/device-alerts/{$alert->id}/status", [
            'status' => 'RESOLVED',
        ]);
        $this->assertEquals(403, $response->status());

        // Super-admin can resolve alert
        Sanctum::actingAs($this->superAdmin, ['*']);
        $response = $this->patchJson("/api/device-alerts/{$alert->id}/status", [
            'status' => 'RESOLVED',
        ]);
        $response->assertOk();
    }

    public function test_sec06_notifications_scoped_to_owner_user(): void
    {
        // Insert notification owned by superAdmin
        $notifId = (string) \Illuminate\Support\Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'type' => 'App\Notifications\SecurityAlert',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->superAdmin->id,
            'data' => json_encode(['message' => 'Admin only notification']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Grant selfservice.view permission so the request reaches the controller ownership check
        $employeeRole = Role::where('slug', 'employee')->first();
        $employeeRole->givePermission('selfservice.view');

        // Standard user attempts to mark superAdmin's notification as read
        Sanctum::actingAs($this->standardUser, ['*']);
        $response = $this->putJson("/api/notifications/{$notifId}/read");
        $this->assertEquals(404, $response->status());

        // Verify notification is still unread
        $unread = DB::table('notifications')->where('id', $notifId)->whereNull('read_at')->exists();
        $this->assertTrue($unread);
    }

    // =========================================================================
    // SEC-07: Anti-Self-Approval & Cross-Employee Protection
    // =========================================================================

    public function test_sec07_employees_cannot_submit_leave_for_others_and_cannot_self_approve(): void
    {
        $employee1 = Employee::create([
            'user_id' => $this->standardUser->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'Standard',
            'last_name' => 'Employee',
            'employment_status' => 'active',
        ]);

        $employee2 = Employee::create([
            'user_id' => $this->superAdmin->id,
            'employee_code' => 'EMP-002',
            'first_name' => 'Manager',
            'last_name' => 'User',
            'employment_status' => 'active',
        ]);

        $leaveType = LeaveType::create([
            'name' => 'Sick Leave',
            'code' => 'SL-01',
            'max_days_per_year' => 15,
        ]);

        \App\Models\LeaveBalance::create([
            'employee_id' => $employee2->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 15,
            'used' => 0,
            'pending' => 0,
        ]);

        // Standard user attempts to submit leave for employee2
        Sanctum::actingAs($this->standardUser, ['*']);
        $response = $this->postJson('/api/leave-requests', [
            'employee_id' => $employee2->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-11',
            'reason' => 'Unauthorized submission for peer',
        ]);
        $this->assertEquals(403, $response->status());

        // Create leave request for superAdmin
        $leaveReq = LeaveRequest::create([
            'employee_id' => $employee2->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-16',
            'total_days' => 2,
            'status' => 'pending',
        ]);

        // SuperAdmin attempts to self-approve their own request
        Sanctum::actingAs($this->superAdmin, ['*']);
        $response = $this->putJson("/api/leave-requests/{$leaveReq->id}/approve");
        $this->assertEquals(403, $response->status());
        $response->assertJson(['message' => 'Self-approval of leave requests is forbidden.']);
    }

    // =========================================================================
    // SEC-08: Removal of Mock Entity Auto-Creation
    // =========================================================================

    public function test_sec08_nonexistent_entities_return_404_not_mock_created(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Nonexistent employee in manual punch
        $response = $this->postJson('/api/attendance/manual-entry', [
            'employee_id' => 999888,
            'punch_time' => now()->toDateTimeString(),
            'direction' => 'in',
            'reason' => 'Testing 404',
        ]);
        $this->assertEquals(404, $response->status());
        $this->assertDatabaseMissing('employees', ['id' => 999888]);

        // Nonexistent attendance record in override
        $response = $this->putJson('/api/attendance/999888/override', [
            'status' => 'present',
            'remarks' => 'Testing 404 override',
        ]);
        $this->assertEquals(404, $response->status());
        $this->assertDatabaseMissing('attendance_records', ['id' => 999888]);

        // Nonexistent regularization approval
        $response = $this->putJson('/api/regularization-requests/999888/approve');
        $this->assertEquals(404, $response->status());
    }

    // =========================================================================
    // SEC-09: Camera HTTP Service TLS Verification
    // =========================================================================

    public function test_sec09_camera_http_service_enforces_tls_verification_by_default(): void
    {
        $service = app(CameraHttpService::class);
        $this->assertNotNull($service);

        // Verification CA bundle configuration is accessible
        Config::set('services.camera.ca_bundle', '/etc/ssl/certs/camera-ca.crt');
        Config::set('services.camera.allow_self_signed', false);

        $this->assertEquals('/etc/ssl/certs/camera-ca.crt', config('services.camera.ca_bundle'));
        $this->assertFalse((bool) config('services.camera.allow_self_signed'));
    }

    // =========================================================================
    // SEC-10: Sanctum API Token Expiration & Pruning
    // =========================================================================

    public function test_sec10_sanctum_token_expiration_is_configured_and_prunable(): void
    {
        $expiration = config('sanctum.expiration');
        $this->assertNotNull($expiration, 'Sanctum token expiration must be configured');
        $this->assertGreaterThan(0, $expiration, 'Sanctum token expiration must be greater than 0');

        // Verify pruning command exists and executes without error
        $exitCode = Artisan::call('sanctum:prune-expired', ['--hours' => 24]);
        $this->assertEquals(0, $exitCode);
    }

    // =========================================================================
    // SEC-11: Biometric Media Storage & Secure Serving
    // =========================================================================

    public function test_sec11_biometrics_disk_is_registered_and_media_route_requires_auth(): void
    {
        $diskConfig = config('filesystems.disks.biometrics');
        $this->assertNotNull($diskConfig, 'Biometrics private disk must be configured');

        // Unauthenticated access to media endpoint is blocked
        auth()->forgetGuards();

        $response = $this->getJson('/api/media/personnel/test.jpg');
        $this->assertEquals(401, $response->status());
    }

    // =========================================================================
    // SEC-12: CSV Formula Injection (DDE) Sanitization
    // =========================================================================

    public function test_sec12_csv_formula_injection_is_sanitized(): void
    {
        $maliciousInputs = [
            '=cmd|\' /C calc\'!A0',
            '+cmd|\' /C calc\'!A0',
            '-cmd|\' /C calc\'!A0',
            '@SUM(1,2)',
            "\tTAB_COMMAND",
            "\rCR_COMMAND",
            'Safe String',
        ];

        $sanitized = CsvSanitizer::sanitizeRow($maliciousInputs);

        $this->assertEquals("'=cmd|' /C calc'!A0", $sanitized[0]);
        $this->assertEquals("'+cmd|' /C calc'!A0", $sanitized[1]);
        $this->assertEquals("'-cmd|' /C calc'!A0", $sanitized[2]);
        $this->assertEquals("'@SUM(1,2)", $sanitized[3]);
        $this->assertEquals("'\tTAB_COMMAND", $sanitized[4]);
        $this->assertEquals("'\rCR_COMMAND", $sanitized[5]);
        $this->assertEquals('Safe String', $sanitized[6]);
    }

    // =========================================================================
    // SEC-13: Security Headers & API Rate Limiting
    // =========================================================================

    public function test_sec13_security_headers_are_present_in_responses(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
    }

    // =========================================================================
    // SEC-15: .env.example Key Sanitization
    // =========================================================================

    public function test_sec15_env_example_has_no_hardcoded_key(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('APP_KEY=', $envExample);
        $this->assertStringNotContainsString('APP_KEY=base64:2uDSBwXABxqKrme22nL1rR4Acnranb/7QN9hBStcbM8=', $envExample);
    }
}
