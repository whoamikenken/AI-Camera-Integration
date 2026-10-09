<?php

namespace App\Jobs;

use App\Events\AccessLogReceived;
use App\Events\DeviceAlertReceived;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Notification;
use App\Models\StrangerSnap;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTelemetryPacketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public ?string $deviceId,
        public string $operator,
        public array $payload
    ) {
        $this->onQueue('camera-telemetry');
    }

    public function handle(ImageStorageService $storageService): void
    {
        $info = $this->payload['info'] ?? [];

        switch ($this->operator) {
            case 'VerifyPush':
            case 'RecPush':
                $this->processVerifyPush($info, $storageService);
                break;

            case 'StrSnapPush':
            case 'SnapPush':
                if (!empty($info['AlarmAction']) || !empty($this->payload['AlarmAction'])) {
                    $this->processDeviceAlert($this->operator, $info, $storageService);
                } else {
                    $this->processStrangerSnap($info, $storageService);
                }
                break;

            default:
                if (str_contains($this->operator, 'Alarm') || str_contains($this->operator, 'SnapPush') || !empty($info['AlarmAction'])) {
                    $this->processDeviceAlert($this->operator, $info, $storageService);
                }
                break;
        }
    }

    protected function processVerifyPush(array $info, ImageStorageService $storageService): void
    {
        $personId = isset($info['personId']) ? (int) $info['personId'] : (isset($info['PersonID']) ? (int) $info['PersonID'] : null);
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $rawPic = $this->payload['SanpPic'] ?? $info['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'snaps') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'scenes') : null;

        $capturedAt = $this->parseTimestamp($timeStr);

        $customizeId = isset($info['customId']) && is_numeric($info['customId'])
            ? (int) $info['customId']
            : (isset($info['CustomizeID']) && is_numeric($info['CustomizeID']) ? (int) $info['CustomizeID'] : null);

        $log = AccessLog::create([
            'device_id' => $this->deviceId,
            'person_id' => $personId,
            'customize_id' => $customizeId,
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

        broadcast(new AccessLogReceived($log));

        if ($log->verify_status === 1) {
            \App\Jobs\ProcessAttendancePunchJob::dispatch($log);
        }
    }

    protected function processStrangerSnap(array $info, ImageStorageService $storageService): void
    {
        $snapId = isset($info['SnapID']) ? (int) $info['SnapID'] : null;
        $timeStr = $info['time'] ?? $info['CreateTime'] ?? null;

        $rawPic = $this->payload['SanpPic'] ?? $info['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'strangers') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'scenes') : null;

        $capturedAt = $this->parseTimestamp($timeStr);

        $snap = StrangerSnap::create([
            'device_id' => $this->deviceId,
            'snap_id' => $snapId,
            'snap_pic_url' => $snapPicUrl ?: '',
            'scene_pic_url' => $scenePicUrl,
            'target_pos' => $info['targetPosInScene'] ?? null,
            'is_no_mask' => (int) ($info['isNoMask'] ?? 0),
            'alarm_action' => null,
            'captured_at' => $capturedAt,
        ]);

        broadcast(new StrangerSnapReceived($snap));
    }

    protected function processDeviceAlert(string $operator, array $info, ImageStorageService $storageService): void
    {
        $rawPic = $this->payload['SanpPic'] ?? $info['SanpPic'] ?? $info['pic'] ?? $this->payload['pic'] ?? $this->payload['Pic'] ?? $info['Pic'] ?? null;
        $rawScene = $this->payload['ScenePic'] ?? $info['ScenePic'] ?? $info['scene'] ?? $this->payload['scene'] ?? $this->payload['TemPic'] ?? null;

        $snapPicUrl = !empty($rawPic) ? $storageService->storeBase64Image($rawPic, 'alerts') : null;
        $scenePicUrl = !empty($rawScene) ? $storageService->storeBase64Image($rawScene, 'alerts') : null;

        $timeStr = $info['time'] ?? $info['snapTime'] ?? $info['CreateTime'] ?? $info['startTime'] ?? null;
        $capturedAt = $this->parseTimestamp($timeStr);

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
            'device_id' => $this->deviceId,
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

        try {
            $device = Device::where('device_id', $this->deviceId)->first();
            $deviceName = $device?->name ?? $this->deviceId;
            Notification::create([
                'title' => $title,
                'message' => "Camera [{$deviceName}] reported {$title}. {$description}",
                'type' => $severity === 'CRITICAL' ? 'critical' : 'warning',
                'data' => [
                    'alert_id' => $alert->id,
                    'device_id' => $this->deviceId,
                    'alert_type' => $alertType,
                    'snap_pic_url' => $snapPicUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            // Notification table might be optional
        }
    }

    protected function parseTimestamp(?string $timeStr): Carbon
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
