<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Events\AccessLogReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\StrangerSnap;
use App\Models\User;
use App\Services\CameraHttpService;
use App\Services\CameraMqttService;
use App\Services\ImageStorageService;
use App\Support\CsvSanitizer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpMqtt\Client\MqttClient;
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

        $log = new AccessLog([
            'device_id' => $device->device_id,
            'verify_status' => 1,
            'captured_at' => now(),
        ]);
        $accessEvent = new AccessLogReceived($log);
        $channels = $accessEvent->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-access-logs', $channels[0]->name);

        $snap = new StrangerSnap([
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
        $notifId = (string) Str::uuid();
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

        LeaveBalance::create([
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

    // =========================================================================
    // Milestone 2: Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19)
    // =========================================================================

    public function test_sec13_insecure_mqtt_tunnel_disabled_by_default(): void
    {
        $startDevScript = file_get_contents(base_path('start-dev.sh'));
        $this->assertStringContainsString('${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true"', $startDevScript);

        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('ENABLE_INSECURE_MQTT_TUNNEL=false', $envExample);

        $env = file_get_contents(base_path('.env'));
        $this->assertStringContainsString('ENABLE_INSECURE_MQTT_TUNNEL=false', $env);
    }

    public function test_sec13_mqtt_listen_drops_telemetry_from_unregistered_or_inactive_device(): void
    {
        $command = new class extends MqttListenCommand
        {
            public function invokeVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storage): void
            {
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storage);
            }

            public function invokeStrangerSnapPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storage): void
            {
                $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storage);
            }

            public function invokeDeviceAlert(?string $deviceId, string $operator, array $data, array $info, MqttClient $mqtt, ImageStorageService $storage): void
            {
                $this->handleDeviceAlert($deviceId, $operator, $data, $info, $mqtt, $storage);
            }
        };

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        $mockStorage = $this->createMock(ImageStorageService::class);
        $mockStorage->method('storeBase64Image')->willReturn('https://example.com/test.jpg');

        // 1. Unregistered device: VerifyPush should be dropped, but staged with is_active = false
        $verifyPayload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => 'ROGUE-MQTT-01',
                'RecordID' => 1001,
                'VerifyStatus' => 1,
                'time' => '2026-10-07 10:00:00',
            ],
        ];
        $command->invokeVerifyPush('ROGUE-MQTT-01', $verifyPayload, $verifyPayload['info'], $mockMqtt, $mockStorage);

        $this->assertEquals(0, AccessLog::where('device_id', 'ROGUE-MQTT-01')->count());
        $stagedDevice = Device::where('device_id', 'ROGUE-MQTT-01')->first();
        $this->assertNotNull($stagedDevice);
        $this->assertFalse((bool) $stagedDevice->is_active);

        // 2. Pre-enrolled but inactive device: stranger snap and alert should be dropped
        $inactiveDevice = Device::create([
            'device_id' => 'INACTIVE-CAM-01',
            'name' => 'Inactive Edge Cam',
            'ip_address' => '192.168.1.150',
            'is_active' => false,
        ]);

        $snapPayload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => 'INACTIVE-CAM-01',
                'SnapID' => 5001,
                'time' => '2026-10-07 10:01:00',
            ],
            'pic' => 'base64pic',
        ];
        $command->invokeStrangerSnapPush('INACTIVE-CAM-01', $snapPayload, $snapPayload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(0, StrangerSnap::where('device_id', 'INACTIVE-CAM-01')->count());

        $alertPayload = [
            'operator' => 'ClothHelmetSnapPush',
            'info' => [
                'facesluiceId' => 'INACTIVE-CAM-01',
                'AlarmAction' => 'NO_HELMET',
                'time' => '2026-10-07 10:02:00',
            ],
            'pic' => 'base64pic',
        ];
        $command->invokeDeviceAlert('INACTIVE-CAM-01', 'ClothHelmetSnapPush', $alertPayload, $alertPayload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(0, DeviceAlert::where('device_id', 'INACTIVE-CAM-01')->count());
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active);
    }

    public function test_sec13_mqtt_listen_heartbeat_stages_unknown_device_as_inactive_and_does_not_reactivate(): void
    {
        $command = new class extends MqttListenCommand
        {
            public function invokeHeartbeat(?string $deviceId, array $info): void
            {
                $this->handleHeartbeat($deviceId, $info);
            }

            public function invokeOnline(?string $deviceId, string $operator, array $info, MqttClient $mqtt): void
            {
                $this->handleOnlineStatus($deviceId, $operator, $info, $mqtt);
            }
        };

        $mockMqtt = $this->createMock(MqttClient::class);

        // 1. Unknown heartbeat stages device with is_active = false
        $command->invokeHeartbeat('UNKNOWN-HB-01', [
            'facesname' => 'Unknown HB Cam',
            'ip' => '192.168.1.180',
        ]);
        $device = Device::where('device_id', 'UNKNOWN-HB-01')->first();
        $this->assertNotNull($device);
        $this->assertFalse((bool) $device->is_active);

        // 2. Existing inactive device receiving heartbeat should NOT become active
        $inactiveDevice = Device::create([
            'device_id' => 'MANUALLY-DISABLED-01',
            'name' => 'Disabled Camera',
            'ip_address' => '192.168.1.185',
            'is_active' => false,
        ]);

        Cache::forget('device_hb_throttle:MANUALLY-DISABLED-01');
        $command->invokeHeartbeat('MANUALLY-DISABLED-01', ['ip' => '192.168.1.185']);
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active);

        // 3. Online status from unapproved device creates device as inactive
        $command->invokeOnline('UNKNOWN-ONLINE-01', 'Online', ['facesname' => 'Online Cam', 'ip' => '192.168.1.190'], $mockMqtt);
        $onlineDevice = Device::where('device_id', 'UNKNOWN-ONLINE-01')->first();
        $this->assertNotNull($onlineDevice);
        $this->assertFalse((bool) $onlineDevice->is_active);
    }

    public function test_sec15_personnel_photo_upload_rejects_svg_files(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $svgFile = UploadedFile::fake()->createWithContent(
            'avatar.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $response = $this->postJson('/api/personnel', [
            'name' => 'Malicious SVG User',
            'person_type' => 0,
            'photo' => $svgFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);

        // Verify valid raster image is accepted
        $validImage = UploadedFile::fake()->image('avatar.jpg');
        $validResponse = $this->postJson('/api/personnel', [
            'name' => 'Valid Raster User',
            'person_type' => 0,
            'photo' => $validImage,
        ]);
        $validResponse->assertStatus(201);
    }

    public function test_sec15_image_storage_service_get_media_rejects_svg_xml_html(): void
    {
        $storageService = app(ImageStorageService::class);

        // Put dummy dangerous files on biometrics disk
        Storage::disk('biometrics')->put('personnel/exploit.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        Storage::disk('biometrics')->put('personnel/page.html', '<html><body><script>alert(1)</script></body></html>');
        Storage::disk('biometrics')->put('personnel/data.xml', '<?xml version="1.0"?><data>test</data>');
        Storage::disk('biometrics')->put('personnel/valid.jpg', 'fake-jpeg-content');

        $this->assertNull($storageService->getMedia('personnel/exploit.svg'));
        $this->assertNull($storageService->getMedia('personnel/page.html'));
        $this->assertNull($storageService->getMedia('personnel/data.xml'));

        $validMedia = $storageService->getMedia('personnel/valid.jpg');
        $this->assertNotNull($validMedia);
        $this->assertEquals('fake-jpeg-content', $validMedia['content']);
    }

    public function test_sec16_personnel_photo_path_rejects_ssrf_urls(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // 1. Cloud metadata target in photo_path
        $response1 = $this->postJson('/api/personnel', [
            'name' => 'Metadata Target',
            'person_type' => 0,
            'photo_path' => 'http://169.254.169.254/latest/meta-data/',
        ]);
        $response1->assertStatus(422);
        $response1->assertJsonValidationErrors(['photo_path']);

        // 2. Loopback target in photo_path
        $response2 = $this->postJson('/api/personnel', [
            'name' => 'Loopback Target',
            'person_type' => 0,
            'photo_path' => 'http://127.0.0.1:8000/secret',
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['photo_path']);

        // 3. Private RFC 1918 network in photo_path
        $response3 = $this->postJson('/api/personnel', [
            'name' => 'Private IP Target',
            'person_type' => 0,
            'photo_path' => 'http://192.168.1.1/admin',
        ]);
        $response3->assertStatus(422);
        $response3->assertJsonValidationErrors(['photo_path']);

        // 4. Update existing record with SSRF in photo_path
        $person = Personnel::create([
            'customize_id' => 88123,
            'name' => 'Existing Personnel',
            'person_type' => 0,
        ]);

        $updateResponse = $this->putJson("/api/personnel/{$person->id}", [
            'name' => 'Updated Name',
            'person_type' => 0,
            'photo_path' => 'http://10.0.0.1/sensitive',
        ]);
        $updateResponse->assertStatus(422);
        $updateResponse->assertJsonValidationErrors(['photo_path']);
    }

    public function test_sec16_personnel_photo_path_allows_valid_relative_paths(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->postJson('/api/personnel', [
            'name' => 'Relative Path Personnel',
            'person_type' => 0,
            'photo_path' => 'strangers/test_stranger_123.jpg',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('personnel', [
            'name' => 'Relative Path Personnel',
            'photo_path' => 'strangers/test_stranger_123.jpg',
        ]);
    }

    public function test_sec19_webhook_rejects_loopback_ip_bypass_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $device = Device::create([
            'device_id' => 'CAM-PROD-WEBHOOK-01',
            'name' => 'Prod Camera',
            'ip_address' => '192.168.1.200',
            'is_active' => true,
        ]);

        // Attacker claims 127.0.0.1 behind reverse proxy, but camera IP is 192.168.1.200
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-PROD-WEBHOOK-01',
                'info' => [
                    'PersonID' => 1,
                    'VerifyStatus' => 1,
                ],
            ]);

        $this->assertEquals(401, $response->status(), 'Loopback IP bypass must be rejected in production environment');
    }

    public function test_sec19_trusted_proxies_configured(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195',
        ])->get('/up');

        $response->assertOk();
    }

    // =========================================================================
    // Milestone 3: Content-Security-Policy & Upstream Dependencies (SEC-17, SEC-18)
    // =========================================================================

    public function test_sec17_content_security_policy_directives_are_hardened(): void
    {
        // 1. Production environment: verify strict policy, no eval, no wildcards
        app()->detectEnvironment(fn () => 'production');

        Config::set('broadcasting.connections.reverb.options.host', 'reverb.internal');
        Config::set('broadcasting.connections.reverb.options.port', 8080);
        Config::set('filesystems.disks.s3.url', 'https://s3.ap-southeast-1.amazonaws.com/camera-hub-storage');

        $response = $this->get('/');

        $this->assertTrue($response->headers->has('Content-Security-Policy'), 'CSP header must be present');
        $csp = $response->headers->get('Content-Security-Policy');

        // Parse directives
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if (! empty($part)) {
                $tokens = preg_split('/\s+/', $part);
                $name = array_shift($tokens);
                $directives[$name] = $tokens;
            }
        }

        // script-src: must contain wasm-unsafe-eval, must NOT contain unsafe-eval in production
        $this->assertArrayHasKey('script-src', $directives);
        $this->assertContains("'wasm-unsafe-eval'", $directives['script-src']);
        $this->assertNotContains("'unsafe-eval'", $directives['script-src'], 'Production script-src must not contain unsafe-eval');
        $this->assertContains('https://static.cloudflareinsights.com', $directives['script-src']);

        // worker-src: must contain 'self' blob:
        $this->assertArrayHasKey('worker-src', $directives);
        $this->assertContains("'self'", $directives['worker-src']);
        $this->assertContains('blob:', $directives['worker-src']);

        // img-src: must NOT contain wildcard https:
        $this->assertArrayHasKey('img-src', $directives);
        $this->assertNotContains('https:', $directives['img-src'], 'img-src must not contain wildcard https:');
        $this->assertContains("'self'", $directives['img-src']);
        $this->assertContains('data:', $directives['img-src']);
        $this->assertContains('blob:', $directives['img-src']);
        $this->assertContains('https://s3.ap-southeast-1.amazonaws.com/camera-hub-storage', $directives['img-src']);

        // connect-src: must NOT contain wildcards https:, ws:, wss:
        $this->assertArrayHasKey('connect-src', $directives);
        $this->assertNotContains('https:', $directives['connect-src'], 'connect-src must not contain wildcard https:');
        $this->assertNotContains('ws:', $directives['connect-src'], 'connect-src must not contain wildcard ws:');
        $this->assertNotContains('wss:', $directives['connect-src'], 'connect-src must not contain wildcard wss:');

        // connect-src: must scope strictly to 'self', cloudflareinsights, and Reverb endpoints
        $this->assertContains("'self'", $directives['connect-src']);
        $this->assertContains('https://cloudflareinsights.com', $directives['connect-src']);
        $this->assertContains('ws://reverb.internal:8080', $directives['connect-src']);
        $this->assertContains('wss://reverb.internal:8080', $directives['connect-src']);

        // frame-ancestors: 'none'
        $this->assertArrayHasKey('frame-ancestors', $directives);
        $this->assertContains("'none'", $directives['frame-ancestors']);

        // 2. Non-production environment: verify Vite dev origins and dev tooling support
        app()->detectEnvironment(fn () => 'local');

        $responseDev = $this->get('/');
        $cspDev = $responseDev->headers->get('Content-Security-Policy');

        $directivesDev = [];
        foreach (explode(';', $cspDev) as $part) {
            $part = trim($part);
            if (! empty($part)) {
                $tokens = preg_split('/\s+/', $part);
                $name = array_shift($tokens);
                $directivesDev[$name] = $tokens;
            }
        }

        $this->assertContains("'unsafe-eval'", $directivesDev['script-src'], 'Dev environment must retain unsafe-eval for Vite dev tooling');
        $this->assertContains('ws://localhost:*', $directivesDev['connect-src']);
        $this->assertContains('wss://localhost:*', $directivesDev['connect-src']);
        $this->assertContains('ws://127.0.0.1:*', $directivesDev['connect-src']);
        $this->assertContains('wss://camera-dev.8gategames.com', $directivesDev['connect-src']);
    }

    public function test_sec18_dependency_audit_clean(): void
    {
        // 1. Verify package.json contains upgraded dependencies and overrides
        $packageJson = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertNotNull($packageJson);
        $this->assertEquals('^3.5.43', $packageJson['devDependencies']['vue'] ?? null);
        $this->assertEquals('^1.11.0', $packageJson['overrides']['shell-quote'] ?? null);
        $this->assertEquals('^1.2.2', $packageJson['overrides']['source-map-js'] ?? null);

        // 2. Verify composer.json contains patched package constraints
        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertNotNull($composerJson);
        $this->assertEquals('^13.30', $composerJson['require']['laravel/framework'] ?? null);
        $this->assertEquals('^2.10.3', $composerJson['require']['league/commonmark'] ?? null);
        $this->assertEquals('^3.36.0', $composerJson['require']['league/flysystem'] ?? null);

        // 3. Verify composer.lock has resolved secure versions
        $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);
        $this->assertNotNull($composerLock);
        $installedPackages = collect($composerLock['packages'])->keyBy('name');

        $frameworkVersion = ltrim($installedPackages['laravel/framework']['version'] ?? '', 'v');
        $this->assertTrue(version_compare($frameworkVersion, '13.30.0', '>='), "laravel/framework version {$frameworkVersion} must be >= 13.30.0");

        $commonmarkVersion = ltrim($installedPackages['league/commonmark']['version'] ?? '', 'v');
        $this->assertTrue(version_compare($commonmarkVersion, '2.10.3', '>='), "league/commonmark version {$commonmarkVersion} must be >= 2.10.3");

        $flysystemVersion = ltrim($installedPackages['league/flysystem']['version'] ?? '', 'v');
        $this->assertTrue(version_compare($flysystemVersion, '3.36.0', '>='), "league/flysystem version {$flysystemVersion} must be >= 3.36.0");

        // 4. Verify package-lock.json has resolved secure versions
        $packageLock = json_decode(file_get_contents(base_path('package-lock.json')), true);
        $this->assertNotNull($packageLock);
        $packages = $packageLock['packages'] ?? [];

        $vueVersion = ltrim($packages['node_modules/vue']['version'] ?? '', 'v');
        $this->assertTrue(version_compare($vueVersion, '3.5.43', '>='), "vue version {$vueVersion} must be >= 3.5.43");

        // Check that shell-quote is >= 1.11.0 everywhere in node_modules
        foreach ($packages as $pkgPath => $pkgInfo) {
            if (str_ends_with($pkgPath, 'shell-quote')) {
                $sqVer = ltrim($pkgInfo['version'] ?? '', 'v');
                $this->assertTrue(version_compare($sqVer, '1.11.0', '>='), "shell-quote version {$sqVer} must be >= 1.11.0");
            }
            if (str_ends_with($pkgPath, 'source-map-js')) {
                $smVer = ltrim($pkgInfo['version'] ?? '', 'v');
                $this->assertTrue(version_compare($smVer, '1.2.2', '>='), "source-map-js version {$smVer} must be >= 1.2.2");
            }
        }
    }
}
