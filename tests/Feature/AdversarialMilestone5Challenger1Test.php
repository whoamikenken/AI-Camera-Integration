<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Events\DeviceCommandCompleted;
use App\Jobs\ProcessAttendancePunchJob;
use App\Jobs\ProcessTelemetryPacketJob;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\StrangerSnap;
use App\Services\CameraMqttService;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PhpMqtt\Client\MqttClient;
use Tests\TestCase;

class AdversarialMilestone5Challenger1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Helper to instantiate a testable MqttListenCommand subclass.
     */
    protected function makeTestableMqttCommand(): MqttListenCommand
    {
        return new class extends MqttListenCommand {
            public function invokeHandleVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ?ImageStorageService $storageService = null): void
            {
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storageService);
            }

            public function invokeHandleStrangerSnapPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ?ImageStorageService $storageService = null): void
            {
                $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storageService);
            }

            public function invokeSendPushAck(?string $deviceId, int $ackType, int $recordOrSnapId, MqttClient $mqtt): void
            {
                $this->sendPushAck($deviceId, $ackType, $recordOrSnapId, $mqtt);
            }

            public function invokeIsDeviceRegisteredAndActive(string $deviceId): bool
            {
                return $this->isDeviceRegisteredAndActive($deviceId);
            }
        };
    }

    // =========================================================================
    // SECTION 1: Zero-Latency Ingestion & Non-blocking Ingress
    // =========================================================================

    public function test_handle_verify_push_immediately_transmits_hardware_push_ack(): void
    {
        $device = Device::factory()->create([
            'device_id' => 'CAM-ZERO-LAT-01',
            'is_active' => true,
        ]);

        $publishedTopic = null;
        $publishedPayload = null;

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(true);
        $mockMqtt->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (string $topic, string $payload, int $qos) use (&$publishedTopic, &$publishedPayload) {
                $publishedTopic = $topic;
                $publishedPayload = json_decode($payload, true);
            });

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $command = $this->makeTestableMqttCommand();

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 78910,
                'customId' => 101,
                'similarity1' => 96.5,
                'time' => now()->format('Y-m-d H:i:s'),
                'SanpPic' => 'data:image/jpeg;base64,' . base64_encode('test-snap'),
            ],
        ];

        $command->invokeHandleVerifyPush($device->device_id, $payload, $payload['info'], $mockMqtt);

        // Verify PushAck topic and structure
        $this->assertEquals("mqtt/face/{$device->device_id}", $publishedTopic);
        $this->assertIsArray($publishedPayload);
        $this->assertEquals('PushAck', $publishedPayload['operator']);
        $this->assertStringStartsWith('ACK-', $publishedPayload['messageId']);
        $this->assertEquals(2, $publishedPayload['info']['PushAckType']);
        $this->assertEquals(78910, $publishedPayload['info']['SnapOrRecordID']);

        // Verify offloaded to queue
        Queue::assertPushed(ProcessTelemetryPacketJob::class, function ($job) use ($device) {
            return $job->deviceId === $device->device_id && $job->operator === 'VerifyPush';
        });
    }

    public function test_handle_stranger_snap_immediately_transmits_hardware_push_ack(): void
    {
        $device = Device::factory()->create([
            'device_id' => 'CAM-ZERO-LAT-02',
            'is_active' => true,
        ]);

        $publishedTopic = null;
        $publishedPayload = null;

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(true);
        $mockMqtt->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (string $topic, string $payload, int $qos) use (&$publishedTopic, &$publishedPayload) {
                $publishedTopic = $topic;
                $publishedPayload = json_decode($payload, true);
            });

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $command = $this->makeTestableMqttCommand();

        $payload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'SnapID' => 33445,
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $command->invokeHandleStrangerSnapPush($device->device_id, $payload, $payload['info'], $mockMqtt);

        $this->assertEquals("mqtt/face/{$device->device_id}", $publishedTopic);
        $this->assertEquals('PushAck', $publishedPayload['operator']);
        $this->assertEquals(1, $publishedPayload['info']['PushAckType']);
        $this->assertEquals(33445, $publishedPayload['info']['SnapOrRecordID']);

        Queue::assertPushed(ProcessTelemetryPacketJob::class);
    }

    public function test_listener_thread_does_not_execute_disk_io_or_base64_decoding(): void
    {
        $device = Device::factory()->create([
            'device_id' => 'CAM-ZERO-LAT-03',
            'is_active' => true,
        ]);

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        // Mock ImageStorageService to assert it is NEVER called during listener command execution
        $mockStorage = $this->createMock(ImageStorageService::class);
        $mockStorage->expects($this->never())->method('storeBase64Image');
        $mockStorage->expects($this->never())->method('saveBase64Image');

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $hugeFakeBase64 = str_repeat('A', 500000); // 500KB string
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 99001,
                'customId' => 202,
                'time' => now()->format('Y-m-d H:i:s'),
                'SanpPic' => 'data:image/jpeg;base64,' . $hugeFakeBase64,
            ],
        ];

        $command = $this->makeTestableMqttCommand();
        $command->invokeHandleVerifyPush($device->device_id, $payload, $payload['info'], $mockMqtt, $mockStorage);

        // Job must be enqueued with the raw payload, without synchronous storage calls
        Queue::assertPushed(ProcessTelemetryPacketJob::class, function ($job) use ($device) {
            return $job->deviceId === $device->device_id
                && isset($job->payload['info']['SanpPic']);
        });

        // Database must not have created the record synchronously in the listener thread
        $this->assertDatabaseMissing('access_logs', [
            'device_id' => $device->device_id,
            'customize_id' => 202,
        ]);
    }

    // =========================================================================
    // SECTION 2: Deduplication Invariants (PushAck Sent, Re-queue Blocked)
    // =========================================================================

    public function test_deduplication_identical_record_id_packets_are_acknowledged_but_not_requeued(): void
    {
        $device = Device::factory()->create([
            'device_id' => 'CAM-DEDUP-01',
            'is_active' => true,
        ]);

        $publishCount = 0;
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(true);
        $mockMqtt->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function () use (&$publishCount) {
                $publishCount++;
            });

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $command = $this->makeTestableMqttCommand();

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 445566,
                'customId' => 303,
                'time' => '2026-10-09 10:00:00',
            ],
        ];

        // First packet: should send PushAck and enqueue job
        $command->invokeHandleVerifyPush($device->device_id, $payload, $payload['info'], $mockMqtt);
        Queue::assertPushed(ProcessTelemetryPacketJob::class, 1);
        $this->assertEquals(1, $publishCount);

        // Second packet within 60s with same RecordID: MUST still send PushAck to clear camera hardware buffer
        // but MUST NOT enqueue a second job!
        $command->invokeHandleVerifyPush($device->device_id, $payload, $payload['info'], $mockMqtt);
        Queue::assertPushed(ProcessTelemetryPacketJob::class, 1); // Still 1!
        $this->assertEquals(2, $publishCount); // PushAck was sent twice!
    }

    public function test_deduplication_stranger_snaps_same_snap_id_acknowledged_but_not_requeued(): void
    {
        $device = Device::factory()->create([
            'device_id' => 'CAM-DEDUP-02',
            'is_active' => true,
        ]);

        $publishCount = 0;
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(true);
        $mockMqtt->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function () use (&$publishCount) {
                $publishCount++;
            });

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $command = $this->makeTestableMqttCommand();

        $payload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'SnapID' => 889900,
                'time' => '2026-10-09 10:05:00',
            ],
        ];

        // 1st transmission
        $command->invokeHandleStrangerSnapPush($device->device_id, $payload, $payload['info'], $mockMqtt);
        Queue::assertPushed(ProcessTelemetryPacketJob::class, 1);
        $this->assertEquals(1, $publishCount);

        // 2nd duplicate transmission
        $command->invokeHandleStrangerSnapPush($device->device_id, $payload, $payload['info'], $mockMqtt);
        Queue::assertPushed(ProcessTelemetryPacketJob::class, 1);
        $this->assertEquals(2, $publishCount);
    }

    // =========================================================================
    // SECTION 3: SEC-13 Inactive and Unenrolled Device Packet Dropping
    // =========================================================================

    public function test_sec13_unenrolled_device_packet_is_dropped_and_recorded_as_inactive(): void
    {
        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $unknownDeviceId = 'ROGUE-DEVICE-UNKNOWN';

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $unknownDeviceId,
                'RecordID' => 112233,
                'customId' => 404,
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $command = $this->makeTestableMqttCommand();
        $command->invokeHandleVerifyPush($unknownDeviceId, $payload, $payload['info'], $mockMqtt);

        // The packet must be dropped (not enqueued)
        Queue::assertNotPushed(ProcessTelemetryPacketJob::class);

        // Per SEC-13, an inactive placeholder record is created
        $this->assertDatabaseHas('devices', [
            'device_id' => $unknownDeviceId,
            'is_active' => false,
        ]);
    }

    public function test_sec13_inactive_device_packet_is_dropped(): void
    {
        $inactiveDevice = Device::factory()->create([
            'device_id' => 'CAM-INACTIVE-01',
            'is_active' => false,
        ]);

        $mockMqtt = $this->createMock(MqttClient::class);
        $mockMqtt->method('isConnected')->willReturn(false);

        Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $inactiveDevice->device_id,
                'RecordID' => 223344,
                'customId' => 505,
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $command = $this->makeTestableMqttCommand();
        $command->invokeHandleVerifyPush($inactiveDevice->device_id, $payload, $payload['info'], $mockMqtt);

        // Must NOT enqueue
        Queue::assertNotPushed(ProcessTelemetryPacketJob::class);

        // Must not create access logs
        $this->assertDatabaseMissing('access_logs', [
            'device_id' => $inactiveDevice->device_id,
            'customize_id' => 505,
        ]);
    }

    // =========================================================================
    // SECTION 4: Tier 2 Queue Invariants & Robust Image Handling
    // =========================================================================

    public function test_process_telemetry_packet_job_queue_assignment(): void
    {
        $job = new ProcessTelemetryPacketJob('CAM-01', 'VerifyPush', []);
        $this->assertEquals('camera-telemetry', $job->queue);
    }

    public function test_telemetry_job_handles_null_images_producing_null_urls(): void
    {
        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 12345,
                'customId' => 601,
                'similarity1' => 99.0,
                'time' => '2026-10-09 09:30:00',
                'SanpPic' => null,
                'ScenePic' => null,
            ],
            'SanpPic' => null,
            'ScenePic' => null,
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        $log = AccessLog::where('device_id', $device->device_id)
            ->where('customize_id', 601)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->snap_pic_url);
        $this->assertNull($log->scene_pic_url);
    }

    public function test_telemetry_job_handles_empty_string_images_producing_null_urls(): void
    {
        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 12346,
                'customId' => 602,
                'similarity1' => 98.5,
                'time' => '2026-10-09 09:31:00',
                'SanpPic' => '',
                'ScenePic' => '',
            ],
            'SanpPic' => '',
            'ScenePic' => '',
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        $log = AccessLog::where('device_id', $device->device_id)
            ->where('customize_id', 602)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->snap_pic_url);
        $this->assertNull($log->scene_pic_url);
    }

    public function test_telemetry_job_handles_corrupted_base64_payload_without_throwing(): void
    {
        $device = Device::factory()->create(['is_active' => true]);

        $corruptedBase64 = 'data:image/jpeg;base64,!!!NOT_A_VALID_BASE64_SEQUENCE@@@%%%';
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 12347,
                'customId' => 603,
                'similarity1' => 97.0,
                'time' => '2026-10-09 09:32:00',
                'SanpPic' => $corruptedBase64,
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        // Must execute cleanly without unhandled exception
        dispatch_sync($job);

        $this->assertDatabaseHas('access_logs', [
            'device_id' => $device->device_id,
            'customize_id' => 603,
        ]);
    }

    public function test_telemetry_job_handles_large_base64_payload_cleanly(): void
    {
        $device = Device::factory()->create(['is_active' => true]);

        // 100KB binary string encoded as base64
        $rawBinary = random_bytes(102400);
        $validBase64 = 'data:image/jpeg;base64,' . base64_encode($rawBinary);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 12348,
                'customId' => 604,
                'similarity1' => 99.4,
                'time' => '2026-10-09 09:33:00',
                'SanpPic' => $validBase64,
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        $log = AccessLog::where('device_id', $device->device_id)
            ->where('customize_id', 604)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->snap_pic_url);
    }

    public function test_stranger_snap_handles_null_images_safely(): void
    {
        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'StrSnapPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'SnapID' => 778899,
                'time' => '2026-10-09 09:35:00',
                'SanpPic' => null,
                'ScenePic' => null,
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'StrSnapPush', $payload);
        dispatch_sync($job);

        $snap = StrangerSnap::where('device_id', $device->device_id)
            ->where('snap_id', 778899)
            ->first();

        $this->assertNotNull($snap);
        $this->assertEquals('', $snap->snap_pic_url);
        $this->assertNull($snap->scene_pic_url);
    }

    // =========================================================================
    // SECTION 5: Attendance Punch Invariant Triggering
    // =========================================================================

    public function test_verify_status_allowed_triggers_process_attendance_punch_job(): void
    {
        Queue::fake([\App\Jobs\ProcessAttendancePunchJob::class]);

        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 5001,
                'customId' => 701,
                'VerifyStatus' => 1, // Allowed
                'time' => '2026-10-09 08:00:00',
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        Queue::assertPushed(ProcessAttendancePunchJob::class, function ($punchJob) {
            return $punchJob->accessLog instanceof AccessLog && $punchJob->accessLog->customize_id === 701;
        });
    }

    public function test_verify_status_rejected_does_not_trigger_process_attendance_punch_job(): void
    {
        Queue::fake([\App\Jobs\ProcessAttendancePunchJob::class]);

        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 5002,
                'customId' => 702,
                'VerifyStatus' => 2, // Rejected
                'time' => '2026-10-09 08:01:00',
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        Queue::assertNotPushed(ProcessAttendancePunchJob::class);
    }

    public function test_verify_status_not_registered_does_not_trigger_process_attendance_punch_job(): void
    {
        Queue::fake([\App\Jobs\ProcessAttendancePunchJob::class]);

        $device = Device::factory()->create(['is_active' => true]);

        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 5003,
                'customId' => 703,
                'VerifyStatus' => 3, // Not Registered
                'time' => '2026-10-09 08:02:00',
            ],
        ];

        $job = new ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        Queue::assertNotPushed(ProcessAttendancePunchJob::class);
    }

    // =========================================================================
    // SECTION 6: Command Correlator Invariants (Failure ACKs, Unknown IDs, Broadcasts)
    // =========================================================================

    public function test_hardware_ack_with_non_zero_error_code_marks_command_failed(): void
    {
        $device = Device::factory()->create();
        $messageId = 'FAIL-CMD-' . uniqid();

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $ackPacket = [
            'operator' => 'RebootDeviceAck',
            'messageId' => $messageId,
            'code' => 500,
            'info' => [
                'Result' => 1,
                'detail' => 'Hardware internal reboot error: peripheral busy',
            ],
        ];

        $service = app(CameraMqttService::class);
        $result = $service->handleCommandAck($ackPacket);

        $this->assertNotNull($result);
        $command->refresh();

        $this->assertEquals('failed', $command->status);
        $this->assertNotNull($command->completed_at);
        $this->assertStringContainsString('Hardware internal reboot error', $command->error_message ?? '');
    }

    public function test_hardware_ack_fallback_error_message_when_no_detail_provided(): void
    {
        $device = Device::factory()->create();
        $messageId = 'FAIL-FALLBACK-' . uniqid();

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'SetSysTime',
            'status' => 'pending',
        ]);

        $ackPacket = [
            'operator' => 'SetSysTimeAck',
            'messageId' => $messageId,
            'code' => 403,
        ];

        $service = app(CameraMqttService::class);
        $service->handleCommandAck($ackPacket);

        $command->refresh();
        $this->assertEquals('failed', $command->status);
        $this->assertEquals('Hardware returned code 403', $command->error_message);
    }

    public function test_hardware_ack_with_unknown_message_id_handled_gracefully(): void
    {
        $unknownAckPacket = [
            'operator' => 'SetSysTimeAck',
            'messageId' => 'UNKNOWN-' . uniqid(),
            'code' => 0,
        ];

        $service = app(CameraMqttService::class);
        $result = $service->handleCommandAck($unknownAckPacket);

        $this->assertNull($result);
    }

    public function test_successful_hardware_ack_broadcasts_device_command_completed_event(): void
    {
        Event::fake([DeviceCommandCompleted::class]);

        $device = Device::factory()->create();
        $messageId = 'OK-CMD-' . uniqid();

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'UpMQTTconfig',
            'status' => 'pending',
        ]);

        $ackPacket = [
            'operator' => 'UpMQTTconfigAck',
            'messageId' => $messageId,
            'code' => 0,
            'info' => ['Result' => 0],
        ];

        $service = app(CameraMqttService::class);
        $service->handleCommandAck($ackPacket);

        $command->refresh();
        $this->assertEquals('completed', $command->status);

        Event::assertDispatched(DeviceCommandCompleted::class, function ($event) use ($command) {
            return $event->command->id === $command->id
                && $event->broadcastOn()[0]->name === 'private-device-commands';
        });
    }
}
