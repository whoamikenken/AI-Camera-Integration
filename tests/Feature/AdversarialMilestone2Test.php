<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Events\DeviceStatusUpdated;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\StrangerSnap;
use App\Models\User;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpMqtt\Client\MqttClient;
use Tests\TestCase;

class AdversarialMilestone2Test extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $this->org = Organization::create([
            'name' => 'Adversarial Test Org',
            'code' => 'ADV-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->superAdmin->roles()->sync([$superAdminRole->id]);
        $this->superAdmin->load('roles');

        auth()->forgetGuards();
    }

    // =========================================================================
    // 1. SEC-13: Rogue & Inactive MQTT Ingestion Stress Probes
    // =========================================================================

    public function test_adversarial_sec13_rogue_device_verify_push_cannot_inject_access_logs(): void
    {
        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);
        $mockStorage = $this->createMock(ImageStorageService::class);

        $rogueId = 'ROGUE-EXPLOIT-' . uniqid();

        $verifyPayload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $rogueId,
                'RecordID' => 9999,
                'VerifyStatus' => 1,
                'time' => '2026-10-07 12:00:00',
            ],
        ];

        $command->invokeVerifyPush($rogueId, $verifyPayload, $verifyPayload['info'], $mockMqtt, $mockStorage);

        $this->assertEquals(0, AccessLog::where('device_id', $rogueId)->count(), 'Rogue MQTT VerifyPush must not persist AccessLog');
        $staged = Device::where('device_id', $rogueId)->first();
        $this->assertNotNull($staged, 'Rogue device should be staged for review');
        $this->assertFalse((bool) $staged->is_active, 'Rogue device must be inactive');
    }

    public function test_adversarial_sec13_inactive_device_verify_push_dropped(): void
    {
        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);
        $mockStorage = $this->createMock(ImageStorageService::class);

        $inactiveDevice = Device::create([
            'device_id' => 'DISABLED-CAM-01',
            'name' => 'Decommissioned Camera',
            'ip_address' => '192.168.1.111',
            'is_active' => false,
        ]);

        $verifyPayload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => 'DISABLED-CAM-01',
                'RecordID' => 8888,
                'VerifyStatus' => 1,
                'time' => '2026-10-07 12:05:00',
            ],
        ];

        $command->invokeVerifyPush('DISABLED-CAM-01', $verifyPayload, $verifyPayload['info'], $mockMqtt, $mockStorage);

        $this->assertEquals(0, AccessLog::where('device_id', 'DISABLED-CAM-01')->count());
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active, 'Device must remain inactive');
    }

    public function test_adversarial_sec13_inactive_device_stranger_snap_dropped(): void
    {
        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);
        $mockStorage = $this->createMock(ImageStorageService::class);

        $inactiveDevice = Device::create([
            'device_id' => 'DISABLED-SNAP-01',
            'name' => 'Decommissioned Snap Camera',
            'ip_address' => '192.168.1.112',
            'is_active' => false,
        ]);

        $snapPayload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => 'DISABLED-SNAP-01',
                'SnapID' => 7777,
                'time' => '2026-10-07 12:10:00',
            ],
            'pic' => 'fakebase64',
        ];

        $command->invokeStrangerSnapPush('DISABLED-SNAP-01', $snapPayload, $snapPayload['info'], $mockMqtt, $mockStorage);

        $this->assertEquals(0, StrangerSnap::where('device_id', 'DISABLED-SNAP-01')->count());
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active);
    }

    public function test_adversarial_sec13_inactive_device_alert_dropped(): void
    {
        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);
        $mockStorage = $this->createMock(ImageStorageService::class);

        $inactiveDevice = Device::create([
            'device_id' => 'DISABLED-ALERT-01',
            'name' => 'Decommissioned Alert Camera',
            'ip_address' => '192.168.1.113',
            'is_active' => false,
        ]);

        $alertPayload = [
            'operator' => 'ClothHelmetSnapPush',
            'info' => [
                'facesluiceId' => 'DISABLED-ALERT-01',
                'AlarmAction' => 'NO_HELMET',
                'SnapID' => 6666,
                'time' => '2026-10-07 12:15:00',
            ],
            'pic' => 'fakebase64',
        ];

        $command->invokeDeviceAlert('DISABLED-ALERT-01', 'ClothHelmetSnapPush', $alertPayload, $alertPayload['info'], $mockMqtt, $mockStorage);

        $this->assertEquals(0, DeviceAlert::where('device_id', 'DISABLED-ALERT-01')->count());
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active);
    }

    public function test_adversarial_sec13_inactive_device_heartbeat_and_online_do_not_reactivate(): void
    {
        Event::fake([DeviceStatusUpdated::class]);

        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);

        $inactiveDevice = Device::create([
            'device_id' => 'DISABLED-STATUS-01',
            'name' => 'Decommissioned Status Camera',
            'ip_address' => '192.168.1.114',
            'is_active' => false,
        ]);

        Cache::forget('device_hb_throttle:DISABLED-STATUS-01');
        $command->invokeHeartbeat('DISABLED-STATUS-01', ['ip' => '192.168.1.114']);
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active, 'Heartbeat must not reactivate disabled camera');

        $command->invokeOnline('DISABLED-STATUS-01', 'Online', ['facesname' => 'Cam', 'ip' => '192.168.1.114'], $mockMqtt);
        $this->assertFalse((bool) $inactiveDevice->fresh()->is_active, 'Online status must not reactivate disabled camera');

        Event::assertNotDispatched(DeviceStatusUpdated::class);
    }

    // =========================================================================
    // 2. SEC-15: SVG / XML / HTML Upload & Media Rejection Stress Probes
    // =========================================================================

    public function test_adversarial_sec15_svg_with_nested_script_rejected_in_store(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $svgContent = <<<SVG
<?xml version="1.0" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg version="1.1" baseProfile="full" xmlns="http://www.w3.org/2000/svg">
   <polygon id="triangle" points="0,0 0,50 50,0" fill="#009900" stroke="#004400"/>
   <script type="text/javascript">
      alert('XSS');
   </script>
</svg>
SVG;

        $svgFile = UploadedFile::fake()->createWithContent('malicious_payload.svg', $svgContent);

        $response = $this->postJson('/api/personnel', [
            'name' => 'SVG Attack Vector 1',
            'person_type' => 0,
            'photo' => $svgFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_adversarial_sec15_svg_with_onload_attribute_rejected_in_update(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $person = Personnel::create([
            'customize_id' => 99123,
            'name' => 'Existing Target',
            'person_type' => 0,
        ]);

        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg" onload="fetch(\'/api/keys\')"></svg>';
        $svgFile = UploadedFile::fake()->createWithContent('onload_exploit.svg', $svgContent);

        $response = $this->putJson("/api/personnel/{$person->id}", [
            'name' => 'Existing Target Updated',
            'person_type' => 0,
            'photo' => $svgFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_adversarial_sec15_html_and_xml_files_rejected_in_upload(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $htmlFile = UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>');
        $resHtml = $this->postJson('/api/personnel', [
            'name' => 'HTML Uploader',
            'person_type' => 0,
            'photo' => $htmlFile,
        ]);
        $resHtml->assertStatus(422);
        $resHtml->assertJsonValidationErrors(['photo']);

        $xmlFile = UploadedFile::fake()->createWithContent('doc.xml', '<?xml version="1.0"?><root>test</root>');
        $resXml = $this->postJson('/api/personnel', [
            'name' => 'XML Uploader',
            'person_type' => 0,
            'photo' => $xmlFile,
        ]);
        $resXml->assertStatus(422);
        $resXml->assertJsonValidationErrors(['photo']);
    }

    public function test_adversarial_sec15_polyglot_svg_disguised_as_jpg_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Create a real file on disk where content is SVG but extension is .jpg
        $tmp = tempnam(sys_get_temp_dir(), 'polyglot_');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $polyglot = new UploadedFile($tmp, 'disguised.jpg', 'image/jpeg', null, true);

        $response = $this->postJson('/api/personnel', [
            'name' => 'Polyglot Attacker',
            'person_type' => 0,
            'photo' => $polyglot,
        ]);

        // Fileinfo inspects disk content and detects image/svg+xml MIME, so mimes:jpeg,jpg,png,webp fails
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);

        @unlink($tmp);
    }

    public function test_adversarial_sec15_get_media_blocks_uppercase_and_content_type_evasions(): void
    {
        $storageService = app(ImageStorageService::class);

        // Put uppercase and mixed-case extensions
        Storage::disk('biometrics')->put('personnel/CAPS.SVG', '<svg></svg>');
        Storage::disk('biometrics')->put('personnel/CAPS.XML', '<xml></xml>');
        Storage::disk('biometrics')->put('personnel/CAPS.HTML', '<html></html>');
        Storage::disk('biometrics')->put('personnel/CAPS.HTM', '<html></html>');

        $this->assertNull($storageService->getMedia('personnel/CAPS.SVG'), 'Must reject uppercase SVG');
        $this->assertNull($storageService->getMedia('personnel/CAPS.XML'), 'Must reject uppercase XML');
        $this->assertNull($storageService->getMedia('personnel/CAPS.HTML'), 'Must reject uppercase HTML');
        $this->assertNull($storageService->getMedia('personnel/CAPS.HTM'), 'Must reject uppercase HTM');

        // File named .jpg but content is SVG
        Storage::disk('biometrics')->put('personnel/tricky.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>');
        $this->assertNull($storageService->getMedia('personnel/tricky.jpg'), 'Must reject file with SVG MIME even if extension is .jpg');

        // Valid raster image
        $samplePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('biometrics')->put('personnel/clean.png', $samplePng);
        $media = $storageService->getMedia('personnel/clean.png');
        $this->assertNotNull($media);
        $this->assertStringContainsString('png', $media['mime_type']);
    }

    // =========================================================================
    // 3. SEC-16: SSRF Targets & Relative Path Handling Stress Probes
    // =========================================================================

    public function test_adversarial_sec16_photo_path_rejects_broad_ssrf_targets(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $dangerousUrls = [
            'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'http://169.254.169.254/latest/user-data',
            'http://127.0.0.1:8000/api/settings/public',
            'http://127.0.0.2/admin',
            'http://localhost:3000/secrets',
            'http://localhost.localdomain:9000/env',
            'http://10.0.0.1/internal',
            'http://10.255.255.255/intranet',
            'http://172.16.0.1/private',
            'http://172.31.255.255/keys',
            'http://192.168.0.1/config',
            'http://192.168.1.254/gateway',
            'http://[::1]:8080/metrics',
            'file:///etc/passwd',
            'gopher://127.0.0.1:25/x',
        ];

        foreach ($dangerousUrls as $target) {
            $response = $this->postJson('/api/personnel', [
                'name' => 'SSRF Probe',
                'person_type' => 0,
                'photo_path' => $target,
            ]);

            $this->assertEquals(
                422,
                $response->status(),
                "Target [{$target}] in photo_path must be rejected with HTTP 422"
            );
            $response->assertJsonValidationErrors(['photo_path']);
        }
    }

    public function test_adversarial_sec16_photo_path_in_update_rejects_ssrf(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $person = Personnel::create([
            'customize_id' => 99887,
            'name' => 'SSRF Update Victim',
            'person_type' => 0,
        ]);

        $response = $this->putJson("/api/personnel/{$person->id}", [
            'name' => 'SSRF Update Victim',
            'person_type' => 0,
            'photo_path' => 'http://169.254.169.254/meta',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo_path']);
    }

    public function test_adversarial_sec16_symmetric_validation_when_both_fields_supplied(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Empty photo_url, malicious photo_path
        $response = $this->postJson('/api/personnel', [
            'name' => 'Dual Field Probe',
            'person_type' => 0,
            'photo_url' => '',
            'photo_path' => 'http://127.0.0.1:8000/keys',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo_path']);

        // Malicious photo_url, empty photo_path
        $response2 = $this->postJson('/api/personnel', [
            'name' => 'Dual Field Probe 2',
            'person_type' => 0,
            'photo_url' => 'http://127.0.0.1:8000/keys',
            'photo_path' => '',
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['photo_url']);
    }

    public function test_adversarial_sec16_valid_relative_and_storage_paths_allowed(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $samplePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('biometrics')->put('strangers/snap_adversarial.jpg', $samplePng);

        // Relative path
        $response1 = $this->postJson('/api/personnel', [
            'name' => 'Relative Path Enrollee',
            'person_type' => 0,
            'photo_path' => 'strangers/snap_adversarial.jpg',
        ]);
        $response1->assertStatus(201);
        $this->assertDatabaseHas('personnel', [
            'name' => 'Relative Path Enrollee',
        ]);

        // Prefixed with /storage/
        $response2 = $this->postJson('/api/personnel', [
            'name' => 'Storage Prefixed Enrollee',
            'person_type' => 0,
            'photo_path' => '/storage/strangers/snap_adversarial.jpg',
        ]);
        $response2->assertStatus(201);
        $this->assertDatabaseHas('personnel', [
            'name' => 'Storage Prefixed Enrollee',
        ]);
    }

    // =========================================================================
    // 4. SEC-19: Reverse Proxy & Loopback Bypass Behavior Stress Probes
    // =========================================================================

    public function test_adversarial_sec19_production_rejects_loopback_and_spoofed_headers(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $device = Device::create([
            'device_id' => 'CAM-ADV-PROD-01',
            'name' => 'Production Camera',
            'ip_address' => '192.168.1.250',
            'is_active' => true,
        ]);

        // 1. Attacker sends from 127.0.0.1
        $resLoopback = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-ADV-PROD-01',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(401, $resLoopback->status(), 'Loopback IP must not bypass webhook auth in production');

        // 2. Attacker sends from IPv6 ::1
        $resIpv6 = $this->withServerVariables(['REMOTE_ADDR' => '::1'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-ADV-PROD-01',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(401, $resIpv6->status(), 'IPv6 loopback must not bypass webhook auth in production');

        // 3. Camera with registered IP 192.168.1.250 succeeds
        $resCam = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.250'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-ADV-PROD-01',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);
        $this->assertEquals(200, $resCam->status(), 'Camera sending from its registered IP must succeed');
    }

    public function test_adversarial_sec19_inactive_device_cannot_bypass_even_on_loopback(): void
    {
        // Even in testing environment, inactive device cannot bypass
        $inactiveDevice = Device::create([
            'device_id' => 'CAM-ADV-INACTIVE-01',
            'name' => 'Inactive Camera',
            'ip_address' => '192.168.1.251',
            'is_active' => false,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/Subscribe/Verify', [
                'DeviceID' => 'CAM-ADV-INACTIVE-01',
                'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
            ]);

        $this->assertEquals(401, $response->status(), 'Inactive camera must not bypass auth on loopback');
    }

    public function test_adversarial_sec19_trusted_proxy_resolves_forwarded_client_ip(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-ADV-PROXY-01',
            'name' => 'Proxy Camera',
            'ip_address' => '198.51.100.22',
            'is_active' => true,
        ]);

        // Proxied request from real camera IP 198.51.100.22 through proxy 127.0.0.1
        $responseMatching = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.22',
        ])->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-ADV-PROXY-01',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);
        $this->assertEquals(200, $responseMatching->status(), 'Forwarded real IP matching camera registered IP must succeed');

        // Proxied request from rogue IP 203.0.113.88 through proxy 127.0.0.1
        $responseMismatched = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
        ])->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-ADV-PROXY-01',
            'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
        ]);
        $this->assertEquals(401, $responseMismatched->status(), 'Forwarded real IP not matching camera registered IP must be rejected');
    }

    public function test_adversarial_sec15_get_media_endpoint_returns_404_for_svg_and_html(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        Storage::disk('biometrics')->put('personnel/probe_xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("pwn")</script></svg>');
        Storage::disk('biometrics')->put('personnel/probe_page.html', '<html><body>Exploit</body></html>');
        Storage::disk('biometrics')->put('personnel/probe_valid.jpg', 'fake-jpeg-binary');

        $resSvg = $this->get('/api/media/personnel/probe_xss.svg');
        $resSvg->assertStatus(404);

        $resHtml = $this->get('/api/media/personnel/probe_page.html');
        $resHtml->assertStatus(404);

        $resJpg = $this->get('/api/media/personnel/probe_valid.jpg');
        $resJpg->assertStatus(200);
        $resJpg->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_adversarial_sec16_dword_and_hex_encoded_ssrf_urls_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $encodedTargets = [
            'http://2130706433/keys',           // Dword 127.0.0.1
            'http://0x7f000001/keys',           // Hex 127.0.0.1
            'http://0177.0.0.1/keys',           // Octal 127.0.0.1
            'http://2852039166/metadata',       // Dword 169.254.169.254
            'http://0xA9FEA9FE/metadata',       // Hex 169.254.169.254
        ];

        foreach ($encodedTargets as $target) {
            $response = $this->postJson('/api/personnel', [
                'name' => 'Encoded SSRF Probe',
                'person_type' => 0,
                'photo_path' => $target,
            ]);

            $this->assertEquals(422, $response->status(), "Encoded SSRF target [{$target}] must be rejected with HTTP 422");
            $response->assertJsonValidationErrors(['photo_path']);
        }
    }

    public function test_adversarial_sec13_mqtt_listen_with_topic_extracted_device_id(): void
    {
        $command = $this->createHarnessedMqttCommand();
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);
        $mockStorage = $this->createMock(ImageStorageService::class);

        // Topic contains device ID: mqtt/face/ROGUE-TOPIC-CAM/Rec
        // Payload info omits facesluiceId
        $rawMessage = json_encode([
            'operator' => 'VerifyPush',
            'info' => [
                'RecordID' => 12345,
                'VerifyStatus' => 1,
            ],
        ]);

        $command->invokeHandleMessage('mqtt/face/ROGUE-TOPIC-CAM/Rec', $rawMessage, $mockMqtt, $mockStorage);

        $this->assertEquals(0, AccessLog::where('device_id', 'ROGUE-TOPIC-CAM')->count());
        $staged = Device::where('device_id', 'ROGUE-TOPIC-CAM')->first();
        $this->assertNotNull($staged);
        $this->assertFalse((bool) $staged->is_active);
    }

    // =========================================================================
    // Helper Harness
    // =========================================================================

    protected function createHarnessedMqttCommand(): object
    {
        return new class extends MqttListenCommand {
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

            public function invokeHeartbeat(?string $deviceId, array $info): void
            {
                $this->handleHeartbeat($deviceId, $info);
            }

            public function invokeOnline(?string $deviceId, string $operator, array $info, MqttClient $mqtt): void
            {
                $this->handleOnlineStatus($deviceId, $operator, $info, $mqtt);
            }

            public function invokeHandleMessage(string $topic, string $rawMessage, MqttClient $mqtt, ImageStorageService $storage): void
            {
                $this->handleMessage($topic, $rawMessage, $mqtt, $storage);
            }
        };
    }
}
