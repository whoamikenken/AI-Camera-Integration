<?php

namespace App\Services;

use App\Contracts\CameraGatewayInterface;
use App\Events\DeviceCommandCompleted;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;
use Illuminate\Support\Facades\Cache;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class CameraMqttService
{
    protected string $host;
    protected int $port;
    protected ?MqttClient $sharedClient = null;
    protected CameraGatewayInterface $gateway;

    public function __construct(?CameraGatewayInterface $gateway = null)
    {
        $this->host = config('mqtt.host', env('MQTT_HOST', '127.0.0.1'));
        $this->port = (int) config('mqtt.port', env('MQTT_PORT', 1883));
        $this->gateway = $gateway ?? app(CameraGatewayInterface::class);
    }

    public function getGateway(): CameraGatewayInterface
    {
        return $this->gateway;
    }

    public function setGateway(CameraGatewayInterface $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    protected function getSharedClient(): MqttClient
    {
        if (!$this->sharedClient || !$this->sharedClient->isConnected()) {
            $clientId = 'camera_hub_worker_' . getmypid();
            $this->sharedClient = new MqttClient($this->host, $this->port, $clientId);
            $settings = $this->createConnectionSettings(30, 5);
            $this->sharedClient->connect($settings, false);
        }

        return $this->sharedClient;
    }

    /**
     * Publish a downlink MQTT command and wait for matching response from mqtt/face/{DeviceID}/Ack
     */
    public function publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array
    {
        return $this->gateway->publishCommandAndWait($device, $operator, $info, $extraRootFields, $timeoutSeconds);
    }

    /**
     * Publish a downlink MQTT command to a specific device topic: mqtt/face/{DeviceID}
     */
    public function publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array
    {
        return $this->gateway->publishCommand($device, $operator, $info, $extraRootFields);
    }

    /**
     * Build MQTT personnel info structure from a Personnel model.
     */
    public function buildPersonnelInfo(Personnel $person): array
    {
        if ($this->gateway instanceof \App\Gateways\MqttCameraGateway) {
            return $this->gateway->buildPersonnelInfo($person);
        }

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

    /**
     * Add or Update single person via MQTT EditPerson command.
     */
    public function addOrUpdatePerson(Device $device, Personnel $person): array
    {
        return $this->gateway->addOrUpdatePerson($device, $person);
    }

    /**
     * Batch add personnel via MQTT AddPersons command.
     */
    public function addPersons(Device $device, array $personnelItems): array
    {
        return $this->gateway->addPersons($device, $personnelItems);
    }

    /**
     * Delete person(s) via MQTT DelPerson / DeletePersons command.
     */
    public function deletePerson(Device $device, array $customizeIds): array
    {
        return $this->gateway->deletePerson($device, $customizeIds);
    }

    /**
     * Delete all personnel via MQTT DeleteAllPerson command.
     */
    public function deleteAllPersonnel(Device $device): array
    {
        return $this->gateway->deleteAllPersonnel($device);
    }

    /**
     * Remote Reboot device via MQTT RebootDevice command.
     */
    public function rebootDevice(Device $device): array
    {
        return $this->gateway->rebootDevice($device);
    }

    /**
     * Configure MQTT settings on camera via MQTT UpMQTTconfig command.
     */
    public function configureMqtt(Device $device, array $params = []): array
    {
        return $this->gateway->configureMqtt($device, $params);
    }

    /**
     * Get MQTT parameters via MQTT GetMQTTconfig command.
     */
    public function getMqttParam(Device $device): array
    {
        return $this->gateway->getMqttParam($device);
    }

    /**
     * Synchronize device system clock via MQTT SetSysTime command.
     */
    public function setSysTime(Device $device, ?string $time = null): array
    {
        return $this->gateway->setSysTime($device, $time);
    }

    /**
     * Query device hardware information via MQTT GetDeviceInformation command.
     */
    public function getDeviceInformation(Device $device): array
    {
        return $this->gateway->getDeviceInformation($device);
    }

    /**
     * Request on-demand snapshot frame via MQTT GetSceneSnap command.
     */
    public function getSceneSnap(Device $device): array
    {
        return $this->gateway->getSceneSnap($device);
    }

    /**
     * Search single person detail via MQTT SearchPerson command.
     */
    public function searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array
    {
        return $this->gateway->searchPerson($device, $searchId, $searchType, $picture);
    }

    /**
     * Search person list via MQTT SearchPersonList command.
     */
    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array
    {
        return $this->gateway->searchPersonList($device, $beginNo, $count);
    }

    /**
     * Search total person count via MQTT QueryPerson command.
     */
    public function searchPersonNum(Device $device): array
    {
        return $this->gateway->searchPersonNum($device);
    }

    /**
     * Test connection reachability of device over MQTT.
     */
    public function testConnection(Device $device): array
    {
        return $this->gateway->testConnection($device);
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

    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand
    {
        return $this->gateway->dispatchCommandAsync($device, $operator, $params);
    }

    public function handleCommandAck(array $data): ?DeviceCommand
    {
        $messageId = $data['messageId'] ?? null;
        if (!$messageId) {
            return null;
        }

        Cache::put("mqtt_ack:{$messageId}", $data, 30);

        $command = DeviceCommand::where('message_id', $messageId)->first();
        if (!$command) {
            return null;
        }

        $code = (int) ($data['code'] ?? 0);
        $result = strtolower((string) ($data['info']['result'] ?? $data['info']['Result'] ?? ''));
        $isSuccess = ($code === 0 || $code === 200 || $result === 'ok' || $result === 'success');

        if ($isSuccess) {
            $command->markCompleted($data);
        } else {
            $error = $data['desc'] ?? $data['info']['detail'] ?? $data['info']['Detail'] ?? $data['error'] ?? "Hardware returned code {$code}";
            $command->markFailed($data, (string) $error);
        }

        event(new DeviceCommandCompleted($command));

        return $command;
    }
}
