<?php

namespace App\Gateways;

use App\Contracts\CameraGatewayInterface;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

class FakeCameraGateway implements CameraGatewayInterface
{
    /**
     * Pre-configured responses keyed by operator.
     *
     * @var array<string, mixed>
     */
    protected array $responses = [];

    /**
     * History of all dispatched commands.
     *
     * @var Collection<int, array>
     */
    protected Collection $dispatched;

    /**
     * Whether to simulate device timeouts.
     */
    protected bool $simulateTimeout = false;

    /**
     * Simulated error payload if set.
     */
    protected ?array $simulatedError = null;

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
        $this->dispatched = collect();
    }

    /**
     * Static helper to instantiate and bind Fake into container.
     */
    public static function fake(array $responses = []): self
    {
        $fake = new self($responses);
        app()->instance(CameraGatewayInterface::class, $fake);

        return $fake;
    }

    /**
     * Configure fake canned responses.
     */
    public function setResponses(array $responses): self
    {
        $this->responses = array_merge($this->responses, $responses);

        return $this;
    }

    /**
     * Simulate a gateway timeout (HTTP 504).
     */
    public function simulateTimeout(bool $timeout = true): self
    {
        $this->simulateTimeout = $timeout;

        return $this;
    }

    /**
     * Simulate a gateway error.
     */
    public function simulateError(int $code, string $message): self
    {
        $this->simulatedError = [
            'success' => false,
            'code' => $code,
            'error' => $message,
            'data' => null,
        ];

        return $this;
    }

    /**
     * Record a command dispatch.
     */
    protected function recordDispatch(string $method, Device $device, string $operator, array $info = [], array $extraRootFields = []): array
    {
        $record = [
            'method' => $method,
            'device' => $device,
            'device_id' => $device->device_id,
            'operator' => $operator,
            'info' => $info,
            'extraRootFields' => $extraRootFields,
            'timestamp' => now(),
        ];

        $this->dispatched->push($record);

        if ($this->simulateTimeout) {
            return [
                'success' => false,
                'code' => 504,
                'message_id' => 'CMD-' . strtoupper(substr(uniqid(), -8)),
                'data' => null,
                'error' => 'Simulated camera timeout: device unreachable',
            ];
        }

        if ($this->simulatedError !== null) {
            return array_merge([
                'message_id' => 'CMD-' . strtoupper(substr(uniqid(), -8)),
            ], $this->simulatedError);
        }

        if (array_key_exists($operator, $this->responses)) {
            $configured = $this->responses[$operator];
            if (is_callable($configured)) {
                return $configured($device, $info, $extraRootFields);
            }
            if (is_array($configured)) {
                return $configured;
            }
        }

        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

        return [
            'success' => true,
            'code' => 200,
            'message_id' => $messageId,
            'data' => [
                'messageId' => $messageId,
                'operator' => "{$operator}-Ack",
                'code' => 200,
                'info' => array_merge($info, [
                    'result' => 'ok',
                    'Result' => 'Ok',
                    'facesluiceId' => $device->device_id,
                    'DeviceID' => $device->device_id,
                ]),
            ],
            'error' => null,
        ];
    }

    public function publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array
    {
        return $this->recordDispatch('publishCommand', $device, $operator, $info, $extraRootFields);
    }

    public function publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array
    {
        return $this->recordDispatch('publishCommandAndWait', $device, $operator, $info, $extraRootFields);
    }

    public function testConnection(Device $device, array $overrides = []): array
    {
        $this->recordDispatch('testConnection', $device, 'GetDeviceInformation');

        if (array_key_exists('testConnection', $this->responses)) {
            $resp = $this->responses['testConnection'];
            return is_callable($resp) ? $resp($device) : $resp;
        }

        return [
            'success' => true,
            'code' => 200,
            'message' => 'Device connection verified over MQTT',
            'data' => [
                'device_id' => $device->device_id,
                'is_online' => true,
                'protocol' => 'MQTT',
            ],
            'error' => null,
        ];
    }

    public function rebootDevice(Device $device): array
    {
        return $this->recordDispatch('rebootDevice', $device, 'RebootDevice', [
            'IsRebootDevice' => 1,
        ]);
    }

    public function configureMqtt(Device $device, array $params = []): array
    {
        $info = array_merge([
            'MQEnable' => 1,
            'MQAddr' => env('MQTT_HOST', 'mqtt.8gategames.com'),
            'MQPort' => 1883,
            'MQTopic' => $device->mqtt_topic ?: "mqtt/face/{$device->device_id}",
            'RecordUploadType' => 1,
            'StrangerUploadType' => 0,
            'KeepAliveInterval' => 30,
            'OnlineTopic' => 'mqtt/face/basic',
            'HeartbeatTopic' => 'mqtt/face/heartbeat',
            'ResumefromBreakpoint' => 1,
        ], $params);

        return $this->recordDispatch('configureMqtt', $device, 'UpMQTTconfig', $info);
    }

    public function getMqttParam(Device $device): array
    {
        return $this->recordDispatch('getMqttParam', $device, 'GetMQTTconfig');
    }

    public function getSysParam(Device $device): array
    {
        return $this->recordDispatch('getSysParam', $device, 'GetDeviceInformation');
    }

    public function setSysParam(Device $device, array $params = []): array
    {
        return $this->recordDispatch('setSysParam', $device, 'UpSysParam', $params);
    }

    public function setSysTime(Device $device, ?string $time = null): array
    {
        return $this->recordDispatch('setSysTime', $device, 'SetSysTime', [
            'time' => $time ?: now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function getDeviceInformation(Device $device): array
    {
        return $this->recordDispatch('getDeviceInformation', $device, 'GetDeviceInformation');
    }

    public function getSceneSnap(Device $device): array
    {
        return $this->recordDispatch('getSceneSnap', $device, 'GetSceneSnap', [
            'ImgType' => 2,
            'ImgQuality' => 80,
        ]);
    }

    public function searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array
    {
        return $this->recordDispatch('searchPerson', $device, 'SearchPerson', [
            'customId' => $searchId,
            'CustomizeID' => is_numeric($searchId) ? (int) $searchId : 0,
            'SearchType' => $searchType,
            'Picture' => $picture,
        ]);
    }

    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array
    {
        $this->recordDispatch('searchPersonList', $device, 'SearchPersonList', [
            'PersonType' => 2,
            'BeginNO' => $beginNo,
            'RequestCount' => $count,
        ]);

        if (array_key_exists('SearchPersonList', $this->responses)) {
            $resp = $this->responses['SearchPersonList'];
            return is_callable($resp) ? $resp($device) : $resp;
        }

        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'operator' => 'SearchPersonList-Ack',
                'code' => 200,
                'info' => [
                    'facesluiceId' => $device->device_id,
                    'result' => 'ok',
                    'PersonNum' => 0,
                ],
            ],
            'error' => null,
        ];
    }

    public function searchPersonNum(Device $device): array
    {
        return $this->recordDispatch('searchPersonNum', $device, 'QueryPerson');
    }

    public function addOrUpdatePerson(Device $device, Personnel $person): array
    {
        $info = [
            'customId' => (string) $person->customize_id,
            'CustomizeID' => (int) $person->customize_id,
            'name' => $person->name,
            'Name' => $person->name,
            'gender' => (int) $person->gender,
            'Gender' => (int) $person->gender,
            'personType' => (int) $person->person_type,
            'PersonType' => (int) $person->person_type,
            'tempValid' => (int) ($person->temp_valid ?? 0),
            'validBegin' => $person->valid_begin ? $person->valid_begin->format('Y-m-d H:i:s') : '2024-01-01 00:00:00',
            'validEnd' => $person->valid_end ? $person->valid_end->format('Y-m-d H:i:s') : '2038-12-31 23:59:59',
            'effectNumber' => (int) ($person->effect_number ?? 10000),
        ];

        return $this->recordDispatch('addOrUpdatePerson', $device, 'EditPerson', $info);
    }

    public function addPersons(Device $device, array $personnelItems): array
    {
        $info = [
            'Total' => count($personnelItems),
            'PersonNum' => count($personnelItems),
        ];

        foreach (array_values($personnelItems) as $idx => $item) {
            $info["Personinfo_{$idx}"] = $item;
        }

        return $this->recordDispatch('publishCommand', $device, 'AddPersons', $info);
    }

    public function deletePerson(Device $device, array $customizeIds): array
    {
        $ids = array_map('strval', $customizeIds);
        if (count($ids) === 1) {
            return $this->recordDispatch('deletePerson', $device, 'DelPerson', [
                'customId' => $ids[0],
                'CustomizeID' => [(int) $ids[0]],
            ]);
        }

        return $this->recordDispatch('deletePerson', $device, 'DeletePersons', [
            'PersonNum' => count($ids),
            'customId' => $ids,
        ]);
    }

    public function deleteAllPersonnel(Device $device): array
    {
        return $this->recordDispatch('deleteAllPersonnel', $device, 'DeleteAllPerson', [
            'deleteall' => 1,
            'DeleteAllPersonCheck' => 1,
        ]);
    }

    public function subscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap'], ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1): array
    {
        return $this->recordDispatch('subscribe', $device, 'UpMQTTconfig');
    }

    public function unsubscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap']): array
    {
        $this->recordDispatch('unsubscribe', $device, 'Unsubscribe');

        return ['success' => true, 'code' => 200];
    }

    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand
    {
        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));
        $this->recordDispatch('dispatchCommandAsync', $device, $operator, $params);

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => $operator,
            'status' => 'pending',
            'payload' => array_merge(['facesluiceId' => $device->device_id], $params),
            'dispatched_at' => now(),
        ]);

        return $command;
    }

    // =========================================================================
    // FLUENT ASSERTIONS
    // =========================================================================

    /**
     * Get all dispatched calls matching criteria.
     */
    public function dispatched(?string $operator = null, ?callable $callback = null): Collection
    {
        return $this->dispatched->filter(function (array $dispatch) use ($operator, $callback) {
            if ($operator !== null && $dispatch['operator'] !== $operator) {
                return false;
            }

            if ($callback !== null) {
                return $callback($dispatch['device'], $dispatch['info'], $dispatch);
            }

            return true;
        })->values();
    }

    /**
     * Check if a command was dispatched.
     */
    public function hasDispatched(string $operator, ?callable $callback = null): bool
    {
        return $this->dispatched($operator, $callback)->isNotEmpty();
    }

    /**
     * Assert that a command was dispatched.
     */
    public function assertDispatched(string $operator, ?callable $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->hasDispatched($operator, $callback),
            "Failed asserting that command [{$operator}] was dispatched."
        );
    }

    /**
     * Assert that a command was dispatched a specific number of times.
     */
    public function assertDispatchedTimes(string $operator, int $times = 1): void
    {
        $count = $this->dispatched($operator)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "Failed asserting that command [{$operator}] was dispatched {$times} times. Dispatched {$count} times."
        );
    }

    /**
     * Assert that a command was not dispatched.
     */
    public function assertNotDispatched(string $operator, ?callable $callback = null): void
    {
        PHPUnit::assertFalse(
            $this->hasDispatched($operator, $callback),
            "Failed asserting that command [{$operator}] was NOT dispatched."
        );
    }

    /**
     * Assert that no commands were dispatched.
     */
    public function assertNothingDispatched(): void
    {
        $count = $this->dispatched->count();

        PHPUnit::assertSame(
            0,
            $count,
            "Failed asserting that nothing was dispatched. {$count} commands were dispatched."
        );
    }

    /**
     * Reset dispatched history.
     */
    public function reset(): self
    {
        $this->dispatched = collect();
        $this->responses = [];
        $this->simulateTimeout = false;
        $this->simulatedError = null;

        return $this;
    }
}
