<?php

namespace App\Console\Commands;

use App\Events\AccessLogReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\StrangerSnap;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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

                $settings = (new ConnectionSettings)
                    ->setKeepAliveInterval(60)
                    ->setConnectTimeout(10)
                    ->setUseTls(false);

                if (env('MQTT_AUTH', false) && env('MQTT_USERNAME')) {
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

        $this->line("[<fg=green>" . date('H:i:s') . "</>] Operator: <fg=cyan>{$operator}</> Device: <fg=yellow>{$deviceId}</>");

        switch ($operator) {
            case 'VerifyPush':
            case 'RecPush':
                $this->handleVerifyPush($deviceId, $data, $info, $mqtt, $storageService);
                break;

            case 'StrSnapPush':
            case 'SnapPush':
                $this->handleStrangerSnapPush($deviceId, $data, $info, $mqtt, $storageService);
                break;

            case 'HeartBeat':
            case 'Heartbeat':
                $this->handleHeartbeat($deviceId, $info);
                break;

            case 'Online':
            case 'Offline':
                $this->handleOnlineStatus($deviceId, $operator, $info, $mqtt);
                break;

            default:
                Log::debug("Unhandled MQTT operator: {$operator}", ['topic' => $topic]);
                break;
        }
    }

    protected function handleVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        if (!$deviceId) {
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
        );

        $device->update(['last_heartbeat_at' => now()]);

        // Decode Base64 pictures
        $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? null;
        $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? null;

        $snapPicUrl = $storageService->storeBase64Image($rawPic, 'snaps');
        $scenePicUrl = $storageService->storeBase64Image($rawScene, 'scenes');

        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;
        $capturedAt = $timeStr ? Carbon::parse($timeStr) : now();

        $log = AccessLog::create([
            'device_id' => $deviceId,
            'person_id' => isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null),
            'customize_id' => isset($info['customId']) && is_numeric($info['customId']) ? (int) $info['customId'] : (isset($info['CustomizeID']) && is_numeric($info['CustomizeID']) ? (int) $info['CustomizeID'] : null),
            'person_uuid' => $info['PersonUUID'] ?? null,
            'person_name' => $info['persionName'] ?? $info['personName'] ?? $info['Name'] ?? null,
            'verify_status' => (int) ($info['VerifyStatus'] ?? 1),
            'verify_type' => (int) ($info['VerifyType'] ?? $info['VerfyType'] ?? 1),
            'person_type' => (int) ($info['PersonType'] ?? 0),
            'similarity' => isset($info['similarity1']) ? (float) $info['similarity1'] : (isset($info['Similarity1']) ? (float) $info['Similarity1'] : null),
            'snap_pic_url' => $snapPicUrl,
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'captured_at' => $capturedAt,
        ]);

        // Broadcast to WebSocket subscribers
        broadcast(new AccessLogReceived($log));

        // Reply ACK if continuous transmission record ID is present
        $recordId = $info['RecordID'] ?? null;
        if ($recordId && $mqtt->isConnected()) {
            $ackPayload = json_encode([
                'operator' => 'PushAck',
                'messageId' => 'ACK-' . uniqid(),
                'info' => [
                    'PushAckType' => 2,
                    'SnapOrRecordID' => (int) $recordId,
                ],
            ]);
            $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
        }
    }

    protected function handleStrangerSnapPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        if (!$deviceId) {
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
        );

        $device->update(['last_heartbeat_at' => now()]);

        $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? null;
        $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? null;

        $snapPicUrl = $storageService->storeBase64Image($rawPic, 'strangers');
        $scenePicUrl = $storageService->storeBase64Image($rawScene, 'scenes');

        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;
        $capturedAt = $timeStr ? Carbon::parse($timeStr) : now();

        $snap = StrangerSnap::create([
            'device_id' => $deviceId,
            'snap_id' => isset($info['SnapID']) ? (int) $info['SnapID'] : null,
            'snap_pic_url' => $snapPicUrl ?: '',
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'alarm_action' => $info['AlarmAction'] ?? null,
            'captured_at' => $capturedAt,
        ]);

        broadcast(new StrangerSnapReceived($snap));

        $snapId = $info['SnapID'] ?? null;
        if ($snapId && $mqtt->isConnected()) {
            $ackPayload = json_encode([
                'operator' => 'PushAck',
                'messageId' => 'ACK-' . uniqid(),
                'info' => [
                    'PushAckType' => 1,
                    'SnapOrRecordID' => (int) $snapId,
                ],
            ]);
            $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
        }
    }

    protected function handleHeartbeat(?string $deviceId, array $info): void
    {
        if (!$deviceId) {
            return;
        }

        $device = Device::where('device_id', $deviceId)->first();
        if ($device) {
            $device->update(['last_heartbeat_at' => now()]);
            broadcast(new DeviceStatusUpdated($device));
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
                'name' => $info['facesname'] ?? "Camera {$deviceId}",
                'ip_address' => $info['ip'] ?? '192.168.1.100',
                'is_active' => true,
            ]
        );

        $device->update([
            'last_heartbeat_at' => now(),
            'is_active' => ($operator === 'Online'),
        ]);

        broadcast(new DeviceStatusUpdated($device));

        if ($operator === 'Online' && $mqtt->isConnected()) {
            $ackPayload = json_encode([
                'messageId' => 'ONLINE-ACK-' . uniqid(),
                'operator' => 'Online-Ack',
                'info' => [
                    'facesluiceId' => $deviceId,
                    'result' => 'ok',
                ],
            ]);
            $mqtt->publish("mqtt/face/basic", $ackPayload, 0);
        }
    }
}
