<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\StrangerSnap;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PhpMqtt\Client\MqttClient;
use Tests\TestCase;

class TelemetryDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mqtt_listen_deduplicates_stranger_snaps(): void
    {
        Cache::flush();

        $command = new class extends MqttListenCommand {
            public function testHandleStrangerSnap(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
            {
                $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storageService);
            }
        };

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        $mockStorage = $this->createMock(ImageStorageService::class);
        $mockStorage->method('storeBase64Image')->willReturn('https://example.com/stranger.jpg');

        $payload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => '1026230',
                'SnapID' => 6781,
                'time' => '2026-09-29 17:18:43',
                'targetPosInScene' => '[0,0,0,0]',
            ],
            'pic' => 'base64str',
        ];

        // First call should create record
        $command->testHandleStrangerSnap('1026230', $payload, $payload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(1, StrangerSnap::count());

        // Duplicate call with exact same SnapID should be ignored
        $command->testHandleStrangerSnap('1026230', $payload, $payload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(1, StrangerSnap::count());
    }

    public function test_mqtt_listen_deduplicates_verify_push(): void
    {
        Cache::flush();

        $command = new class extends MqttListenCommand {
            public function testHandleVerify(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
            {
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storageService);
            }
        };

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        $mockStorage = $this->createMock(ImageStorageService::class);
        $mockStorage->method('storeBase64Image')->willReturn('https://example.com/snap.jpg');

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => '1026230',
                'RecordID' => 9999,
                'personId' => 271,
                'customId' => 103,
                'time' => '2026-09-29 17:18:43',
                'VerifyStatus' => 1,
            ],
        ];

        // First call should create record
        $command->testHandleVerify('1026230', $payload, $payload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(1, AccessLog::count());

        // Duplicate call with same RecordID should be ignored
        $command->testHandleVerify('1026230', $payload, $payload['info'], $mockMqtt, $mockStorage);
        $this->assertEquals(1, AccessLog::count());
    }

    public function test_webhook_deduplicates_stranger_snaps(): void
    {
        Cache::flush();

        Device::firstOrCreate(['device_id' => '1026230'], [
            'name' => 'Edge Camera 1026230',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $payload = [
            'info' => [
                'DeviceID' => '1026230',
                'SnapID' => 6781,
                'CreateTime' => '2026-09-29 17:18:43',
            ],
            'Pic' => 'base64str',
        ];

        $response1 = $this->postJson('/api/Subscribe/Snap', $payload);
        $response1->assertOk();
        $this->assertEquals(1, StrangerSnap::count());

        $response2 = $this->postJson('/api/Subscribe/Snap', $payload);
        $response2->assertOk();
        $this->assertEquals(1, StrangerSnap::count());
    }
}
