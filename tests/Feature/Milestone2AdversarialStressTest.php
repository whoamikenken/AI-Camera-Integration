<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Events\DeviceStatusUpdated;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Personnel;
use App\Models\StrangerSnap;
use App\Models\Role;
use App\Models\User;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpMqtt\Client\MqttClient;
use Tests\TestCase;

class Milestone2AdversarialStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected ImageStorageService $storageService;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_active' => true,
        ]);
        $this->superAdmin->roles()->sync([$superAdminRole->id]);
        $this->superAdmin->load('roles');

        $this->storageService = app(ImageStorageService::class);
        Storage::fake('biometrics');
        Storage::fake('public');
    }

    /**
     * Helper to invoke private/protected methods on MqttListenCommand.
     */
    protected function getMqttTestHarness(): object
    {
        return new class extends MqttListenCommand {
            public function invokeMessage(string $topic, string $rawMessage, ?MqttClient $mqtt = null, ?ImageStorageService $storage = null): void
            {
                $mqtt = $mqtt ?? \Mockery::mock(MqttClient::class);
                $storage = $storage ?? app(ImageStorageService::class);
                $this->handleMessage($topic, $rawMessage, $mqtt, $storage);
            }

            public function invokeVerifyPush(?string $deviceId, array $data, array $info, ?MqttClient $mqtt = null, ?ImageStorageService $storage = null): void
            {
                $mqtt = $mqtt ?? \Mockery::mock(MqttClient::class);
                $storage = $storage ?? app(ImageStorageService::class);
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storage);
            }

            public function invokeStrangerSnapPush(?string $deviceId, array $data, array $info, ?MqttClient $mqtt = null, ?ImageStorageService $storage = null): void
            {
                $mqtt = $mqtt ?? \Mockery::mock(MqttClient::class);
                $storage = $storage ?? app(ImageStorageService::class);
                $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storage);
            }

            public function invokeDeviceAlert(?string $deviceId, string $operator, array $data, array $info, ?MqttClient $mqtt = null, ?ImageStorageService $storage = null): void
            {
                $mqtt = $mqtt ?? \Mockery::mock(MqttClient::class);
                $storage = $storage ?? app(ImageStorageService::class);
                $this->handleDeviceAlert($deviceId, $operator, $data, $info, $mqtt, $storage);
            }

            public function invokeHeartbeat(?string $deviceId, array $info): void
            {
                $this->handleHeartbeat($deviceId, $info);
            }

            public function invokeOnline(?string $deviceId, string $operator, array $info, ?MqttClient $mqtt = null): void
            {
                $mqtt = $mqtt ?? \Mockery::mock(MqttClient::class);
                $this->handleOnlineStatus($deviceId, $operator, $info, $mqtt);
            }

            public function invokeCommandAck(?string $deviceId, string $operator, array $data): void
            {
                $this->handleCommandAck($deviceId, $operator, $data);
            }
        };
    }

    // =========================================================================
    // SECTION 1: SEC-13 MQTT LISTENER STRESS TESTS & ROGUE DEVICE PROBING
    // =========================================================================

    public function test_sec13_mqtt_drops_verify_push_for_unregistered_device_and_stages_inactive(): void
    {
        $harness = $this->getMqttTestHarness();
        $mockMqtt = \Mockery::mock(MqttClient::class);
        $mockMqtt->shouldReceive('isConnected')->andReturn(false);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'DeviceID' => 'UNREG-DEV-001',
                'PersonID' => 101,
                'customId' => 202,
                'persionName' => 'Attacker Fake Person',
                'VerifyStatus' => 1,
                'similarity1' => 99.5,
                'time' => now()->toDateTimeString(),
            ],
        ];

        // 1. Process VerifyPush for unknown device
        $harness->invokeMessage('mqtt/face/UNREG-DEV-001/Rec', json_encode($payload), $mockMqtt, $this->storageService);

        // Assert device was staged with is_active = false
        $device = Device::where('device_id', 'UNREG-DEV-001')->first();
        $this->assertNotNull($device, 'Device should be staged in database');
        $this->assertFalse((bool) $device->is_active, 'Staged device MUST be inactive');

        // Assert no AccessLog was created
        $this->assertEquals(0, AccessLog::where('device_id', 'UNREG-DEV-001')->count());

        // 2. Second burst of messages must NOT reactivate device or create access logs
        Cache::forget('device_hb_throttle:UNREG-DEV-001');
        Cache::forget('mqtt_dedup:rec:UNREG-DEV-001:101:' . md5(now()->toDateTimeString()));

        $harness->invokeMessage('mqtt/face/UNREG-DEV-001/Rec', json_encode($payload), $mockMqtt, $this->storageService);
        $this->assertFalse((bool) $device->fresh()->is_active, 'Consecutive message must NOT activate device');
        $this->assertEquals(0, AccessLog::where('device_id', 'UNREG-DEV-001')->count());
    }

    public function test_sec13_mqtt_drops_all_telemetry_types_for_disabled_device(): void
    {
        $disabledDevice = Device::create([
            'device_id' => 'DISABLED-FLEET-CAM',
            'name' => 'Compromised Camera',
            'ip_address' => '10.0.0.99',
            'is_active' => false,
        ]);

        $harness = $this->getMqttTestHarness();
        $mockMqtt = \Mockery::mock(MqttClient::class);
        $mockMqtt->shouldReceive('isConnected')->andReturn(false);

        // 1. VerifyPush
        $harness->invokeVerifyPush('DISABLED-FLEET-CAM', [], [
            'RecordID' => 1,
            'PersonID' => 50,
            'VerifyStatus' => 1,
        ], $mockMqtt, $this->storageService);
        $this->assertEquals(0, AccessLog::where('device_id', 'DISABLED-FLEET-CAM')->count());

        // 2. StrangerSnapPush
        $harness->invokeStrangerSnapPush('DISABLED-FLEET-CAM', [], [
            'SnapID' => 1,
            'time' => now()->toDateTimeString(),
        ], $mockMqtt, $this->storageService);
        $this->assertEquals(0, StrangerSnap::where('device_id', 'DISABLED-FLEET-CAM')->count());

        // 3. AI Safety Alarms (ClothHelmetSnapPush, FireSmokeSnapPush, etc.)
        $alertTypes = ['ClothHelmetSnapPush', 'FireSmokeSnapPush', 'BehaviorSnapPush', 'PlateSnapPush'];
        foreach ($alertTypes as $alertOp) {
            $harness->invokeDeviceAlert('DISABLED-FLEET-CAM', $alertOp, [], [
                'AlarmType' => 1,
                'time' => now()->toDateTimeString(),
            ], $mockMqtt, $this->storageService);
        }
        $this->assertEquals(0, DeviceAlert::where('device_id', 'DISABLED-FLEET-CAM')->count());

        // Assert device remained strictly inactive
        $this->assertFalse((bool) $disabledDevice->fresh()->is_active);
    }

    public function test_sec13_mqtt_handles_malformed_and_adversarial_payloads_without_crashing(): void
    {
        $harness = $this->getMqttTestHarness();
        $mockMqtt = \Mockery::mock(MqttClient::class);

        $adversarialMessages = [
            // Not valid JSON
            'malformed-json-content',
            '',
            '   ',
            '{broken',
            // Valid JSON but non-object
            '12345',
            'true',
            'null',
            '["array", "of", "items"]',
            // Object missing operator
            json_encode(['info' => ['DeviceID' => 'TEST']]),
            // Empty operator
            json_encode(['operator' => '', 'info' => []]),
            // Unknown operator
            json_encode(['operator' => 'UnrecognizedCustomExploit', 'info' => ['DeviceID' => 'TEST']]),
            // Null info
            json_encode(['operator' => 'VerifyPush', 'info' => null]),
            // Empty info
            json_encode(['operator' => 'VerifyPush', 'info' => []]),
            // Integer facesluiceId
            json_encode(['operator' => 'HeartBeat', 'info' => ['facesluiceId' => 99999]]),
            // Very long deviceId (SQL truncation probe)
            json_encode(['operator' => 'HeartBeat', 'info' => ['facesluiceId' => str_repeat('A', 60)]]),
            // SQL injection / special characters in deviceId
            json_encode(['operator' => 'HeartBeat', 'info' => ['facesluiceId' => "DEV' OR 1=1 --;"]]),
            // Topic path fallback probe
            json_encode(['operator' => 'HeartBeat', 'info' => []]),
        ];

        foreach ($adversarialMessages as $index => $msg) {
            try {
                $harness->invokeMessage("mqtt/face/ADV-TOPIC-{$index}/Rec", $msg, $mockMqtt, $this->storageService);
                $this->assertTrue(true, "Message index {$index} handled gracefully");
            } catch (\Throwable $e) {
                $this->fail("MqttListenCommand threw uncaught exception on adversarial message index {$index}: " . $e->getMessage());
            }
        }
    }

    public function test_sec13_active_device_heartbeat_updates_timestamp_and_broadcasts(): void
    {
        Event::fake([DeviceStatusUpdated::class]);

        $activeDevice = Device::create([
            'device_id' => 'ACTIVE-PROD-CAM-01',
            'name' => 'Front Gate Cam',
            'ip_address' => '192.168.1.150',
            'is_active' => true,
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $harness = $this->getMqttTestHarness();
        Cache::forget('device_hb_throttle:ACTIVE-PROD-CAM-01');

        $harness->invokeHeartbeat('ACTIVE-PROD-CAM-01', [
            'ip' => '192.168.1.150',
            'time' => now()->toDateTimeString(),
        ]);

        $this->assertTrue($activeDevice->fresh()->last_heartbeat_at->diffInSeconds(now()) < 5);
        Event::assertDispatched(DeviceStatusUpdated::class);
    }

    public function test_sec13_inactive_device_heartbeat_does_not_broadcast_status(): void
    {
        Event::fake([DeviceStatusUpdated::class]);

        $inactiveDevice = Device::create([
            'device_id' => 'INACTIVE-CAM-02',
            'name' => 'Decommissioned Cam',
            'ip_address' => '192.168.1.151',
            'is_active' => false,
        ]);

        $harness = $this->getMqttTestHarness();
        Cache::forget('device_hb_throttle:INACTIVE-CAM-02');

        $harness->invokeHeartbeat('INACTIVE-CAM-02', [
            'ip' => '192.168.1.151',
        ]);

        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active);
        Event::assertNotDispatched(DeviceStatusUpdated::class);
    }

    // =========================================================================
    // SECTION 2: SEC-15 BIOMETRIC UPLOAD MIME TYPE & STORED XSS PROBING
    // =========================================================================

    public function test_sec15_personnel_photo_upload_rejects_disguised_and_polyglot_svgs(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $maliciousFiles = [
            // 1. Direct SVG
            'standard.svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            // 2. Uppercase SVG extension
            'UPPERCASE.SVG' => ['<svg><circle r="10"/></svg>', 'image/svg+xml'],
            // 3. SVG disguised as JPG
            'disguised.jpg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            // 4. SVG disguised as PNG
            'disguised.png' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            // 5. HTML file
            'exploit.html' => ['<html><head><script>alert("xss")</script></head></html>', 'text/html'],
            // 6. XML file
            'payload.xml' => ['<?xml version="1.0"?><note><body>test</body></note>', 'text/xml'],
            // 7. PHP script
            'webshell.php' => ['<?php echo phpinfo(); ?>', 'application/x-php'],
        ];

        foreach ($maliciousFiles as $filename => [$content, $mime]) {
            $tmp = tempnam(sys_get_temp_dir(), 'test_sec15_');
            file_put_contents($tmp, $content);
            $file = new UploadedFile($tmp, $filename, $mime, null, true);

            $response = $this->postJson('/api/personnel', [
                'name' => "Malicious Tester {$filename}",
                'person_type' => 0,
                'photo' => $file,
            ]);

            @unlink($tmp);

            $response->assertStatus(422, "File {$filename} should be rejected with 422");
            $response->assertJsonValidationErrors(['photo']);
        }
    }

    public function test_sec15_personnel_update_rejects_svg_uploads(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $person = Personnel::create([
            'customize_id' => 99101,
            'name' => 'Existing Personnel Before Update',
            'person_type' => 0,
        ]);

        $svgFile = UploadedFile::fake()->createWithContent('malicious_update.svg', '<svg><script>alert(1)</script></svg>');

        $response = $this->putJson("/api/personnel/{$person->id}", [
            'name' => 'Updated Person',
            'person_type' => 0,
            'photo' => $svgFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_sec15_get_media_strictly_blocks_scriptable_formats(): void
    {
        // Place dangerous test files on biometrics disk
        $dangerousFiles = [
            'personnel/attack.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'personnel/attack.SVG' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'personnel/attack.xml' => '<?xml version="1.0"?><root></root>',
            'personnel/attack.html' => '<html><body>XSS</body></html>',
            'personnel/attack.htm' => '<html><body>XSS</body></html>',
        ];

        foreach ($dangerousFiles as $path => $content) {
            Storage::disk('biometrics')->put($path, $content);
            $result = $this->storageService->getMedia($path);
            $this->assertNull($result, "getMedia() must return null for dangerous file: {$path}");
        }

        // Legitimate raster files must be returned cleanly
        Storage::disk('biometrics')->put('personnel/clean.jpg', 'valid-binary-jpeg-data');
        Storage::disk('biometrics')->put('personnel/clean.png', 'valid-binary-png-data');
        Storage::disk('biometrics')->put('personnel/clean.webp', 'valid-binary-webp-data');

        $this->assertNotNull($this->storageService->getMedia('personnel/clean.jpg'));
        $this->assertNotNull($this->storageService->getMedia('personnel/clean.png'));
        $this->assertNotNull($this->storageService->getMedia('personnel/clean.webp'));
    }

    // =========================================================================
    // SECTION 3: SEC-16 SSRF PROBING & EVASION VARIATIONS ON photo_path / photo_url
    // =========================================================================

    public function test_sec16_photo_path_rejects_extensive_ssrf_payload_variations(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $ssrfTargets = [
            // Cloud metadata (AWS, GCP, Azure, OpenStack)
            'http://169.254.169.254/latest/meta-data/',
            'http://169.254.169.254/computeMetadata/v1/',
            'http://169.254.1.1/secret',
            // Localhost / Loopback variations
            'http://127.0.0.1:8000/admin',
            'http://localhost/private',
            'http://localhost.localdomain/keys',
            'http://127.0.0.1.nip.io/exploit',
            'http://127.127.127.127/',
            'http://0.0.0.0:80/',
            // Alternative representations
            'http://0177.0.0.1/admin',       // Octal
            'http://2130706433/admin',       // Decimal
            'http://0x7f000001/admin',       // Hex
            // RFC 1918 Private Ranges
            'http://10.0.0.1/credentials',
            'http://172.16.0.1/internal',
            'http://192.168.1.1/router',
            // Non-HTTP URI schemes
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/_flushall',
            'ftp://127.0.0.1/test.jpg',
            // Userinfo tricks
            'http://user:pass@127.0.0.1/test',
            'http://127.0.0.1#@example.com/',
        ];

        foreach ($ssrfTargets as $target) {
            $response = $this->postJson('/api/personnel', [
                'name' => 'SSRF Probe User',
                'person_type' => 0,
                'photo_path' => $target,
            ]);

            // If it evaluates as a URL, it must be rejected with 422
            if (filter_var($target, FILTER_VALIDATE_URL)) {
                $response->assertStatus(422, "SSRF target [{$target}] should be rejected with 422");
                $response->assertJsonValidationErrors(['photo_path']);
            }
        }
    }

    public function test_sec16_dual_input_validation_catches_asymmetric_ssrf(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Case A: Safe photo_url, but malicious SSRF in photo_path
        $responseA = $this->postJson('/api/personnel', [
            'name' => 'Dual Input Probe A',
            'person_type' => 0,
            'photo_url' => 'https://example.com/clean.jpg',
            'photo_path' => 'http://169.254.169.254/latest/meta-data/',
        ]);
        $responseA->assertStatus(422);
        $responseA->assertJsonValidationErrors(['photo_path']);

        // Case B: Malicious SSRF in photo_url, but valid relative string in photo_path
        $responseB = $this->postJson('/api/personnel', [
            'name' => 'Dual Input Probe B',
            'person_type' => 0,
            'photo_url' => 'http://127.0.0.1:8000/api/keys',
            'photo_path' => 'strangers/clean_relative.jpg',
        ]);
        $responseB->assertStatus(422);
        $responseB->assertJsonValidationErrors(['photo_url']);
    }

    public function test_sec16_legitimate_relative_paths_and_safe_public_urls_succeed(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Legitimate relative paths from stranger snaps
        $resRelative = $this->postJson('/api/personnel', [
            'name' => 'Legit Relative Personnel',
            'person_type' => 0,
            'photo_path' => 'strangers/2026/10/snapshot_987.jpg',
        ]);
        $resRelative->assertStatus(201);
        $this->assertDatabaseHas('personnel', [
            'name' => 'Legit Relative Personnel',
            'photo_path' => 'strangers/2026/10/snapshot_987.jpg',
        ]);

        // Legitimate public domain URL
        $resPublic = $this->postJson('/api/personnel', [
            'name' => 'Legit Public URL Personnel',
            'person_type' => 0,
            'photo_path' => 'https://example.com/avatar_test.jpg',
        ]);
        $resPublic->assertStatus(201);
    }

    // =========================================================================
    // SECTION 4: SEC-19 REVERSE-PROXY TRUST HEADERS & LOOPBACK BYPASS PROBING
    // =========================================================================

    public function test_sec19_webhook_production_rejects_loopback_and_untrusted_spoofed_ips(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $device = Device::create([
            'device_id' => 'CAM-EDGE-PROD-99',
            'name' => 'Edge Camera 99',
            'ip_address' => '192.168.1.200',
            'is_active' => true,
        ]);

        // Probe 1: Direct connection claiming loopback 127.0.0.1
        $res1 = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-EDGE-PROD-99',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(401, $res1->status(), 'Loopback IP must be rejected in production environment');

        // Probe 2: Reverse proxy with untrusted forwarded client IP
        $res2 = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.55',
        ])->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-EDGE-PROD-99',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);
        $this->assertEquals(401, $res2->status(), 'Untrusted forwarded IP must be rejected');

        // Probe 3: Reverse proxy forwarding REAL camera IP (192.168.1.200)
        $res3 = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.200',
        ])->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-EDGE-PROD-99',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);
        $this->assertEquals(200, $res3->status(), 'Matching camera IP via reverse proxy should authenticate');
    }

    public function test_sec19_webhook_rejects_inactive_device_even_with_matching_ip(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $inactiveDevice = Device::create([
            'device_id' => 'CAM-DISABLED-PROD',
            'name' => 'Decommissioned Edge Camera',
            'ip_address' => '192.168.1.210',
            'is_active' => false,
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.210',
        ])->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-DISABLED-PROD',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);

        // Must reject inactive device with 401 or 403
        $this->assertTrue(
            in_array($response->status(), [401, 403], true),
            "Inactive camera must be rejected with 401 or 403, got {$response->status()}"
        );
    }

    public function test_sec19_webhook_secret_authenticates_independent_of_client_ip(): void
    {
        config(['services.camera.webhook_secret' => 'super-secure-webhook-token-2026']);

        $device = Device::create([
            'device_id' => 'CAM-TOKEN-PROD',
            'name' => 'Token Authenticated Cam',
            'ip_address' => '192.168.1.220',
            'is_active' => true,
        ]);

        // Wrong token -> 401
        $resFail = $this->withHeaders(['X-Webhook-Secret' => 'wrong-secret'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-TOKEN-PROD',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(401, $resFail->status());

        // Correct token -> 200
        $resPass = $this->withHeaders(['X-Webhook-Secret' => 'super-secure-webhook-token-2026'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-TOKEN-PROD',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(200, $resPass->status());
    }
}
