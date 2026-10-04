<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Personnel;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class Challenger1AdversarialTest extends TestCase
{
    use RefreshDatabase;

    public bool $disableAutoAuth = true;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(RateLimiter::availableIn(''));
        Cache::flush();
    }

    // =========================================================================
    // 1. Concurrency & Atomic Sequence Tests
    // =========================================================================

    public function test_personnel_create_produces_monotonic_unique_customize_ids_rapid_sequence(): void
    {
        Event::fake([\App\Events\PersonnelUpdated::class]);
        Queue::fake();

        $count = 30;
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = Personnel::create([
                'name' => "Sequential Personnel {$i}",
            ]);
        }

        $this->assertCount($count, $created);

        $customizeIds = array_map(fn($p) => $p->customize_id, $created);
        $uniqueIds = array_unique($customizeIds);

        // Assert 100% unique IDs
        $this->assertCount($count, $uniqueIds, 'All generated customize_ids must be unique');

        // Assert strict monotonicity
        for ($i = 1; $i < count($customizeIds); $i++) {
            $this->assertGreaterThan(
                $customizeIds[$i - 1],
                $customizeIds[$i],
                "customize_id at index {$i} ({$customizeIds[$i]}) must be greater than index " . ($i - 1) . " ({$customizeIds[$i - 1]})"
            );
        }
    }

    public function test_personnel_create_concurrent_process_stress(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL sequence concurrency test requires pgsql driver.');
        }

        // Test multi-process fork concurrency against live PostgreSQL sequence
        $numWorkers = 5;
        $insertsPerWorker = 10;
        $totalExpected = $numWorkers * $insertsPerWorker;

        $pids = [];
        for ($w = 0; $w < $numWorkers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail("Could not fork child process {$w}");
            } elseif ($pid) {
                $pids[] = $pid;
            } else {
                DB::purge();
                DB::reconnect();
                Event::fake([\App\Events\PersonnelUpdated::class]);
                Queue::fake();

                for ($j = 0; $j < $insertsPerWorker; $j++) {
                    try {
                        Personnel::create([
                            'name' => "Fork Worker {$w} Item {$j}",
                        ]);
                    } catch (\Throwable $e) {
                        exit(1);
                    }
                }
                exit(0);
            }
        }

        $allSucceeded = true;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) !== 0) {
                $allSucceeded = false;
            }
        }

        $this->assertTrue($allSucceeded, 'All child worker processes must succeed without SQL collision');

        DB::purge();
        DB::reconnect();

        $records = Personnel::where('name', 'like', 'Fork Worker %')
            ->orderBy('customize_id', 'asc')
            ->get();

        $this->assertCount($totalExpected, $records);
        $ids = $records->pluck('customize_id')->toArray();
        $this->assertCount($totalExpected, array_unique($ids), 'All concurrent customize_ids must be unique');

        for ($k = 1; $k < count($ids); $k++) {
            $this->assertGreaterThan(
                $ids[$k - 1],
                $ids[$k],
                "Concurrent customize_id at index {$k} must be strictly greater than preceding index"
            );
        }
    }

    // =========================================================================
    // 2. Webhook Security & Rate-Limiting Tests
    // =========================================================================

    public function test_webhook_endpoints_reject_missing_or_invalid_secret_token(): void
    {
        config(['services.camera.webhook_secret' => 'strict-test-token-777']);

        $device = Device::create([
            'device_id' => 'CAM-AUTH-TEST-01',
            'name' => 'Auth Test Cam',
            'ip_address' => '203.0.113.10',
            'is_active' => true,
        ]);

        $endpoints = [
            '/api/Subscribe/heartbeat' => ['DeviceID' => 'CAM-AUTH-TEST-01'],
            '/api/Subscribe/Verify' => ['DeviceID' => 'CAM-AUTH-TEST-01', 'info' => ['PersonID' => 1, 'VerifyStatus' => 1]],
            '/api/Subscribe/Snap' => ['DeviceID' => 'CAM-AUTH-TEST-01', 'info' => ['SnapID' => 1]],
        ];

        foreach ($endpoints as $uri => $payload) {
            // Case 1: Missing secret/token
            $resMissing = $this->postJson($uri, $payload, [
                'REMOTE_ADDR' => '203.0.113.10',
            ]);
            $this->assertEquals(401, $resMissing->status(), "Endpoint {$uri} must return 401 when secret token is missing");

            // Case 2: Invalid secret/token
            $resInvalid = $this->postJson($uri, $payload, [
                'REMOTE_ADDR' => '203.0.113.10',
                'X-Camera-Secret' => 'completely-wrong-token',
            ]);
            $this->assertEquals(401, $resInvalid->status(), "Endpoint {$uri} must return 401 when secret token is invalid");

            // Case 3: Valid secret token
            $resValid = $this->postJson($uri, $payload, [
                'REMOTE_ADDR' => '203.0.113.10',
                'X-Camera-Secret' => 'strict-test-token-777',
            ]);
            $this->assertEquals(200, $resValid->status(), "Endpoint {$uri} must return 200 with valid secret token");
        }
    }

    public function test_webhook_header_secret_crashes_with_500_due_to_unencrypted_db_default_password(): void
    {
        config(['services.camera.webhook_secret' => null]);

        $device = Device::create([
            'device_id' => 'CAM-DECRYPT-VULN',
            'name' => 'Decrypt Crash Cam',
            'ip_address' => '203.0.113.15',
            'is_active' => true,
        ]);

        // When a device is loaded from DB with default 'admin', evaluating $device->password
        // throws DecryptException and yields HTTP 500 rather than cleanly returning 401.
        $response = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-DECRYPT-VULN',
            'info' => ['PersonID' => 1],
        ], [
            'REMOTE_ADDR' => '203.0.113.15',
            'X-Camera-Secret' => 'bad-secret',
        ]);

        // Verifies the vulnerability is resolved: DecryptException does NOT cause 500 and returns 401
        $this->assertEquals(401, $response->status(), 'Unhandled DecryptException fixed; endpoint cleanly returns 401');
    }

    public function test_device_password_encrypted_cast_fails_on_postgresql_due_to_varchar_64(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL schema vulnerability test requires pgsql driver.');
        }

        // Empirical demonstration: Setting password on Device triggers SQLSTATE[22001]
        // because password column was created as VARCHAR(64), but Eloquent encrypted cast
        // produces a 200+ character base64 JSON payload.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/value too long for type character varying\(64\)/');

        Device::create([
            'device_id' => 'CAM-VULN-TRUNCATION',
            'name' => 'Truncation Vulnerability Cam',
            'ip_address' => '192.168.1.100',
            'password' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_webhook_unknown_camera_id_handling(): void
    {
        config(['services.camera.webhook_secret' => 'master-secret-key']);

        $unknownDeviceId = 'UNKNOWN-CAM-' . uniqid();

        // 1. /api/Subscribe/Verify with unknown camera (unauthenticated) -> 401, camera is NOT created
        $resVerifyNoAuth = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => $unknownDeviceId,
            'info' => ['PersonID' => 10],
        ], ['REMOTE_ADDR' => '203.0.113.50']);

        $this->assertEquals(401, $resVerifyNoAuth->status(), 'Unauthenticated verify push from unknown camera must be rejected with 401');
        $this->assertNull(Device::where('device_id', $unknownDeviceId)->first(), 'Verify push must not auto-create device');

        // 2. /api/Subscribe/Verify with unknown camera (authenticated with valid secret) -> 403 Forbidden, camera is NOT created
        $resVerifyAuth = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => $unknownDeviceId,
            'info' => ['PersonID' => 10],
        ], [
            'REMOTE_ADDR' => '203.0.113.50',
            'X-Camera-Secret' => 'master-secret-key',
        ]);

        $this->assertEquals(403, $resVerifyAuth->status(), 'Authenticated verify push from unknown camera must return 403 Forbidden');
        $this->assertNull(Device::where('device_id', $unknownDeviceId)->first(), 'Authenticated verify push must not auto-create device');

        // 3. /api/Subscribe/Snap with unknown camera -> 403 Forbidden when authenticated, camera is NOT created
        $resSnap = $this->postJson('/api/Subscribe/Snap', [
            'DeviceID' => $unknownDeviceId,
            'info' => ['SnapID' => 10],
        ], [
            'REMOTE_ADDR' => '203.0.113.50',
            'X-Camera-Secret' => 'master-secret-key',
        ]);

        $this->assertEquals(403, $resSnap->status(), 'Snap push from unknown camera must return 403 Forbidden');
        $this->assertNull(Device::where('device_id', $unknownDeviceId)->first(), 'Snap push must not auto-create device');

        // 4. /api/Subscribe/heartbeat with unknown camera -> 200 OK, staged as INACTIVE (is_active = false)
        $resHb = $this->postJson('/api/Subscribe/heartbeat', [
            'DeviceID' => $unknownDeviceId,
            'info' => ['Name' => 'Discovered Camera'],
        ], [
            'REMOTE_ADDR' => '203.0.113.50',
            'X-Camera-Secret' => 'master-secret-key',
        ]);

        $this->assertEquals(200, $resHb->status());
        $stagedDevice = Device::where('device_id', $unknownDeviceId)->first();
        $this->assertNotNull($stagedDevice, 'Heartbeat from new camera should be staged in database');
        $this->assertFalse((bool) $stagedDevice->is_active, 'Staged camera must have is_active=false (not auto-activated)');

        // 5. Staged inactive camera attempting /api/Subscribe/Verify must be rejected with 403
        $resStagedVerify = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => $unknownDeviceId,
            'info' => ['PersonID' => 10],
        ], [
            'REMOTE_ADDR' => '203.0.113.50',
            'X-Camera-Secret' => 'master-secret-key',
        ]);

        $this->assertEquals(403, $resStagedVerify->status(), 'Inactive staged camera must be rejected on Verify push with 403 Forbidden');
    }

    public function test_webhook_rejects_payload_with_fake_base64_exceeding_10mb(): void
    {
        config(['services.camera.webhook_secret' => null]);

        $device = Device::create([
            'device_id' => 'CAM-PAYLOAD-TEST',
            'name' => 'Payload Test Cam',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        // Generate fake Base64 string > 10MB (10MB + 128 bytes)
        $hugeBase64 = str_repeat('A', (10 * 1024 * 1024) + 128);

        // Verify endpoint
        $resVerify = $this->postJson('/api/Subscribe/Verify', [
            'DeviceID' => 'CAM-PAYLOAD-TEST',
            'SanpPic' => $hugeBase64,
            'info' => ['PersonID' => 1],
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertEquals(400, $resVerify->status(), 'Oversized Base64 in Verify push must return 400 Bad Request');
        $this->assertStringContainsString('oversized', strtolower($resVerify->json('desc') ?? ''));

        // Snap endpoint
        $resSnap = $this->postJson('/api/Subscribe/Snap', [
            'DeviceID' => 'CAM-PAYLOAD-TEST',
            'SanpPic' => $hugeBase64,
            'info' => ['SnapID' => 1],
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertEquals(400, $resSnap->status(), 'Oversized Base64 in Snap push must return 400 Bad Request');
        $this->assertStringContainsString('oversized', strtolower($resSnap->json('desc') ?? ''));
    }

    public function test_webhook_rate_limiting_enforces_429_on_burst_of_70_plus_requests(): void
    {
        RateLimiter::clear(RateLimiter::availableIn(''));
        config(['services.camera.webhook_secret' => null]);

        $device = Device::create([
            'device_id' => 'CAM-RATE-TEST',
            'name' => 'Rate Limit Test Cam',
            'ip_address' => '198.51.100.99',
            'is_active' => true,
        ]);

        $ip = '198.51.100.99';
        $got429 = false;
        $successCount = 0;
        $rateLimitedCount = 0;

        for ($i = 1; $i <= 75; $i++) {
            $response = $this->postJson('/api/Subscribe/heartbeat', [
                'DeviceID' => 'CAM-RATE-TEST',
            ], [
                'REMOTE_ADDR' => $ip,
            ]);

            if ($response->status() === 429) {
                $got429 = true;
                $rateLimitedCount++;
            } elseif ($response->status() === 200) {
                $successCount++;
            }
        }

        $this->assertTrue($got429, 'Rate limiter must trigger HTTP 429 when burst exceeds 60 requests per minute');
        $this->assertGreaterThan(0, $rateLimitedCount, 'Burst beyond 60 must receive 429 responses');
        $this->assertLessThanOrEqual(60, $successCount, 'At most 60 requests should succeed before rate limit');
    }

    // =========================================================================
    // 3. Redis Heartbeat Throttling Tests
    // =========================================================================

    public function test_redis_heartbeat_throttling_limits_database_writes_to_once_per_60s(): void
    {
        config(['services.camera.webhook_secret' => null]);

        $initialHeartbeat = now()->subMinutes(10)->startOfSecond();

        $device = Device::create([
            'device_id' => 'CAM-THROTTLE-HB-01',
            'name' => 'Throttled Heartbeat Camera',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
            'last_heartbeat_at' => $initialHeartbeat,
        ]);

        $throttleKey = "device_hb_throttle:CAM-THROTTLE-HB-01";
        Cache::forget($throttleKey);

        // 1. First heartbeat: updates database and populates Redis throttle cache
        $firstResponse = $this->postJson('/api/Subscribe/heartbeat', [
            'DeviceID' => 'CAM-THROTTLE-HB-01',
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertEquals(200, $firstResponse->status());

        $device->refresh();
        $firstUpdatedTimestamp = $device->last_heartbeat_at->toIso8601String();
        $this->assertNotEquals($initialHeartbeat->toIso8601String(), $firstUpdatedTimestamp);
        $this->assertTrue(Cache::has($throttleKey), 'Redis throttle key must exist after first heartbeat');

        // 2. Simulate 9 rapid subsequent heartbeats within seconds
        for ($i = 2; $i <= 10; $i++) {
            $subsequentResponse = $this->postJson('/api/Subscribe/heartbeat', [
                'DeviceID' => 'CAM-THROTTLE-HB-01',
            ], ['REMOTE_ADDR' => '127.0.0.1']);

            $this->assertEquals(200, $subsequentResponse->status());

            $device->refresh();
            $this->assertEquals(
                $firstUpdatedTimestamp,
                $device->last_heartbeat_at->toIso8601String(),
                "Heartbeat {$i} must not update last_heartbeat_at in DB due to Redis 60s throttling"
            );
        }
    }

    // =========================================================================
    // 4. SSRF Defense Validation Tests
    // =========================================================================

    public function test_image_storage_service_rejects_all_adversarial_ssrf_urls(): void
    {
        $storageService = app(ImageStorageService::class);

        $adversarialUrls = [
            'http://127.0.0.1/test.jpg',
            'http://localhost/test.jpg',
            'http://169.254.169.254/latest/meta-data',
            'http://10.0.0.1/test.jpg',
            'http://192.168.1.1/test.jpg',
            'http://172.16.0.1/test.jpg',
            'http://[::1]/test.jpg',
            // Additional boundary probes
            'http://127.0.0.2/test.jpg',
            'http://169.254.1.1/metadata',
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/_',
            'ftp://127.0.0.1/test.jpg',
        ];

        foreach ($adversarialUrls as $url) {
            $isSafe = $storageService->isSafeUrl($url);
            $this->assertFalse($isSafe, "URL '{$url}' must be classified as unsafe (SSRF)");

            $stored = $storageService->storeFromUrlOrPath($url);
            $this->assertNull($stored, "URL '{$url}' must return null and abort fetch");
        }
    }
}
