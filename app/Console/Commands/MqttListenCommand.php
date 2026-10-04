<?php

namespace App\Console\Commands;

use App\Events\AccessLogReceived;
use App\Events\DeviceAlertReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\StrangerSnap;
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
                $deviceModel = Device::where('device_id', $deviceId)->first();
                if ($deviceModel) {
                    $deviceModel->update([
                        'last_heartbeat_at' => now(),
                        'is_active' => true,
                    ]);
                }
                Cache::put($throttleKey, true, 60);
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

    protected function handleVerifyPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        if (!$deviceId) {
            return;
        }

        $recordId = isset($info['RecordID']) ? (int) $info['RecordID'] : null;
        $personId = isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null);
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $dedupKey = $recordId
            ? "mqtt_dedup:rec:{$deviceId}:{$recordId}"
            : "mqtt_dedup:rec:{$deviceId}:{$personId}:" . md5($timeStr ?? '');

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate verify push ignored for device {$deviceId}, recordId: " . ($recordId ?? 'N/A'));
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
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
        );

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
            Cache::put($throttleKey, true, 60);
        }

        // Decode Base64 pictures
        $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? null;
        $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? null;

        $snapPicUrl = $storageService->storeBase64Image($rawPic, 'snaps');
        $scenePicUrl = $storageService->storeBase64Image($rawScene, 'scenes');

        $capturedAt = $this->parseCameraTimestamp($timeStr);

        $log = AccessLog::create([
            'device_id' => $deviceId,
            'person_id' => $personId,
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

        if ($log->verify_status === 1) {
            \App\Jobs\ProcessAttendancePunchJob::dispatch($log);
        }

        // Reply ACK if continuous transmission record ID is present
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

    /**
     * Handle pure stranger face captures (unregistered persons).
     */
    protected function handleStrangerSnapPush(?string $deviceId, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        if (!$deviceId) {
            return;
        }

        $snapId = isset($info['SnapID']) ? (int) $info['SnapID'] : null;
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $dedupKey = $snapId 
            ? "mqtt_dedup:snap:{$deviceId}:{$snapId}" 
            : "mqtt_dedup:snap:{$deviceId}:" . md5(($timeStr ?? '') . ($info['targetPosInScene'] ?? ''));

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate stranger snap ignored for device {$deviceId}, snapId: " . ($snapId ?? 'N/A'));
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
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
        );

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
            Cache::put($throttleKey, true, 60);
        }

        $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? null;
        $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? null;

        $snapPicUrl = $storageService->storeBase64Image($rawPic, 'strangers');
        $scenePicUrl = $storageService->storeBase64Image($rawScene, 'scenes');

        $capturedAt = $this->parseCameraTimestamp($timeStr);

        $snap = StrangerSnap::create([
            'device_id' => $deviceId,
            'snap_id' => $snapId,
            'snap_pic_url' => $snapPicUrl ?: '',
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'alarm_action' => null,
            'captured_at' => $capturedAt,
        ]);

        broadcast(new StrangerSnapReceived($snap));

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

    /**
     * Handle edge AI safety, security and hazard alerts.
     */
    protected function handleDeviceAlert(?string $deviceId, string $operator, array $data, array $info, MqttClient $mqtt, ImageStorageService $storageService): void
    {
        if (!$deviceId) {
            return;
        }

        $alertId = $info['SnapID'] ?? $info['ID'] ?? null;
        $timeStr = $info['time'] ?? $info['snapTime'] ?? $info['CreateTime'] ?? $info['startTime'] ?? null;

        $dedupKey = $alertId
            ? "mqtt_dedup:alert:{$deviceId}:{$operator}:{$alertId}"
            : "mqtt_dedup:alert:{$deviceId}:{$operator}:" . md5(($timeStr ?? '') . ($info['AlarmAction'] ?? ''));

        if (!Cache::add($dedupKey, true, 60)) {
            Log::info("Duplicate device alert ignored for device {$deviceId}, operator: {$operator}");
            if ($alertId && $mqtt->isConnected()) {
                $ackPayload = json_encode([
                    'operator' => 'PushAck',
                    'messageId' => 'ACK-' . uniqid(),
                    'info' => [
                        'PushAckType' => 1,
                        'SnapOrRecordID' => (int) $alertId,
                    ],
                ]);
                $mqtt->publish("mqtt/face/{$deviceId}", $ackPayload, 0);
            }
            return;
        }

        $device = Device::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
        );

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
            Cache::put($throttleKey, true, 60);
        }

        $rawPic = $data['SanpPic'] ?? $info['pic'] ?? $data['pic'] ?? $data['Pic'] ?? $info['Pic'] ?? null;
        $rawScene = $data['ScenePic'] ?? $info['scene'] ?? $data['scene'] ?? $data['TemPic'] ?? null;

        $snapPicUrl = $storageService->storeBase64Image($rawPic, 'alerts');
        $scenePicUrl = $storageService->storeBase64Image($rawScene, 'alerts');

        $timeStr = $info['time'] ?? $info['snapTime'] ?? $info['CreateTime'] ?? $info['startTime'] ?? null;
        $capturedAt = $this->parseCameraTimestamp($timeStr);

        // Classify Alert Category & Severity
        $alertType = 'GENERIC_ALARM';
        $severity = 'WARNING';
        $title = 'Edge Security Alert';
        $description = $info['AlarmAction'] ?? null;

        switch ($operator) {
            case 'ClothHelmetSnapPush':
            case 'ClothHelmetVerifyPush':
                $alertType = 'PPE_VIOLATION';
                $severity = 'WARNING';
                $helmet = (int) ($info['helmet'] ?? 0);
                $vest = (int) ($info['reflectiveVest'] ?? 0);
                if ($helmet === 1 && $vest === 1) {
                    $title = 'Missing Hardhat & Safety Vest';
                    $description = 'Worker detected without protective helmet and high-visibility vest.';
                } elseif ($helmet === 1) {
                    $title = 'Missing Protective Hardhat';
                    $description = 'Worker detected in designated zone without safety helmet.';
                } elseif ($vest === 1) {
                    $title = 'Missing High-Visibility Vest';
                    $description = 'Worker detected in zone without required reflective vest.';
                } else {
                    $title = 'PPE Safety Compliance Alert';
                }
                break;

            case 'BehaviorSnapPush':
                $bType = (int) ($info['behaviourType'] ?? 0);
                $alertType = $bType === 0 ? 'TRIPWIRE_INCURSION' : 'AREA_INTRUSION';
                $severity = 'CRITICAL';
                $title = $bType === 0 ? 'Perimeter Tripwire Incursion' : 'Restricted Area Intrusion';
                $dir = (int) ($info['behaviourDirection'] ?? 0);
                $dirLabels = ['Left to Right', 'Right to Left', 'Zone Entry', 'Zone Exit'];
                $description = "Intrusion detected. Direction: " . ($dirLabels[$dir] ?? 'Unknown');
                break;

            case 'FireSmokeSnapPush':
                $alertType = 'FIRE_SMOKE';
                $severity = 'CRITICAL';
                $fire = (int) ($info['fire'] ?? 0);
                $smoke = (int) ($info['smoke'] ?? 0);
                $title = $fire === 1 ? 'Open Flame / Fire Detected!' : 'Smoke Plume Detected!';
                $description = 'Immediate emergency investigation required at camera coverage zone.';
                break;

            case 'TemHighSnapPush':
                $alertType = 'TEMPERATURE_HIGH';
                $severity = 'CRITICAL';
                $spark = (int) ($info['sparkAlarm'] ?? 0);
                $title = $spark > 0 ? 'Electrical Spark / Arc Flash Detected' : 'Extreme Temperature Rise Alert';
                $description = 'Thermal sensor threshold exceeded. Inspect electrical and heat sources.';
                break;

            case 'LeaveSnapPush':
                $alertType = 'LEAVE_POST';
                $severity = 'WARNING';
                $title = 'Duty Post Unattended / Guard Absence';
                $description = 'Assigned monitoring post or workstation has been left vacant.';
                break;

            case 'ParabolicSnapPush':
                $alertType = 'PARABOLIC_DROP';
                $severity = 'CRITICAL';
                $title = 'Object Dropped from Height';
                $description = 'Falling object detected from building facade.';
                break;

            case 'VisibleKitchenSnapPush':
                $alertType = 'KITCHEN_HYGIENE';
                $severity = 'WARNING';
                $title = 'Kitchen Hygiene Infraction';
                $description = 'Sanitary rule infraction detected in food preparation zone.';
                break;

            case 'SafetyRidePush':
            case 'ElectricalBicycleSnapPush':
                $alertType = 'SAFETY_RIDE';
                $severity = 'INFO';
                $title = 'E-Bike Helmet Violation';
                $description = 'Electric bicycle rider detected without safety helmet.';
                break;

            case 'PlateSnapPush':
            case 'CatAttrSnapPush':
                $alertType = 'PLATE_RECOGNITION';
                $severity = 'INFO';
                $plate = $info['LicencePlate'] ?? $info['carplate'] ?? 'Unknown';
                $title = "Vehicle Plate: {$plate}";
                $description = "Vehicle detected with plate {$plate}";
                break;

            case 'CountInRegionPush':
                $alertType = 'OVERCROWDING';
                $severity = 'WARNING';
                $count = $info['realtimeCount'] ?? 0;
                $title = "Zone Density Threshold Exceeded ({$count} People)";
                $description = "High occupancy detected in monitored sector.";
                break;

            default:
                if (!empty($info['AlarmAction'])) {
                    $alertType = 'AI_RULE_VIOLATION';
                    $title = $info['AlarmAction'];
                }
                break;
        }

        $alert = DeviceAlert::create([
            'device_id' => $deviceId,
            'alert_type' => $alertType,
            'operator' => $operator,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'snap_pic_url' => $snapPicUrl,
            'scene_pic_url' => $scenePicUrl,
            'details' => $info,
            'status' => 'NEW',
            'captured_at' => $capturedAt,
        ]);

        broadcast(new DeviceAlertReceived($alert));

        // Create internal system notification for critical / warning alerts
        try {
            \App\Models\Notification::create([
                'title' => $title,
                'message' => "Camera [{$device->name}] reported {$title}. {$description}",
                'type' => $severity === 'CRITICAL' ? 'critical' : 'warning',
                'data' => [
                    'alert_id' => $alert->id,
                    'device_id' => $deviceId,
                    'alert_type' => $alertType,
                    'snap_pic_url' => $snapPicUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            // Notification table might be optional
        }

        // Send PushAck if continuous transmission ID is present
        $snapId = $info['SnapID'] ?? $info['ID'] ?? null;
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

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device = Device::firstOrCreate(
                ['device_id' => $deviceId],
                [
                    'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
                    'ip_address' => $info['ip'] ?? '192.168.1.100',
                    'is_active' => true,
                ]
            );

            $device->update(['last_heartbeat_at' => now(), 'is_active' => true]);
            Cache::put($throttleKey, true, 60);
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
                'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
                'ip_address' => $info['ip'] ?? '192.168.1.100',
                'is_active' => true,
            ]
        );

        if ($operator === 'Online') {
            $device->update(['last_heartbeat_at' => now()]);
            $this->info("Device {$deviceId} came ONLINE at {$info['ip']}");

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
            $this->warn("Device {$deviceId} went OFFLINE (LWT)");
        }

        broadcast(new DeviceStatusUpdated($device));
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
