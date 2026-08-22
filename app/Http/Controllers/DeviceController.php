<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\CameraHttpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(protected CameraHttpService $cameraService)
    {
    }

    public function index(): JsonResponse
    {
        $devices = Device::withCount(['accessLogs', 'strangerSnaps'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($device) {
                return [
                    'id' => $device->id,
                    'device_id' => $device->device_id,
                    'name' => $device->name,
                    'ip_address' => $device->ip_address,
                    'port' => $device->port,
                    'username' => $device->username,
                    'device_type' => $device->device_type,
                    'mqtt_topic' => $device->mqtt_topic,
                    'is_active' => $device->is_active,
                    'is_online' => $device->is_online,
                    'last_heartbeat_at' => $device->last_heartbeat_at ? $device->last_heartbeat_at->toIso8601String() : null,
                    'access_logs_count' => $device->access_logs_count,
                    'stranger_snaps_count' => $device->stranger_snaps_count,
                    'created_at' => $device->created_at->toIso8601String(),
                ];
            });

        return response()->json($devices);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:64|unique:devices,device_id',
            'name' => 'required|string|max:128',
            'ip_address' => 'required|string|max:45',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'device_type' => 'nullable|integer|in:0,1,2,3',
            'mqtt_topic' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
        ]);

        $device = Device::create(array_merge([
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ], $validated));

        return response()->json($device, 201);
    }

    public function show(Device $device): JsonResponse
    {
        return response()->json(array_merge($device->toArray(), [
            'is_online' => $device->is_online,
        ]));
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => "required|string|max:64|unique:devices,device_id,{$device->id}",
            'name' => 'required|string|max:128',
            'ip_address' => 'required|string|max:45',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'device_type' => 'nullable|integer|in:0,1,2,3',
            'mqtt_topic' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
        ]);

        $device->update($validated);

        return response()->json($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return response()->json(['message' => 'Device deleted successfully']);
    }

    public function testConnection(Device $device): JsonResponse
    {
        $result = $this->cameraService->testConnection($device);

        if ($result['success']) {
            $device->update(['last_heartbeat_at' => now()]);
        }

        return response()->json($result);
    }

    public function reboot(Device $device): JsonResponse
    {
        $result = $this->cameraService->rebootDevice($device);

        return response()->json($result);
    }

    public function syncMqtt(Request $request, Device $device): JsonResponse
    {
        $params = $request->validate([
            'MQAddr' => 'nullable|string',
            'MQPort' => 'nullable|integer',
            'MQTopic' => 'nullable|string',
            'MQUser' => 'nullable|string',
            'MQPwd' => 'nullable|string',
        ]);

        $result = $this->cameraService->configureMqtt($device, $params);

        return response()->json($result);
    }

    public function searchCameraList(Request $request, Device $device): JsonResponse
    {
        $beginNo = (int) $request->input('begin_no', 0);
        $count = (int) $request->input('count', 50);

        $result = $this->cameraService->searchPersonList($device, $beginNo, $count);

        return response()->json($result);
    }
}
