<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Personnel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CameraHttpService
{
    protected int $timeout = 10;

    /**
     * Send an action POST request to the camera device.
     */
    protected function postAction(Device $device, string $operator, array $info, array $extraRootFields = []): array
    {
        $url = "http://{$device->ip_address}:{$device->port}/action/{$operator}";
        $payload = array_merge([
            'operator' => $operator,
            'info' => array_merge([
                'DeviceID' => $device->device_id,
            ], $info),
        ], $extraRootFields);

        try {
            /** @var Response $response */
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($device->username ?? 'admin', $device->password ?? 'admin')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            $data = $response->json() ?? [];
            $code = $data['code'] ?? $response->status();
            $result = strtolower($data['info']['Result'] ?? ($code === 200 ? 'ok' : 'fail'));

            return [
                'success' => $response->successful() && ($code == 200 || $result === 'ok'),
                'code' => $code,
                'data' => $data,
                'error' => $data['info']['Detail'] ?? ($response->successful() ? null : 'HTTP ' . $response->status()),
            ];
        } catch (\Throwable $e) {
            Log::error("Camera HTTP Error [{$operator}] on {$device->device_id} ({$device->ip_address}): " . $e->getMessage());

            return [
                'success' => false,
                'code' => 500,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add or update a person in camera face library (/action/EditPersonNew).
     */
    public function addOrUpdatePerson(Device $device, Personnel $person): array
    {
        $info = $this->buildPersonnelInfo($person);
        $extra = [];

        // picinfo and picURI belong at the root level of the payload (sibling to info)
        if (!empty($person->photo_base64)) {
            $extra['picinfo'] = $person->photo_base64;
        } elseif (!empty($person->photo_path)) {
            $extra['picURI'] = asset('storage/' . $person->photo_path);
        }

        return $this->postAction($device, 'EditPersonNew', $info, $extra);
    }

    /**
     * Formulate the info payload array for a personnel record.
     */
    public function buildPersonnelInfo(Personnel $person): array
    {
        $info = [
            'IdType' => 0,
            'CustomizeID' => (int) $person->customize_id,
            'PersonType' => (int) $person->person_type,
            'Name' => $person->name,
            'Gender' => (int) $person->gender,
            'tempValid' => (int) $person->temp_valid,
            'effectNumber' => (int) ($person->effect_number ?? 1),
        ];

        if ($person->id_card) {
            $info['IdCard'] = $person->id_card;
        }
        if ($person->tel_num) {
            $info['Telnum'] = $person->tel_num;
        }
        if ($person->address) {
            $info['Address'] = $person->address;
        }
        if ($person->birthday) {
            $info['Birthday'] = $person->birthday->format('Y-m-d');
        }
        if ($person->valid_begin) {
            $info['validBegin'] = $person->valid_begin->format('Y-m-d H:i:s');
        }
        if ($person->valid_end) {
            $info['validEnd'] = $person->valid_end->format('Y-m-d H:i:s');
        }

        return $info;
    }

    /**
     * Delete one or more personnel from device (/action/DeletePerson).
     */
    public function deletePerson(Device $device, array $customizeIds): array
    {
        $customizeIds = array_values(array_map('intval', $customizeIds));

        return $this->postAction($device, 'DeletePerson', [
            'TotalNum' => count($customizeIds),
            'IdType' => 0,
            'CustomizeID' => $customizeIds,
        ]);
    }

    /**
     * Wipe all personnel lists from device (/action/DeleteAllPerson).
     * Note: Triggers camera auto-reboot.
     */
    public function deleteAllPersonnel(Device $device): array
    {
        return $this->postAction($device, 'DeleteAllPerson', [
            'DeleteAllPersonCheck' => 1,
        ]);
    }

    /**
     * Query registered face list from camera (/action/SearchPersonList).
     */
    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array
    {
        return $this->postAction($device, 'SearchPersonList', [
            'PersonType' => 2, // 2: All (Whitelist & Blacklist)
            'BeginNO' => $beginNo,
            'RequestCount' => min($count, 100),
            'Picture' => 0,
            'Name' => '',
        ]);
    }

    /**
     * Configure MQTT parameters on the camera (/action/SetMQTTParam).
     */
    public function configureMqtt(Device $device, array $mqttParams = []): array
    {
        $brokerHost = $mqttParams['MQAddr'] ?? env('MQTT_HOST', '192.168.1.50');
        $brokerPort = (int) ($mqttParams['MQPort'] ?? env('MQTT_PORT', 1883));
        $topic = $mqttParams['MQTopic'] ?? ($device->mqtt_topic ?: "mqtt/face/{$device->device_id}");

        $info = [
            'MQEnable' => isset($mqttParams['MQEnable']) ? (int) $mqttParams['MQEnable'] : 1,
            'MQAddr' => $brokerHost,
            'MQPort' => $brokerPort,
            'MQTopic' => $topic,
            'MQCloudID' => (string) ($mqttParams['MQCloudID'] ?? $device->device_id),
            'StrangerUploadType' => isset($mqttParams['StrangerUploadType']) ? (int) $mqttParams['StrangerUploadType'] : 0, // 0: Upload with image
            'RecordUploadType' => isset($mqttParams['RecordUploadType']) ? (int) $mqttParams['RecordUploadType'] : 1,     // 1: Upload with image
            'KeepAliveInterval' => isset($mqttParams['KeepAliveInterval']) ? (int) $mqttParams['KeepAliveInterval'] : 30,
            'BasicTopic' => $mqttParams['BasicTopic'] ?? 'mqtt/face/basic',
            'HeartbeatTopic' => $mqttParams['HeartbeatTopic'] ?? 'mqtt/face/heartbeat',
            'ResumefromBreakpoint' => isset($mqttParams['ResumefromBreakpoint']) ? (int) $mqttParams['ResumefromBreakpoint'] : 1,
        ];

        if (!empty($mqttParams['MQUser'])) {
            $info['MQUser'] = $mqttParams['MQUser'];
            $info['MQPwd'] = $mqttParams['MQPwd'] ?? '';
        }

        return $this->postAction($device, 'SetMQTTParam', $info);
    }

    /**
     * Query current MQTT configuration from camera (/action/GetMQTTParam).
     */
    public function getMqttParam(Device $device): array
    {
        return $this->postAction($device, 'GetMQTTParam', []);
    }

    /**
     * Set System Parameters on camera (/action/SetSysParam).
     */
    public function setSysParam(Device $device, array $params): array
    {
        return $this->postAction($device, 'SetSysParam', $params);
    }

    /**
     * Set System Time on camera (/action/SetSysTime).
     */
    public function setSysTime(Device $device, ?string $time = null): array
    {
        $timeStr = $time ?: now()->setTimezone(config('app.timezone', 'Asia/Manila'))->format('Y-m-d H:i:s');
        return $this->postAction($device, 'SetSysTime', [
            'Time' => $timeStr,
        ]);
    }

    /**
     * Manual push historical recognition records from camera storage (/action/ManualPushRecords).
     */
    public function manualPushRecords(Device $device, string $timeS, string $timeE): array
    {
        return $this->postAction($device, 'ManualPushRecords', [
            'TimeS' => $timeS,
            'TimeE' => $timeE,
        ]);
    }

    /**
     * Manual push stranger snapshot records from camera storage (/action/ManualPushSnaps).
     */
    public function manualPushSnaps(Device $device, string $timeS, string $timeE): array
    {
        return $this->postAction($device, 'ManualPushSnaps', [
            'TimeS' => $timeS,
            'TimeE' => $timeE,
        ]);
    }

    /**
     * Restore camera to factory defaults (/action/SetFactoryDefault).
     */
    public function setFactoryDefault(Device $device, int $netPar = 0, int $person = 1): array
    {
        return $this->postAction($device, 'SetFactoryDefault', [
            'DefaltNetPar' => $netPar,
            'DefaltPerson' => $person,
        ]);
    }

    /**
     * Trigger remote camera reboot (/action/RebootDevice).
     */
    public function rebootDevice(Device $device): array
    {
        return $this->postAction($device, 'RebootDevice', [
            'IsRebootDevice' => 1,
        ]);
    }

    /**
     * Test connection & retrieve camera system info (/action/GetSysParam).
     */
    public function testConnection(Device $device): array
    {
        return $this->postAction($device, 'GetSysParam', []);
    }
}
