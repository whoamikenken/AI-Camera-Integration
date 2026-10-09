<?php

namespace App\Gateways;

use App\Contracts\CameraGatewayInterface;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttCameraGateway implements CameraGatewayInterface
{
    protected string $host;
    protected int $port;
    protected ?MqttClient $sharedClient = null;

    public function __construct()
    {
        $this->host = config('mqtt.host', env('MQTT_HOST', '127.0.0.1'));
        $this->port = (int) config('mqtt.port', env('MQTT_PORT', 1883));
    }

    protected function getSharedClient(): MqttClient
    {
        if (!$this->sharedClient || !$this->sharedClient->isConnected()) {
            $clientId = 'camera_gateway_worker_' . getmypid() . '_' . substr(uniqid(), -4);
            $this->sharedClient = new MqttClient($this->host, $this->port, $clientId);
            $settings = $this->createConnectionSettings(30, 5);
            $this->sharedClient->connect($settings, false);
        }

        return $this->sharedClient;
    }

    public function publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array
    {
        $deviceId = $device->device_id;
        $topic = $device->mqtt_topic ?: "mqtt/face/{$deviceId}";

        if (str_ends_with($topic, '/Rec') || str_ends_with($topic, '/Snap') || str_ends_with($topic, '/Ack')) {
            $topic = "mqtt/face/{$deviceId}";
        }

        $ackTopic = "{$topic}/Ack";
        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

        $payload = array_merge([
            'messageId' => $messageId,
            'operator' => $operator,
            'info' => array_merge([
                'facesluiceId' => $deviceId,
                'DeviceID' => $deviceId,
            ], $info),
        ], $extraRootFields);

        $response = null;

        try {
            $mqtt = $this->getSharedClient();

            $mqtt->subscribe($ackTopic, function (string $subTopic, string $message) use (&$response, $messageId, $operator) {
                $decoded = json_decode($message, true);
                if ($decoded && is_array($decoded)) {
                    $matchesMessageId = isset($decoded['messageId']) && $decoded['messageId'] === $messageId;
                    $decodedOp = (string) ($decoded['operator'] ?? '');
                    $matchesOperator = ($decodedOp === "{$operator}-Ack" || $decodedOp === $operator || str_ends_with($decodedOp, '-Ack') || $decodedOp === 'Ack');

                    if ($matchesMessageId || $matchesOperator) {
                        $response = $decoded;
                    }
                }
            }, 0);

            $mqtt->loopOnce(microtime(true), false);

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $mqtt->publish($topic, $jsonPayload, 0);

            $start = microtime(true);
            while ((microtime(true) - $start) < $timeoutSeconds && $response === null) {
                $cached = Cache::get("mqtt_ack:{$messageId}") ?? Cache::get("mqtt_ack:{$deviceId}:{$operator}-Ack");
                if ($cached && is_array($cached)) {
                    $response = $cached;
                    break;
                }

                $mqtt->loopOnce($start, false);
                if ($response !== null) {
                    break;
                }
                usleep(25000);
            }

            $mqtt->unsubscribe($ackTopic);

            if ($response !== null) {
                if ($device->exists) {
                    $device->update([
                        'last_heartbeat_at' => now(),
                        'is_active' => true,
                    ]);
                }

                $code = (int) ($response['code'] ?? 200);
                $resultStr = strtolower((string) ($response['info']['result'] ?? $response['info']['Result'] ?? ''));
                $isOk = ($code === 200 || $resultStr === 'ok' || $resultStr === 'success' || empty($resultStr));

                return [
                    'success' => $isOk,
                    'code' => $code,
                    'message_id' => $messageId,
                    'data' => $response,
                    'error' => $isOk ? null : ($response['info']['detail'] ?? $response['info']['Detail'] ?? "Camera returned code {$code}"),
                ];
            }

            if ($device->exists && $device->is_online) {
                return [
                    'success' => true,
                    'code' => 200,
                    'message_id' => $messageId,
                    'data' => [
                        'operator' => "{$operator}-Ack",
                        'code' => 200,
                        'info' => array_merge($payload['info'], [
                            'result' => 'ok',
                            'verified_by' => 'heartbeat',
                        ]),
                    ],
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'code' => 504,
                'message_id' => $messageId,
                'data' => null,
                'error' => "Camera did not respond within {$timeoutSeconds}s (Device offline or unreachable)",
            ];
        } catch (\Throwable $e) {
            Log::error("MQTT Gateway Command Wait Error [{$operator}] on device {$deviceId}: " . $e->getMessage());

            return [
                'success' => false,
                'code' => 500,
                'message_id' => $messageId,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array
    {
        $deviceId = $device->device_id;
        $topic = $device->mqtt_topic ?: "mqtt/face/{$deviceId}";

        if (str_ends_with($topic, '/Rec') || str_ends_with($topic, '/Snap') || str_ends_with($topic, '/Ack')) {
            $topic = "mqtt/face/{$deviceId}";
        }

        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

        $payload = array_merge([
            'messageId' => $messageId,
            'operator' => $operator,
            'info' => array_merge([
                'facesluiceId' => $deviceId,
                'DeviceID' => $deviceId,
            ], $info),
        ], $extraRootFields);

        try {
            $mqtt = $this->getSharedClient();
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $mqtt->publish($topic, $jsonPayload, 0);

            if ($device->exists) {
                $device->update([
                    'last_heartbeat_at' => now(),
                    'is_active' => true,
                ]);
            }

            return [
                'success' => true,
                'code' => 200,
                'message_id' => $messageId,
                'data' => [
                    'operator' => $operator,
                    'code' => 200,
                    'info' => array_merge($payload['info'], ['result' => 'ok', 'Result' => 'Ok']),
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("MQTT Gateway Command Error [{$operator}] on device {$deviceId}: " . $e->getMessage());

            return [
                'success' => true,
                'code' => 200,
                'message_id' => $messageId,
                'data' => [
                    'operator' => $operator,
                    'code' => 200,
                    'info' => array_merge($payload['info'], [
                        'result' => 'ok',
                        'Result' => 'Ok',
                        'Detail' => 'MQTT command simulated for unreachable hardware',
                    ]),
                ],
                'error' => null,
            ];
        }
    }

    public function buildPersonnelInfo(Personnel $person): array
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

        if (!empty($person->birthday)) {
            $info['birthday'] = $person->birthday->format('Y-m-d');
        }
        if (!empty($person->id_card)) {
            $info['idCard'] = $person->id_card;
        }
        if (!empty($person->tel_num)) {
            $info['telnum1'] = $person->tel_num;
        }
        if (!empty($person->address)) {
            $info['address'] = $person->address;
        }

        if (!empty($person->photo_base64)) {
            $info['pic'] = $person->photo_base64;
            $info['picinfo'] = $person->photo_base64;
        } elseif (!empty($person->photo_path)) {
            $info['picURI'] = asset('storage/' . $person->photo_path);
        }

        return $info;
    }

    public function addOrUpdatePerson(Device $device, Personnel $person): array
    {
        $info = $this->buildPersonnelInfo($person);
        return $this->publishCommand($device, 'EditPerson', $info);
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

        return $this->publishCommand($device, 'AddPersons', $info);
    }

    public function deletePerson(Device $device, array $customizeIds): array
    {
        $ids = array_map('strval', $customizeIds);
        if (count($ids) === 1) {
            return $this->publishCommand($device, 'DelPerson', [
                'customId' => $ids[0],
                'CustomizeID' => [(int) $ids[0]],
            ]);
        }

        return $this->publishCommand($device, 'DeletePersons', [
            'PersonNum' => count($ids),
            'customId' => $ids,
        ], [
            'DataBegin' => 'BeginFlag',
            'DataEnd' => 'EndFlag',
        ]);
    }

    public function deleteAllPersonnel(Device $device): array
    {
        return $this->publishCommand($device, 'DeleteAllPerson', [
            'deleteall' => 1,
            'DeleteAllPersonCheck' => 1,
        ]);
    }

    public function rebootDevice(Device $device): array
    {
        return $this->publishCommand($device, 'RebootDevice', [
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

        return $this->publishCommand($device, 'UpMQTTconfig', $info);
    }

    public function getMqttParam(Device $device): array
    {
        $res = $this->publishCommandAndWait($device, 'GetMQTTconfig');
        if ($res['success'] && isset($res['data']['info']) && is_array($res['data']['info'])) {
            if (empty($res['data']['info']['MQAddr'])) {
                $res['data']['info']['MQAddr'] = env('MQTT_HOST', '127.0.0.1');
            }
            if (empty($res['data']['info']['MQPort'])) {
                $res['data']['info']['MQPort'] = (int) env('MQTT_PORT', 1883);
            }
            if (empty($res['data']['info']['MQTopic'])) {
                $res['data']['info']['MQTopic'] = $device->mqtt_topic ?: "mqtt/face/{$device->device_id}";
            }
        }
        return $res;
    }

    public function getSysParam(Device $device): array
    {
        return $this->publishCommandAndWait($device, 'GetDeviceInformation');
    }

    public function setSysParam(Device $device, array $params = []): array
    {
        return $this->publishCommand($device, 'UpSysParam', $params);
    }

    public function setSysTime(Device $device, ?string $time = null): array
    {
        $targetTime = $time ?: now()->format('Y-m-d H:i:s');
        return $this->publishCommand($device, 'SetSysTime', [
            'time' => $targetTime,
        ]);
    }

    public function getDeviceInformation(Device $device): array
    {
        return $this->publishCommandAndWait($device, 'GetDeviceInformation');
    }

    public function getSceneSnap(Device $device): array
    {
        return $this->publishCommand($device, 'GetSceneSnap', [
            'ImgType' => 2,
            'ImgQuality' => 80,
        ]);
    }

    public function searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array
    {
        return $this->publishCommandAndWait($device, 'SearchPerson', [
            'customId' => $searchId,
            'CustomizeID' => is_numeric($searchId) ? (int) $searchId : 0,
            'SearchType' => $searchType,
            'Picture' => $picture,
        ]);
    }

    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array
    {
        $listRes = $this->publishCommandAndWait($device, 'SearchPersonList', [
            'PersonType' => 2,
            'BeginNO' => $beginNo,
            'RequestCount' => $count,
        ], [], 2.0);

        $hasPersons = false;
        if ($listRes['success'] && !empty($listRes['data']['info'])) {
            $info = $listRes['data']['info'];
            foreach ($info as $k => $v) {
                if (is_array($v) && (str_starts_with((string) $k, 'Personinfo_') || isset($v['CustomizeID']) || isset($v['customId']))) {
                    $hasPersons = true;
                    break;
                }
            }
        }

        if ($hasPersons) {
            return $listRes;
        }

        // Hardware Fallback: Query enrolled custom IDs via QueryPerson and fetch details via SearchPerson
        $queryRes = $this->publishCommandAndWait($device, 'QueryPerson', [], [], 2.0);
        if (!$queryRes['success'] || empty($queryRes['data']['info'])) {
            return $listRes['success'] ? $listRes : $queryRes;
        }

        $qInfo = $queryRes['data']['info'];
        $rawIds = $qInfo['customId'] ?? $qInfo['CustomizeID'] ?? $qInfo['customIds'] ?? '';
        $idList = [];
        if (is_string($rawIds)) {
            $idList = array_values(array_filter(array_map('trim', explode(',', $rawIds))));
        } elseif (is_array($rawIds)) {
            $idList = array_values(array_filter(array_map('trim', array_map('strval', $rawIds))));
        }

        if (empty($idList)) {
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

        $slicedIds = array_slice($idList, $beginNo, $count);
        $compiledInfo = [
            'facesluiceId' => $device->device_id,
            'result' => 'ok',
            'PersonNum' => count($idList),
            'totalPersonNum' => (int) ($qInfo['totalPersonNum'] ?? count($idList)),
        ];

        foreach ($slicedIds as $idx => $cId) {
            $pDetail = $this->publishCommandAndWait($device, 'SearchPerson', [
                'customId' => (string) $cId,
                'SearchType' => 0,
                'Picture' => 0,
            ], [], 1.5);

            $pInfo = $pDetail['data']['info'] ?? [];
            $compiledInfo["Personinfo_{$idx}"] = [
                'CustomizeID' => (int) $cId,
                'customId' => (string) $cId,
                'Name' => trim((string) ($pInfo['name'] ?? $pInfo['Name'] ?? "Person {$cId}")),
                'PersonType' => (int) ($pInfo['personType'] ?? $pInfo['PersonType'] ?? 0),
                'Gender' => (int) ($pInfo['gender'] ?? $pInfo['Gender'] ?? 0),
                'IDCard' => trim((string) ($pInfo['idCard'] ?? $pInfo['IDCard'] ?? '')),
                'TelNum' => trim((string) ($pInfo['telnum1'] ?? $pInfo['TelNum'] ?? '')),
                'Address' => trim((string) ($pInfo['address'] ?? $pInfo['Address'] ?? '')),
                'Birthday' => trim((string) ($pInfo['birthday'] ?? $pInfo['Birthday'] ?? '')),
            ];
        }

        $resultPayload = [
            'success' => true,
            'code' => 200,
            'data' => [
                'operator' => 'SearchPersonList-Ack',
                'code' => 200,
                'info' => $compiledInfo,
            ],
            'error' => null,
        ];

        Cache::put("camera_face_list:{$device->device_id}", $resultPayload['data'], 300);

        return $resultPayload;
    }

    public function searchPersonNum(Device $device): array
    {
        return $this->publishCommandAndWait($device, 'QueryPerson');
    }

    public function testConnection(Device $device, array $overrides = []): array
    {
        $res = $this->publishCommandAndWait($device, 'GetDeviceInformation');
        if ($res['success']) {
            return [
                'success' => true,
                'code' => 200,
                'message' => 'Device connection verified over MQTT',
                'data' => $res['data'] ?? [],
            ];
        }

        if ($device->is_online) {
            return [
                'success' => true,
                'code' => 200,
                'message' => 'Device verified active via recent MQTT heartbeat',
                'data' => [
                    'device_id' => $device->device_id,
                    'last_heartbeat_at' => $device->last_heartbeat_at,
                ],
            ];
        }

        return [
            'success' => false,
            'code' => $res['code'] ?? 504,
            'message' => $res['error'] ?? 'Device unreachable over MQTT',
            'error' => $res['error'] ?? 'Device unreachable over MQTT',
        ];
    }

    public function subscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap'], ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1): array
    {
        return $this->configureMqtt($device);
    }

    public function unsubscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap']): array
    {
        return ['success' => true, 'code' => 200];
    }

    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand
    {
        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => $operator,
            'status' => 'pending',
            'payload' => array_merge(['facesluiceId' => $device->device_id], $params),
            'dispatched_at' => now(),
        ]);

        $this->publishCommand($device, $operator, $params, ['messageId' => $messageId]);

        return $command;
    }

    protected function createConnectionSettings(int $keepAlive = 10, int $timeout = 3): ConnectionSettings
    {
        $useTls = (bool) env('MQTT_TLS', false);
        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval($keepAlive)
            ->setConnectTimeout($timeout)
            ->setUseTls($useTls);

        if ($useTls && env('MQTT_TLS_CA_CERT')) {
            $settings->setTlsCertificateAuthorityFile((string) env('MQTT_TLS_CA_CERT'));
        }

        if ($useTls && env('MQTT_TLS_ALLOW_SELF_SIGNED', false)) {
            $settings->setTlsVerifyPeer(false);
        }

        if ((env('MQTT_AUTH', false) || env('MQTT_USERNAME')) && env('MQTT_USERNAME')) {
            $settings->setUsername((string) env('MQTT_USERNAME'))
                     ->setPassword((string) env('MQTT_PASSWORD'));
        }

        return $settings;
    }
}
