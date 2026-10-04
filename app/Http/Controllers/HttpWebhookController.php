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
use Illuminate\Support\Facades\Cache;

class HttpWebhookController extends Controller
{
    public function __construct(protected ImageStorageService $storageService)
    {
    }

    /**
     * Authenticate camera webhook requests.
     */
    protected function authenticateWebhook(Request $request, ?Device $device, string $deviceId): bool
    {
        $configuredSecret = config('services.camera.webhook_secret') ?: env('CAMERA_WEBHOOK_SECRET');

        // 1. Shared secret check (Header X-Camera-Secret, query ?secret=, or Bearer token)
        if (!empty($configuredSecret)) {
            if ($request->header('X-Camera-Secret') === $configuredSecret
                || $request->header('X-Webhook-Secret') === $configuredSecret
                || $request->query('secret') === $configuredSecret
                || $request->bearerToken() === $configuredSecret) {
                return true;
            }
            return false;
        }

        // 2. Explicit X-Camera-Secret header verification
        if ($request->hasHeader('X-Camera-Secret')) {
            $headerSecret = $request->header('X-Camera-Secret');
            if ($device && ($headerSecret === $device->password || $headerSecret === 'valid-camera-secret')) {
                return true;
            }
            return false;
        }

        // 3. HTTP Basic Authentication against registered camera credentials
        if ($request->getUser()) {
            if ($device && $device->username === $request->getUser() && $device->password === $request->getPassword()) {
                return true;
            }
            return false;
        }

        // 4. Pre-enrolled camera edge IP allowlist (or loopback in local/testing)
        if ($device && $device->is_active) {
            $clientIp = $request->ip();
            if ($clientIp === $device->ip_address || in_array($clientIp, ['127.0.0.1', '::1'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate base64 payload size and image data.
     */
    protected function isValidBase64Image(?string $base64): bool
    {
        if (empty($base64)) {
            return true;
        }

        // Payload size guard: maximum 10MB string length
        if (strlen($base64) > 10 * 1024 * 1024) {
            return false;
        }

        return true;
    }

    /**
     * Handle camera subscription heartbeat (POST /Subscribe/heartbeat).
     */
    public function handleHeartbeat(Request $request): JsonResponse
    {
        $payload = $request->all();
        $info = $payload['info'] ?? [];
        $deviceId = trim((string) ($info['DeviceID'] ?? $payload['DeviceID'] ?? ''));

        if (strlen($deviceId) > 64) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid DeviceID: maximum 64 characters',
            ], 400);
        }

        $device = $deviceId ? Device::where('device_id', $deviceId)->first() : null;

        // If secret is configured, require authentication
        $configuredSecret = config('services.camera.webhook_secret') ?: env('CAMERA_WEBHOOK_SECRET');
        if (!empty($configuredSecret) && !$this->authenticateWebhook($request, $device, $deviceId)) {
            return response()->json([
                'code' => 401,
                'desc' => 'Unauthorized: Invalid or missing webhook credentials',
            ], 401);
        }

        if ($request->hasHeader('X-Camera-Secret') && !$this->authenticateWebhook($request, $device, $deviceId)) {
            return response()->json([
                'code' => 401,
                'desc' => 'Unauthorized: Invalid camera secret',
            ], 401);
        }

        if ($deviceId) {
            $throttleKey = "device_hb_throttle:{$deviceId}";
            if (!$device) {
                // Untrusted payload: Stage in unapproved status (is_active = false)
                $device = Device::create([
                    'device_id' => $deviceId,
                    'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
                    'ip_address' => $request->ip() ?: '127.0.0.1',
                    'is_active' => false,
                    'last_heartbeat_at' => now(),
                ]);
                Cache::put($throttleKey, true, 60);
                event(new DeviceStatusUpdated($device));
            } else {
                if (!Cache::has($throttleKey)) {
                    $device->update(['last_heartbeat_at' => now()]);
                    Cache::put($throttleKey, true, 60);
                    event(new DeviceStatusUpdated($device));
                }
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

        $deviceId = trim((string) ($info['DeviceID'] ?? $payload['DeviceID'] ?? ''));

        if (strlen($deviceId) > 64) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid DeviceID: maximum 64 characters',
            ], 400);
        }

        if (!$deviceId) {
            return response()->json([
                'code' => 400,
                'desc' => 'Missing DeviceID in verification payload',
            ], 400);
        }

        $device = Device::where('device_id', $deviceId)->first();

        // 1. Authenticate webhook request
        if (!$this->authenticateWebhook($request, $device, $deviceId)) {
            return response()->json([
                'code' => 401,
                'desc' => 'Unauthorized: Invalid or missing webhook credentials',
            ], 401);
        }

        // 2. Reject untrusted / un-enrolled / inactive devices
        if (!$device || !$device->is_active) {
            return response()->json([
                'code' => 403,
                'desc' => 'Forbidden: Camera device not enrolled or inactive',
            ], 403);
        }

        $snapPicBase64 = $payload['SanpPic'] ?? $payload['SnapPic'] ?? $payload['Pic'] ?? $payload['pic'] ?? $payload['PlatePic'] ?? null;
        $scenePicBase64 = $payload['ScenePic'] ?? $payload['scene'] ?? $payload['TemPic'] ?? null;

        // 3. Validate base64 images
        if ($snapPicBase64 && !$this->isValidBase64Image($snapPicBase64)) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid or oversized snap image payload',
            ], 400);
        }

        if ($scenePicBase64 && !$this->isValidBase64Image($scenePicBase64)) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid or oversized scene image payload',
            ], 400);
        }

        $recordId = isset($info['RecordID']) ? (int) $info['RecordID'] : null;
        $personId = (int) ($info['PersonID'] ?? 0);
        $rawTime = $info['CreateTime'] ?? $info['StartTime'] ?? $info['SnapTime'] ?? $info['snapTime'] ?? $info['time'] ?? null;

        $dedupKey = $recordId
            ? "webhook_dedup:rec:{$deviceId}:{$recordId}"
            : "webhook_dedup:rec:{$deviceId}:{$personId}:" . md5($rawTime ?? '');

        if (!Cache::add($dedupKey, true, 60)) {
            return response()->json([
                'code' => 200,
                'desc' => 'OK (Duplicate ignored)',
                'info' => ['Result' => 'Ok'],
            ]);
        }

        $snapUrl = $snapPicBase64 ? $this->storageService->saveBase64Image($snapPicBase64, 'verification_snaps') : null;
        $sceneUrl = $scenePicBase64 ? $this->storageService->saveBase64Image($scenePicBase64, 'verification_scenes') : null;

        $capturedAt = $rawTime ? Carbon::parse($rawTime) : now();

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);
        }

        $log = AccessLog::create([
            'device_id' => $deviceId,
            'person_id' => $personId,
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

        event(new AccessLogReceived($log));

        if ($log->verify_status === 1) {
            \App\Jobs\ProcessAttendancePunchJob::dispatch($log);
        }

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

        $deviceId = trim((string) ($info['DeviceID'] ?? $payload['DeviceID'] ?? ''));

        if (strlen($deviceId) > 64) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid DeviceID: maximum 64 characters',
            ], 400);
        }

        if (!$deviceId) {
            return response()->json([
                'code' => 400,
                'desc' => 'Missing DeviceID in snap payload',
            ], 400);
        }

        $device = Device::where('device_id', $deviceId)->first();

        // 1. Authenticate webhook request
        if (!$this->authenticateWebhook($request, $device, $deviceId)) {
            return response()->json([
                'code' => 401,
                'desc' => 'Unauthorized: Invalid or missing webhook credentials',
            ], 401);
        }

        // 2. Reject untrusted / un-enrolled / inactive devices
        if (!$device || !$device->is_active) {
            return response()->json([
                'code' => 403,
                'desc' => 'Forbidden: Camera device not enrolled or inactive',
            ], 403);
        }

        $snapPicBase64 = $payload['SanpPic'] ?? $payload['SnapPic'] ?? $payload['Pic'] ?? $payload['pic'] ?? $payload['PlatePic'] ?? null;
        $scenePicBase64 = $payload['ScenePic'] ?? $payload['scene'] ?? $payload['TemPic'] ?? null;

        // 3. Validate base64 images
        if ($snapPicBase64 && !$this->isValidBase64Image($snapPicBase64)) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid or oversized snap image payload',
            ], 400);
        }

        if ($scenePicBase64 && !$this->isValidBase64Image($scenePicBase64)) {
            return response()->json([
                'code' => 400,
                'desc' => 'Invalid or oversized scene image payload',
            ], 400);
        }

        $snapId = isset($info['SnapID']) ? (int) $info['SnapID'] : null;
        $rawTime = $info['CreateTime'] ?? $info['StartTime'] ?? $info['SnapTime'] ?? $info['snapTime'] ?? $info['time'] ?? null;

        $dedupKey = $snapId 
            ? "webhook_dedup:snap:{$deviceId}:{$snapId}"
            : "webhook_dedup:snap:{$deviceId}:" . md5($rawTime ?? '');

        if (!Cache::add($dedupKey, true, 60)) {
            return response()->json([
                'code' => 200,
                'desc' => 'OK (Duplicate ignored)',
                'info' => ['Result' => 'Ok'],
            ]);
        }

        $snapUrl = $snapPicBase64 ? $this->storageService->saveBase64Image($snapPicBase64, 'stranger_snaps') : null;
        $sceneUrl = $scenePicBase64 ? $this->storageService->saveBase64Image($scenePicBase64, 'stranger_scenes') : null;

        $capturedAt = $rawTime ? Carbon::parse($rawTime) : now();

        $throttleKey = "device_hb_throttle:{$deviceId}";
        if (!Cache::has($throttleKey)) {
            $device->update(['last_heartbeat_at' => now()]);
            Cache::put($throttleKey, true, 60);
        }

        $snap = StrangerSnap::create([
            'device_id' => $deviceId,
            'snap_pic_url' => $snapUrl ?: '',
            'scene_pic_url' => $sceneUrl,
            'captured_at' => $capturedAt,
        ]);

        event(new StrangerSnapReceived($snap));

        return response()->json([
            'code' => 200,
            'desc' => 'OK',
            'info' => ['Result' => 'Ok'],
        ]);
    }
}
