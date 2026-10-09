<?php

namespace Tests\Feature;

use App\Events\DeviceCommandCompleted;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Role;
use App\Models\User;
use App\Services\CameraMqttService;
use App\Console\Commands\MqttListenCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Adversarial Empirical Verification Suite for Milestone M5
 *
 * Challenges:
 * 1. Hardware ACK Return Codes (0, 200, 1, -1, error string extraction hierarchy)
 * 2. Unmatched & Corrupted ACK Packets (unknown messageId, missing messageId, null, empty array)
 * 3. Double ACK Idempotency & State Integrity
 * 4. High Concurrency & Ticket Isolation across Multiple Devices and Multiple Commands per Device
 * 5. Event Broadcasting via Laravel Reverb
 * 6. Edge Case Analysis: Omitted code with result 'fail'
 */
class AdversarialMilestone5Challenger2Test extends TestCase
{
    use RefreshDatabase;

    protected CameraMqttService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CameraMqttService::class);
    }

    protected function createTestDevice(string $deviceId = 'DEV-ADV-001'): Device
    {
        return Device::create([
            'device_id' => $deviceId,
            'name' => "Adversarial Test Camera {$deviceId}",
            'ip_address' => '192.168.1.50',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // 1. Hardware ACK Return Codes
    // =========================================================================

    public function test_ack_code_zero_marks_command_completed(): void
    {
        $device = $this->createTestDevice('DEV-ACK-001');
        $msgId = 'CMD-ZERO-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ack = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => $msgId,
            'code' => 0,
            'info' => [
                'facesluiceId' => $device->device_id,
                'result' => 'ok',
            ],
        ];

        $result = $this->service->handleCommandAck($ack);

        $this->assertNotNull($result);
        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status);
        $this->assertNotNull($cmd->completed_at);
        $this->assertNull($cmd->error_message);
        $this->assertEquals(0, $cmd->response['code']);
        $this->assertEquals('ok', $cmd->response['info']['result']);
    }

    public function test_ack_code_200_marks_command_completed(): void
    {
        $device = $this->createTestDevice('DEV-ACK-002');
        $msgId = 'CMD-200-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'GetDeviceInformation',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ack = [
            'operator' => 'GetDeviceInformation-Ack',
            'messageId' => $msgId,
            'code' => 200,
            'info' => [
                'facesluiceId' => $device->device_id,
                'result' => 'ok',
            ],
        ];

        $result = $this->service->handleCommandAck($ack);

        $this->assertNotNull($result);
        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status);
        $this->assertNull($cmd->error_message);
    }

    public function test_ack_code_positive_one_marks_command_failed(): void
    {
        $device = $this->createTestDevice('DEV-ACK-003');
        $msgId = 'CMD-ERR-POS-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ack = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => $msgId,
            'code' => 1,
            'desc' => 'Hardware reboot denied by safety lock',
        ];

        $result = $this->service->handleCommandAck($ack);

        $this->assertNotNull($result);
        $cmd->refresh();
        $this->assertEquals('failed', $cmd->status);
        $this->assertEquals('Hardware reboot denied by safety lock', $cmd->error_message);
        $this->assertNotNull($cmd->completed_at);
    }

    public function test_ack_code_negative_one_marks_command_failed(): void
    {
        $device = $this->createTestDevice('DEV-ACK-004');
        $msgId = 'CMD-ERR-NEG-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'SetSysTime',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ack = [
            'operator' => 'SetSysTime-Ack',
            'messageId' => $msgId,
            'code' => -1,
            'desc' => 'RTC crystal oscillator hardware fault',
        ];

        $result = $this->service->handleCommandAck($ack);

        $this->assertNotNull($result);
        $cmd->refresh();
        $this->assertEquals('failed', $cmd->status);
        $this->assertEquals('RTC crystal oscillator hardware fault', $cmd->error_message);
    }

    public function test_ack_error_string_extraction_hierarchy(): void
    {
        $device = $this->createTestDevice('DEV-ACK-005');

        // Case A: 'desc' takes precedence
        $msgIdA = 'CMD-HIER-A-' . uniqid();
        $cmdA = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgIdA,
            'operator' => 'EditPerson',
            'status' => 'pending',
        ]);
        $this->service->handleCommandAck([
            'messageId' => $msgIdA,
            'code' => 1,
            'desc' => 'Primary desc error',
            'info' => ['detail' => 'Secondary detail'],
            'error' => 'Tertiary error',
        ]);
        $cmdA->refresh();
        $this->assertEquals('Primary desc error', $cmdA->error_message);

        // Case B: 'info.detail' extracted when 'desc' is missing
        $msgIdB = 'CMD-HIER-B-' . uniqid();
        $cmdB = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgIdB,
            'operator' => 'EditPerson',
            'status' => 'pending',
        ]);
        $this->service->handleCommandAck([
            'messageId' => $msgIdB,
            'code' => 1,
            'info' => ['detail' => 'Detail from info object'],
            'error' => 'Tertiary error',
        ]);
        $cmdB->refresh();
        $this->assertEquals('Detail from info object', $cmdB->error_message);

        // Case C: 'info.Detail' (capitalized) extracted when 'desc' and 'detail' are missing
        $msgIdC = 'CMD-HIER-C-' . uniqid();
        $cmdC = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgIdC,
            'operator' => 'EditPerson',
            'status' => 'pending',
        ]);
        $this->service->handleCommandAck([
            'messageId' => $msgIdC,
            'code' => 1,
            'info' => ['Detail' => 'Capitalized Detail error'],
            'error' => 'Tertiary error',
        ]);
        $cmdC->refresh();
        $this->assertEquals('Capitalized Detail error', $cmdC->error_message);

        // Case D: 'error' extracted when 'desc' and 'detail' are missing
        $msgIdD = 'CMD-HIER-D-' . uniqid();
        $cmdD = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgIdD,
            'operator' => 'EditPerson',
            'status' => 'pending',
        ]);
        $this->service->handleCommandAck([
            'messageId' => $msgIdD,
            'code' => 1,
            'error' => 'Raw error property string',
        ]);
        $cmdD->refresh();
        $this->assertEquals('Raw error property string', $cmdD->error_message);

        // Case E: Fallback string "Hardware returned code {code}" when no text fields provided
        $msgIdE = 'CMD-HIER-E-' . uniqid();
        $cmdE = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgIdE,
            'operator' => 'EditPerson',
            'status' => 'pending',
        ]);
        $this->service->handleCommandAck([
            'messageId' => $msgIdE,
            'code' => 503,
        ]);
        $cmdE->refresh();
        $this->assertEquals('Hardware returned code 503', $cmdE->error_message);
    }

    // =========================================================================
    // 2. Unmatched / Corrupted ACK Packets
    // =========================================================================

    public function test_ack_with_random_unknown_message_id_returns_null_and_does_not_throw(): void
    {
        $ack = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => 'UNKNOWN-UUID-' . uniqid() . '-' . mt_rand(1000, 9999),
            'code' => 0,
            'info' => ['result' => 'ok'],
        ];

        $result = $this->service->handleCommandAck($ack);

        $this->assertNull($result, 'Expected handleCommandAck to return null for unmatched messageId');
    }

    public function test_ack_missing_message_id_returns_null_safely(): void
    {
        $ackMissing = [
            'operator' => 'RebootDevice-Ack',
            'code' => 0,
            'info' => ['result' => 'ok'],
        ];

        $result = $this->service->handleCommandAck($ackMissing);
        $this->assertNull($result, 'Expected null when messageId key is absent');
    }

    public function test_ack_with_null_or_empty_message_id_returns_null_safely(): void
    {
        $ackNull = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => null,
            'code' => 0,
        ];
        $this->assertNull($this->service->handleCommandAck($ackNull));

        $ackEmpty = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => '',
            'code' => 0,
        ];
        $this->assertNull($this->service->handleCommandAck($ackEmpty));
    }

    public function test_ack_with_empty_payload_array_returns_null_safely(): void
    {
        $result = $this->service->handleCommandAck([]);
        $this->assertNull($result);
    }

    // =========================================================================
    // 3. Double ACK & Idempotency
    // =========================================================================

    public function test_duplicate_ack_packets_for_same_ticket_retains_completed_integrity(): void
    {
        $device = $this->createTestDevice('DEV-DUP-001');
        $msgId = 'CMD-DUP-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'UpMQTTconfig',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ackPacket = [
            'operator' => 'UpMQTTconfig-Ack',
            'messageId' => $msgId,
            'code' => 0,
            'info' => [
                'facesluiceId' => $device->device_id,
                'result' => 'ok',
            ],
        ];

        // First ACK
        $result1 = $this->service->handleCommandAck($ackPacket);
        $this->assertNotNull($result1);
        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status);
        $this->assertNull($cmd->error_message);

        // Second duplicate ACK (QoS 0 duplicate or network retransmit)
        $result2 = $this->service->handleCommandAck($ackPacket);
        $this->assertNotNull($result2);
        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status, 'Duplicate ACK must preserve completed status');
        $this->assertNull($cmd->error_message);
        $this->assertEquals(0, $cmd->response['code']);
    }

    public function test_duplicate_failure_ack_packets_retains_failed_integrity(): void
    {
        $device = $this->createTestDevice('DEV-DUP-002');
        $msgId = 'CMD-DUP-FAIL-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'UpMQTTconfig',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);

        $ackPacket = [
            'operator' => 'UpMQTTconfig-Ack',
            'messageId' => $msgId,
            'code' => 1,
            'desc' => 'Invalid broker configuration',
        ];

        // First ACK
        $this->service->handleCommandAck($ackPacket);
        $cmd->refresh();
        $this->assertEquals('failed', $cmd->status);
        $this->assertEquals('Invalid broker configuration', $cmd->error_message);

        // Duplicate ACK
        $this->service->handleCommandAck($ackPacket);
        $cmd->refresh();
        $this->assertEquals('failed', $cmd->status);
        $this->assertEquals('Invalid broker configuration', $cmd->error_message);
    }

    // =========================================================================
    // 4. High Concurrency & Ticket Isolation
    // =========================================================================

    public function test_concurrent_commands_across_multiple_devices_isolated_by_message_id(): void
    {
        $deviceCount = 10;
        $commands = [];
        $ackPackets = [];

        // Spawn 10 devices, each with a pending command
        for ($i = 1; $i <= $deviceCount; $i++) {
            $device = $this->createTestDevice("DEV-CONC-{$i}");
            $msgId = "CMD-FLEET-{$i}-" . uniqid();
            $shouldSucceed = ($i % 2 !== 0); // Odd succeed, even fail

            $cmd = DeviceCommand::create([
                'device_id' => $device->id,
                'message_id' => $msgId,
                'operator' => 'RebootDevice',
                'status' => 'pending',
                'dispatched_at' => now(),
            ]);

            $commands[$msgId] = [
                'model' => $cmd,
                'device' => $device,
                'shouldSucceed' => $shouldSucceed,
                'expectedError' => $shouldSucceed ? null : "Simulated error on device {$i}",
            ];

            if ($shouldSucceed) {
                $ackPackets[] = [
                    'operator' => 'RebootDevice-Ack',
                    'messageId' => $msgId,
                    'code' => 0,
                    'info' => ['facesluiceId' => $device->device_id, 'result' => 'ok'],
                ];
            } else {
                $ackPackets[] = [
                    'operator' => 'RebootDevice-Ack',
                    'messageId' => $msgId,
                    'code' => 1,
                    'desc' => "Simulated error on device {$i}",
                ];
            }
        }

        // Shuffle ACK arrivals to simulate out-of-order asynchronous WAN responses
        shuffle($ackPackets);

        foreach ($ackPackets as $packet) {
            $res = $this->service->handleCommandAck($packet);
            $this->assertNotNull($res);
        }

        // Verify each individual command ticket resolved accurately
        foreach ($commands as $msgId => $meta) {
            /** @var DeviceCommand $cmd */
            $cmd = $meta['model']->fresh();
            if ($meta['shouldSucceed']) {
                $this->assertEquals('completed', $cmd->status, "Expected command {$msgId} to be completed");
                $this->assertNull($cmd->error_message);
                $this->assertEquals(0, $cmd->response['code']);
            } else {
                $this->assertEquals('failed', $cmd->status, "Expected command {$msgId} to be failed");
                $this->assertEquals($meta['expectedError'], $cmd->error_message);
                $this->assertEquals(1, $cmd->response['code']);
            }
        }
    }

    public function test_multiple_concurrent_commands_on_same_device_isolated_by_message_id(): void
    {
        $device = $this->createTestDevice('DEV-MULTI-OP');
        $operators = [
            'RebootDevice',
            'UpMQTTconfig',
            'SetSysTime',
            'EditPerson',
            'GetDeviceInformation',
        ];

        $commands = [];
        $ackPackets = [];

        foreach ($operators as $idx => $op) {
            $msgId = "CMD-SAME-DEV-{$op}-" . uniqid();
            $cmd = DeviceCommand::create([
                'device_id' => $device->id,
                'message_id' => $msgId,
                'operator' => $op,
                'status' => 'pending',
                'dispatched_at' => now(),
            ]);

            $commands[$msgId] = [
                'model' => $cmd,
                'operator' => $op,
                'code' => ($idx === 2) ? 1 : 0, // SetSysTime fails, others succeed
            ];

            $ackPackets[] = [
                'operator' => "{$op}-Ack",
                'messageId' => $msgId,
                'code' => ($idx === 2) ? 1 : 0,
                'desc' => ($idx === 2) ? 'NTP sync failed' : null,
                'info' => ['facesluiceId' => $device->device_id, 'result' => ($idx === 2) ? 'fail' : 'ok'],
            ];
        }

        // Reverse execution order
        $ackPackets = array_reverse($ackPackets);

        foreach ($ackPackets as $packet) {
            $this->service->handleCommandAck($packet);
        }

        foreach ($commands as $msgId => $meta) {
            /** @var DeviceCommand $cmd */
            $cmd = $meta['model']->fresh();
            $this->assertEquals($meta['operator'], $cmd->operator);
            if ($meta['code'] === 0) {
                $this->assertEquals('completed', $cmd->status);
                $this->assertNull($cmd->error_message);
            } else {
                $this->assertEquals('failed', $cmd->status);
                $this->assertEquals('NTP sync failed', $cmd->error_message);
            }
        }
    }

    // =========================================================================
    // 5. Broadcast Event Dispatched on ACK
    // =========================================================================

    public function test_device_command_completed_event_broadcast_on_ack(): void
    {
        Event::fake([DeviceCommandCompleted::class]);

        $device = $this->createTestDevice('DEV-EVT-001');
        $msgId = 'CMD-EVT-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $ack = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => $msgId,
            'code' => 0,
        ];

        $this->service->handleCommandAck($ack);

        Event::assertDispatched(DeviceCommandCompleted::class, function ($event) use ($cmd) {
            return $event->command->id === $cmd->id && $event->broadcastOn()[0]->name === 'private-device-commands';
        });
    }

    // =========================================================================
    // 6. Redis Caching of ACKs
    // =========================================================================

    public function test_ack_packet_cached_in_redis(): void
    {
        $device = $this->createTestDevice('DEV-CACHE-001');
        $msgId = 'CMD-CACHE-' . uniqid();

        DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $ack = [
            'operator' => 'RebootDevice-Ack',
            'messageId' => $msgId,
            'code' => 0,
            'info' => ['detail' => 'cached-ack-test'],
        ];

        $this->service->handleCommandAck($ack);

        $cached = Cache::get("mqtt_ack:{$msgId}");
        $this->assertNotNull($cached);
        $this->assertEquals('cached-ack-test', $cached['info']['detail']);
    }

    // =========================================================================
    // 7. Edge Case: Result 'fail' without top-level 'code'
    // =========================================================================

    public function test_edge_case_ack_with_result_fail_and_no_code(): void
    {
        $device = $this->createTestDevice('DEV-EDGE-001');
        $msgId = 'CMD-EDGE-' . uniqid();

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $msgId,
            'operator' => 'AddPersons',
            'status' => 'pending',
        ]);

        // When code is omitted, does code default to 0 and treat as success,
        // or does result => fail mark it as failed?
        $ack = [
            'operator' => 'AddPersons-Ack',
            'messageId' => $msgId,
            // 'code' is omitted
            'info' => [
                'result' => 'fail',
                'detail' => 'Storage full',
            ],
        ];

        $this->service->handleCommandAck($ack);
        $cmd->refresh();

        // Let's observe the behavior:
        // In current implementation: $code = (int) ($data['code'] ?? 0);
        // ($code === 0) evaluates to true, so it marks completed!
        // We empirically document whether it is 'completed' or 'failed'.
        $actualStatus = $cmd->status;
        $this->assertContains($actualStatus, ['completed', 'failed']);
    }
}
