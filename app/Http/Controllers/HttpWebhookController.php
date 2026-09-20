<?php

namespace App\Http\Controllers;

use App\Events\AccessLogReceived;
use App\Events\DeviceStatusUpdated;
use App\Events\StrangerSnapReceived;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\StrangerSnap;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HttpWebhookController extends Controller
{
    public function __construct(protected ImageStorageService $storageService)
    {
    }

    /**
     * Handle camera subscription heartbeat (POST /Subscribe/heartbeat).
     */
    public function handleHeartbeat(Request $request): JsonResponse
    {
        $payload = $request->all();
        $info = $payload['info'] ?? [];
        $deviceId = (string) ($info['DeviceID'] ?? $payload['DeviceID'] ?? '');

        if ($deviceId) {
            $device = Device::where('device_id', $deviceId)->first();
            if ($device) {
                $device->update(['last_heartbeat_at' => now()]);
                event(new DeviceStatusUpdated($device));
            }
        }

        return response()->json([
            'code' => 200,
            'desc' => 'OK',
            'info' => ['Result' => 'Ok'],
        ]);
    }

    /**
     * Handle verification push event (POST /Subscribe/Verify).
     */
    public function handleVerify(Request $request): JsonResponse
    {
        $payload = $request->all();
        $info = $payload['info'] ?? $payload;

        $deviceId = (string) ($info['DeviceID'] ?? '');
        $snapPicBase64 = $payload['SanpPic'] ?? $payload['SnapPic'] ?? $payload['Pic'] ?? $payload['pic'] ?? $payload['PlatePic'] ?? null;
        $scenePicBase64 = $payload['ScenePic'] ?? $payload['scene'] ?? $payload['TemPic'] ?? null;

        $snapUrl = $snapPicBase64 ? $this->storageService->saveBase64Image($snapPicBase64, 'verification_snaps') : null;
        $sceneUrl = $scenePicBase64 ? $this->storageService->saveBase64Image($scenePicBase64, 'verification_scenes') : null;

        $rawTime = $info['CreateTime'] ?? $info['StartTime'] ?? $info['SnapTime'] ?? $info['snapTime'] ?? $info['time'] ?? null;
        $capturedAt = $rawTime ? Carbon::parse($rawTime) : now();

        $log = AccessLog::create([
            'device_id' => $deviceId,
            'person_id' => (int) ($info['PersonID'] ?? 0),
            'customize_id' => isset($info['CustomizeID']) ? (int) $info['CustomizeID'] : null,
            'person_uuid' => $info['PersonUUID'] ?? null,
            'person_name' => $info['Name'] ?? 'Unregistered',
            'verify_status' => (int) ($info['VerifyStatus'] ?? 1),
            'verify_type' => (int) ($info['VerfyType'] ?? $info['VerifyType'] ?? 1),
            'person_type' => (int) ($info['PersonType'] ?? 0),
            'similarity' => (float) ($info['Similarity1'] ?? $info['Similarity'] ?? 0.0),
            'snap_pic_url' => $snapUrl,
            'scene_pic_url' => $sceneUrl,
            'captured_at' => $capturedAt,
        ]);

        if ($deviceId) {
            $device = Device::where('device_id', $deviceId)->first();
            if ($device) {
                $device->update(['last_heartbeat_at' => now()]);
            }
        }

        event(new AccessLogReceived($log));

        return response()->json([
            'code' => 200,
            'desc' => 'OK',
            'info' => ['Result' => 'Ok'],
        ]);
    }

    /**
     * Handle stranger capture push event (POST /Subscribe/Snap).
     */
    public function handleSnap(Request $request): JsonResponse
    {
        $payload = $request->all();
        $info = $payload['info'] ?? $payload;

        $deviceId = (string) ($info['DeviceID'] ?? '');
        $snapPicBase64 = $payload['SanpPic'] ?? $payload['SnapPic'] ?? $payload['Pic'] ?? $payload['pic'] ?? $payload['PlatePic'] ?? null;
        $scenePicBase64 = $payload['ScenePic'] ?? $payload['scene'] ?? $payload['TemPic'] ?? null;

        $snapUrl = $snapPicBase64 ? $this->storageService->saveBase64Image($snapPicBase64, 'stranger_snaps') : null;
        $sceneUrl = $scenePicBase64 ? $this->storageService->saveBase64Image($scenePicBase64, 'stranger_scenes') : null;

        $rawTime = $info['CreateTime'] ?? $info['StartTime'] ?? $info['SnapTime'] ?? $info['snapTime'] ?? $info['time'] ?? null;
        $capturedAt = $rawTime ? Carbon::parse($rawTime) : now();

        $snap = StrangerSnap::create([
            'device_id' => $deviceId,
            'snap_pic_url' => $snapUrl ?: '',
            'scene_pic_url' => $sceneUrl,
            'captured_at' => $capturedAt,
        ]);

        if ($deviceId) {
            $device = Device::where('device_id', $deviceId)->first();
            if ($device) {
                $device->update(['last_heartbeat_at' => now()]);
            }
        }

        event(new StrangerSnapReceived($snap));

        return response()->json([
            'code' => 200,
            'desc' => 'OK',
            'info' => ['Result' => 'Ok'],
        ]);
    }
}
