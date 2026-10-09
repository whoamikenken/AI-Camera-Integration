<?php

namespace App\Console\Commands;

use App\Events\AccessLogReceived;
use App\Events\DeviceAlertReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Jobs\ProcessTelemetryPacketJob;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\StrangerSnap;
use App\Services\CameraMqttService;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttListenCommand extends Command
{
    protected $signature = 'mqtt:listen {--topic=mqtt/face/# : The MQTT wildcard topic to listen on}';
    protected $description = 'Listen to camera MQTT streams and dispatch vision telemetry events';

    public function handle(ImageStorageService $storageService): void
    {
        $server   = config('mqtt.host', env('MQTT_HOST', 'mqtt'));
        $port     = (int) config('mqtt.port', env('MQTT_PORT', 1883));
        $clientId = 'camera_hub_daemon_' . uniqid();
        $topic    = $this->option('topic');

        $this->info("Connecting to MQTT Broker {$server}:{$port} as {$clientId}...");

        while (true) {
            try {
                $mqtt = new MqttClient($server, $port, $clientId);

                $useTls = (bool) env('MQTT_TLS', false);
                $settings = (new ConnectionSettings)
                    ->setKeepAliveInterval(60)
                    ->setConnectTimeout(10)
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

                $mqtt->connect($settings, true);
                $this->info("Subscribed successfully to: {$topic}");

                $mqtt->subscribe($topic, function (string $topic, string $message) use ($mqtt, $storageService) {
                    $this->handleMessage($topic, $message, $mqtt, $storageService);
                }, 0);

                $mqtt->loop(true);
            } catch (\Throwable $e) {
                $this->error("MQTT Daemon Error: " . $e->getMessage());
                Log::error("MQTT Daemon Error: " . $e->getMessage());
                $this->info("Reconnecting in 5 seconds...");
                sleep(5);
            }
        }
    }

    protected function handleMessage(string $topic, string $rawMessage, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        $data = json_decode($rawMessage, true);
        if (!$data || !isset($data['operator'])) {
            return;
        }

        $operator = $data['operator'];
        $info = $data['info'] ?? [];

        // Extract device identifier from payload or topic path (mqtt/face/{id}/...)
        $deviceId = $info['facesluiceId'] ?? $info['facesluiceld'] ?? $info['deviceId'] ?? $info['DeviceID'] ?? null;
        if (!$deviceId) {
            $parts = explode('/', $topic);
            if (isset($parts[2]) && !in_array($parts[2], ['basic', 'heartbeat'])) {
                $deviceId = $parts[2];
            }
        }
        if ($deviceId) {
            $deviceId = trim((string) $deviceId);
            $throttleKey = "device_hb_throttle:{$deviceId}";
            if (!Cache::has($throttleKey)) {
                if ($this->isDeviceRegisteredAndActive($deviceId)) {
                    Device::where('device_id', $deviceId)->update([
                        'last_heartbeat_at' => now(),
                    ]);
                }
                Cache::put($throttleKey, true, 60);
            }
        }

        if ($this->output) {
            $this->line("[<fg=green>" . date('H:i:s') . "</>] Operator: <fg=cyan>{$operator}</> Device: <fg=yellow>{$deviceId}</>");
        }

        switch ($operator) {
            case 'VerifyPush':
            case 'RecPush':
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storageService);
                break;

            case 'StrSnapPush':
            case 'SnapPush':
                if (!empty($info['AlarmAction']) || !empty($data['AlarmAction'])) {
                    $this->handleDeviceAlert($deviceId, $operator, $data, $info, $mqtt, $storageService);
                } else {
                    $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storageService);
                }
                break;

            // AI Safety & Security Alarms
            case 'ClothHelmetSnapPush':
            case 'ClothHelmetVerifyPush':
            case 'BehaviorSnapPush':
            case 'FireSmokeSnapPush':
            case 'TemHighSnapPush':
            case 'LeaveSnapPush':
            case 'ParabolicSnapPush':
            case 'VisibleKitchenSnapPush':
            case 'SafetyRidePush':
            case 'ElectricalBicycleSnapPush':
            case 'PlateSnapPush':
            case 'CatAttrSnapPush':
            case 'CountInRegionPush':
                $this->handleDeviceAlert($deviceId, $operator, $data, $info, $mqtt, $storageService);
                break;

            case 'HeartBeat':
            case 'Heartbeat':
                $this->handleHeartbeat($deviceId, $info);
                break;

            case 'Online':
            case 'Offline':
                $this->handleOnlineStatus($deviceId, $operator, $info, $mqtt);
                break;

            case 'GetDeviceInformation-Ack':
            case 'GetMQTTconfig-Ack':
            case 'SearchPerson-Ack':
            case 'SearchPersonList-Ack':
            case 'QueryPerson-Ack':
            case 'EditPerson-Ack':
            case 'DelPerson-Ack':
            case 'DeletePersons-Ack':
            case 'DeleteAllPerson-Ack':
            case 'SetSysTime-Ack':
            case 'RebootDevice-Ack':
            case 'UpMQTTconfig-Ack':
            case 'GetSysParam-Ack':
            case 'UpSysParam-Ack':
            case 'GetCount-Ack':
            case 'GetHandSharkData-Ack':
            case 'SetHandSharkData-Ack':
            case 'ManualPushRecords-Ack':
            case 'ManualPushSnaps-Ack':
            case 'PushAck':
            case 'PushAck-Ack':
                $this->handleCommandAck($deviceId, $operator, $data);
                break;

            // Downlink operators broadcast on mqtt/face/{id} - ignore silently
            case 'SearchPersonList':
            case 'SearchPerson':
            case 'QueryPerson':
            case 'GetDeviceInformation':
            case 'GetMQTTconfig':
            case 'UpMQTTconfig':
            case 'GetSysParam':
            case 'UpSysParam':
            case 'SetSysTime':
            case 'RebootDevice':
            case 'EditPerson':
            case 'AddPersons':
            case 'DelPerson':
            case 'DeletePersons':
            case 'DeleteAllPerson':
            case 'GetSceneSnap':
            case 'GetHandSharkData':
            case 'SetHandSharkData':
            case 'GetCount':
            case 'Subscribe':
            case 'Unsubscribe':
            case 'ManualPushRecords':
            case 'ManualPushSnaps':
                // Downlink command sent by hub - no action needed on listener
                break;

            default:
                if (str_ends_with($operator, '-Ack') || str_ends_with($operator, '_Ack')) {
                    $this->handleCommandAck($deviceId, $operator, $data);
                } else {
                    Log::debug("Unhandled MQTT operator: {$operator}", ['topic' => $topic, 'payload' => $data]);
                }
                break;
        }
    }

    protected function sendPushAck(?string $deviceId, int $ackType, int $recordOrSnapId, MqttClient $mqtt): void
    {
        if (!$deviceId || !$mqtt->isConnected()) {
            return;
        }

        $ackPayload = json_encode([
            'operator' => 'PushAck',
            'messageId' => 'ACK-' . uniqid(),
            'info' => [
                'PushAckType' => $ackType,
                'SnapOrRecordID' => (int) $recordOrSnapId,
            ],
        ]);

        $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
    }

    protected function handleVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ?ImageStorageService $storageService = null): void
    {
        if (!$deviceId) {
            return;
        }

        $recordId = isset($info['RecordID']) ? (int) $info['RecordID'] : null;
        $personId = isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null);
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        // 1. Immediate hardware PushAck (<2ms)
        if ($recordId && $mqtt->isConnected()) {
            $this->sendPushAck($deviceId, 2, $recordId, $mqtt);
        }

        // 2. Dedup cache check
        $dedupKey = $recordId
            ? "mqtt_dedup:rec:{$deviceId}:{$recordId}"
            : "mqtt_dedup:rec:{$deviceId}:{$personId}:" . md5($timeStr ?? '');

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate verify push ignored for device {$deviceId}, recordId: " . ($recordId ?? 'N/A'));
            return;
        }

        if (!$this->isDeviceRegisteredAndActive($deviceId)) {
            Log::warning("VerifyPush dropped: Device [{$deviceId}] is not enrolled or inactive.");
            return;
        }

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            Device::where('device_id', $deviceId)->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);
        }

        // 3. Offload to Tier 2 asynchronous background job
        ProcessTelemetryPacketJob::dispatch($deviceId, $data['operator'] ?? 'VerifyPush', $data);
    }

    /**
     * Handle pure stranger face captures (unregistered persons).
     */
    protected function handleStrangerSnapPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ?ImageStorageService $storageService = null): void
    {
        if (!$deviceId) {
            return;
        }

        $snapId = isset($info['SnapID']) ? (int) $info['SnapID'] : null;
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        // 1. Immediate hardware PushAck (<2ms)
        if ($snapId && $mqtt->isConnected()) {
            $this->sendPushAck($deviceId, 1, $snapId, $mqtt);
        }

        // 2. Dedup cache check
        $dedupKey = $snapId 
            ? "mqtt_dedup:snap:{$deviceId}:{$snapId}" 
            : "mqtt_dedup:snap:{$deviceId}:" . md5(($timeStr ?? '') . ($info['targetPosInScene'] ?? ''));

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate stranger snap ignored for device {$deviceId}, snapId: " . ($snapId ?? 'N/A'));
            return;
        }

        if (!$this->isDeviceRegisteredAndActive($deviceId)) {
            Log::warning("StrangerSnapPush dropped: Device [{$deviceId}] is not enrolled or inactive.");
            return;
        }

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            Device::where('device_id', $deviceId)->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);
        }

        // 3. Offload to Tier 2 asynchronous background job
        ProcessTelemetryPacketJob::dispatch($deviceId, $data['operator'] ?? 'StrSnapPush', $data);
    }

    /**
     * Handle edge AI safety, security and hazard alerts.
     */
    protected function handleDeviceAlert(?string $deviceId, string $operator, array $data, array $info, MqttClient $mqtt, ?ImageStorageService $storageService = null): void
    {
        if (!$deviceId) {
            return;
        }

        $alertId = $info['SnapID'] ?? $info['ID'] ?? null;
        $timeStr = $info['time'] ?? $info['snapTime'] ?? $info['CreateTime'] ?? $info['startTime'] ?? null;

        // 1. Immediate hardware PushAck (<2ms)
        if ($alertId && $mqtt->isConnected()) {
            $this->sendPushAck($deviceId, 1, (int) $alertId, $mqtt);
        }

        // 2. Dedup cache check
        $dedupKey = $alertId
            ? "mqtt_dedup:alert:{$deviceId}:{$operator}:{$alertId}"
            : "mqtt_dedup:alert:{$deviceId}:{$operator}:" . md5(($timeStr ?? '') . ($info['AlarmAction'] ?? ''));

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate device alert ignored for device {$deviceId}, operator: {$operator}");
            return;
        }

        if (!$this->isDeviceRegisteredAndActive($deviceId)) {
            Log::warning("DeviceAlert dropped: Device [{$deviceId}] is not enrolled or inactive.");
            return;
        }

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            Device::where('device_id', $deviceId)->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);
        }

        // 3. Offload to Tier 2 asynchronous background job
        ProcessTelemetryPacketJob::dispatch($deviceId, $operator, $data);
    }

    protected function handleHeartbeat(?string $deviceId, array $info): void
    {
        if (!$deviceId) {
            return;
        }

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device = Device::firstOrCreate(
                ['device_id' => $deviceId],
                [
                    'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
                    'ip_address' => $info['ip'] ?? '192.168.1.100',
                    'is_active' => false,
                ]
            );

            $device->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);

            if ($device->is_active) {
                broadcast(new DeviceStatusUpdated($device));
            }
        }
    }

    protected function handleOnlineStatus(?string $deviceId, string $operator, array $info, MqttClient $mqtt): void
    {
        if (!$deviceId) {
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
                'ip_address' => $info['ip'] ?? '192.168.1.100',
                'is_active' => false,
            ]
        );

        if ($operator === 'Online') {
            $device->update(['last_heartbeat_at' => now()]);
            if ($this->output) {
                $this->info("Device {$deviceId} came ONLINE at {$info['ip']}");
            }

            // Reply Online-Ack
            $onlineAck = json_encode([
                'messageId' => '10201',
                'operator' => 'Online-Ack',
                'info' => [
                    'facesluiceId' => $deviceId,
                    'result' => 'ok',
                ],
            ]);
            $mqtt->publish("mqtt/face/{$deviceId}", $onlineAck, 0);
            $mqtt->publish("mqtt/face/basic", $onlineAck, 0);
        } else {
            if ($this->output) {
                $this->warn("Device {$deviceId} went OFFLINE (LWT)");
            }
        }

        if ($device->is_active) {
            broadcast(new DeviceStatusUpdated($device));
        }
    }

    protected function handleCommandAck(?string $deviceId, string $operator, array $data): void
    {
        $messageId = $data['messageId'] ?? null;
        if ($messageId) {
            Cache::put("mqtt_ack:{$messageId}", $data, 30);
        }

        if ($deviceId) {
            Cache::put("mqtt_ack:{$deviceId}:{$operator}", $data, 30);

            // If it's a person search response, cache for roster and audit queries
            if ($operator === 'SearchPersonList-Ack' || $operator === 'SearchPersonList') {
                Cache::put("camera_face_list:{$deviceId}", $data, 300);
            }

            $device = Device::where('device_id', $deviceId)->first();
            if ($device) {
                $device->update(['last_heartbeat_at' => now()]);
            }
        }

        app(CameraMqttService::class)->handleCommandAck($data);
    }

    public function isDeviceRegistered(?string $deviceId): bool
    {
        return $this->isDeviceRegisteredAndActive($deviceId);
    }

    public function isDeviceRegisteredAndActive(?string $deviceId): bool
    {
        if ($deviceId === null || $deviceId === '') {
            return false;
        }

        $deviceId = trim((string) $deviceId);
        $cacheKey = "device_registered:{$deviceId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $device = Device::where('device_id', $deviceId)->first();
        if (!$device) {
            try {
                Device::firstOrCreate(
                    ['device_id' => $deviceId],
                    [
                        'name' => "Camera {$deviceId}",
                        'ip_address' => '192.168.1.100',
                        'is_active' => false,
                        'last_heartbeat_at' => now(),
                    ]
                );
            } catch (\Throwable $e) {
                // Ignore concurrent creation race condition
            }
            Cache::put($cacheKey, false, 600);
            return false;
        }

        $isActive = (bool) $device->is_active;
        Cache::put($cacheKey, $isActive, 600);

        return $isActive;
    }

    protected function parseCameraTimestamp(?string $timeStr): Carbon
    {
        if (empty($timeStr)) {
            return now();
        }

        try {
            return Carbon::parse($timeStr);
        } catch (\Throwable $e) {
            return now();
        }
    }
}
