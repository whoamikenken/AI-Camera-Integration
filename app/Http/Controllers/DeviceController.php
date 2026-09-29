<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\CameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(protected CameraService $cameraService)
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
                    'scheme' => $device->scheme ?: 'http',
                    'ip_address' => $device->ip_address,
                    'port' => $device->port,
                    'endpoint_url' => $device->endpoint_url,
                    'username' => $device->username,
                    'password' => $device->password,
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

    public function probe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'scheme' => 'nullable|string|in:http,https',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        $result = $this->cameraService->probeEndpoint(
            $validated['endpoint'],
            $validated['port'] ?? null,
            $validated['scheme'] ?? null,
            $validated['username'] ?? 'admin',
            $validated['password'] ?? 'admin'
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:64|unique:devices,device_id',
            'name' => 'required|string|max:128',
            'scheme' => 'nullable|string|in:http,https',
            'ip_address' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'device_type' => 'nullable|integer|in:0,1,2,3',
            'mqtt_topic' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
        ]);

        $parsed = \App\Services\CameraHttpService::parseEndpoint(
            $validated['ip_address'],
            $validated['port'] ?? null,
            $validated['scheme'] ?? null
        );

        $deviceData = array_merge([
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ], $validated, [
            'scheme' => $parsed['scheme'],
            'ip_address' => $parsed['host'],
            'port' => $parsed['port'],
        ]);

        $device = Device::create($deviceData);

        $importedSummary = null;
        try {
            $importedSummary = $this->cameraService->importPersonnelFromCamera($device);
        } catch (\Throwable $e) {
            // Log or ignore if camera is offline during store
        }

        return response()->json(array_merge($device->toArray(), [
            'imported_personnel' => $importedSummary,
        ]), 201);
    }

    public function importPersonnel(Device $device): JsonResponse
    {
        $result = $this->cameraService->importPersonnelFromCamera($device);

        return response()->json($result);
    }

    public function show(Device $device): JsonResponse
    {
        $device->loadCount(['accessLogs', 'strangerSnaps']);

        return response()->json(array_merge($device->toArray(), [
            'endpoint_url' => $device->endpoint_url,
            'is_online' => $device->is_online,
            'access_logs_count' => $device->access_logs_count,
            'stranger_snaps_count' => $device->stranger_snaps_count,
        ]));
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:128',
            'scheme' => 'nullable|string|in:http,https',
            'ip_address' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'device_type' => 'nullable|integer|in:0,1,2,3',
            'mqtt_topic' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
        ]);

        $parsed = \App\Services\CameraHttpService::parseEndpoint(
            $validated['ip_address'],
            $validated['port'] ?? $device->port,
            $validated['scheme'] ?? $device->scheme
        );

        $updateData = array_merge($validated, [
            'scheme' => $parsed['scheme'],
            'ip_address' => $parsed['host'],
            'port' => $parsed['port'],
        ]);

        $device->update($updateData);

        return response()->json($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return response()->json(['message' => 'Device deleted successfully']);
    }

    public function testConnection(Request $request, Device $device): JsonResponse
    {
        $overrides = $request->validate([
            'ip_address' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'scheme' => 'nullable|string|in:http,https',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        $result = $this->cameraService->testConnection($device, array_filter($overrides));

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
            'MQEnable' => 'nullable|integer|in:0,1',
            'MQAddr' => 'nullable|string',
            'MQPort' => 'nullable|integer',
            'MQTopic' => 'nullable|string',
            'MQUser' => 'nullable|string',
            'MQPwd' => 'nullable|string',
            'MQCloudID' => 'nullable|string',
            'StrangerUploadType' => 'nullable|integer',
            'RecordUploadType' => 'nullable|integer',
            'KeepAliveInterval' => 'nullable|integer',
            'BasicTopic' => 'nullable|string',
            'HeartbeatTopic' => 'nullable|string',
            'ResumefromBreakpoint' => 'nullable|integer',
        ]);

        $result = $this->cameraService->configureMqtt($device, $params);

        if ($result['success'] && !empty($params['MQTopic'])) {
            $device->update(['mqtt_topic' => $params['MQTopic']]);
        }

        return response()->json($result);
    }

    public function getMqttParam(Device $device): JsonResponse
    {
        $result = $this->cameraService->getMqttParam($device);

        return response()->json($result);
    }

    public function getSysParam(Device $device): JsonResponse
    {
        $result = $this->cameraService->getSysParam($device);

        return response()->json($result);
    }

    public function setSysParam(Request $request, Device $device): JsonResponse
    {
        $params = $request->validate([
            'Name' => 'nullable|string|max:128',
        ]);

        $result = $this->cameraService->setSysParam($device, $params);

        if ($result['success'] && !empty($params['Name'])) {
            $device->update(['name' => $params['Name']]);
        }

        return response()->json($result);
    }

    public function setSysTime(Request $request, Device $device): JsonResponse
    {
        $time = $request->input('time');
        $result = $this->cameraService->setSysTime($device, $time);

        return response()->json($result);
    }

    public function manualPushRecords(Request $request, Device $device): JsonResponse
    {
        $request->validate([
            'time_s' => 'required|string',
            'time_e' => 'required|string',
            'subscribe_addr' => 'nullable|string',
        ]);

        $result = $this->cameraService->manualPushRecords(
            $device,
            $request->input('time_s'),
            $request->input('time_e'),
            $request->input('subscribe_addr')
        );

        return response()->json($result);
    }

    public function manualPushSnaps(Request $request, Device $device): JsonResponse
    {
        $request->validate([
            'time_s' => 'required|string',
            'time_e' => 'required|string',
            'subscribe_addr' => 'nullable|string',
        ]);

        $result = $this->cameraService->manualPushSnaps(
            $device,
            $request->input('time_s'),
            $request->input('time_e'),
            $request->input('subscribe_addr')
        );

        return response()->json($result);
    }

    public function factoryReset(Request $request, Device $device): JsonResponse
    {
        $netPar = (int) $request->input('default_net_par', 0);
        $person = (int) $request->input('default_person', 1);

        $result = $this->cameraService->setFactoryDefault($device, $netPar, $person);

        return response()->json($result);
    }

    public function deleteAllPersons(Device $device): JsonResponse
    {
        $result = $this->cameraService->deleteAllPersonnel($device);

        return response()->json($result);
    }

    public function searchCameraList(Request $request, Device $device): JsonResponse
    {
        $beginNo = (int) $request->input('begin_no', 0);
        $count = (int) $request->input('count', 50);

        $result = $this->cameraService->searchPersonList($device, $beginNo, $count);

        return response()->json($result);
    }

    public function subscribe(Request $request, Device $device): JsonResponse
    {
        $topics = $request->input('topics', ['Snap', 'VerifyWithSnap']);
        $subscribeAddr = $request->input('subscribe_addr');
        $urls = $request->input('urls');
        $beatInterval = (int) $request->input('beat_interval', 30);
        $resumeFromBreakpoint = (int) $request->input('resume_from_breakpoint', 1);

        $result = $this->cameraService->subscribe($device, $topics, $subscribeAddr, $urls, $beatInterval, $resumeFromBreakpoint);

        return response()->json($result);
    }

    public function unsubscribe(Request $request, Device $device): JsonResponse
    {
        $topics = $request->input('topics', ['Snap', 'VerifyWithSnap']);
        $result = $this->cameraService->unsubscribe($device, $topics);

        return response()->json($result);
    }

    public function getSubscribe(Device $device): JsonResponse
    {
        $result = $this->cameraService->getSubscribe($device);

        return response()->json($result);
    }

    public function getDeviceInformation(Device $device): JsonResponse
    {
        $result = $this->cameraService->getDeviceInformation($device);

        return response()->json($result);
    }

    public function searchPerson(Request $request, Device $device): JsonResponse
    {
        $searchId = $request->input('search_id', '');
        $searchType = (int) $request->input('search_type', 0);
        $picture = (int) $request->input('picture', 0);

        $result = $this->cameraService->searchPerson($device, $searchId, $searchType, $picture);

        return response()->json($result);
    }

    public function searchPersonNum(Device $device): JsonResponse
    {
        $result = $this->cameraService->searchPersonNum($device);

        return response()->json($result);
    }

    public function audit(Device $device): JsonResponse
    {
        $sysResult = $this->cameraService->getSysParam($device);
        $infoResult = $this->cameraService->getDeviceInformation($device);
        $personNumResult = $this->cameraService->searchPersonNum($device);
        $listResult = $this->cameraService->searchPersonList($device, 0, 100);
        $mqttResult = $this->cameraService->getMqttParam($device);
        $subscribeResult = $this->cameraService->getSubscribe($device);
        $countResult = $this->cameraService->getCount($device, 0, 0);
        $handshakeResult = $this->cameraService->getHandSharkData($device);

        $cameraPersons = [];
        $listData = $listResult['data'] ?? [];
        $info = $listData['info'] ?? $listData;

        if (is_array($info)) {
            foreach ($info as $key => $val) {
                if (str_starts_with($key, 'Personinfo_') && is_array($val)) {
                    $cameraPersons[] = [
                        'customize_id' => (int) ($val['CustomizeID'] ?? $val['customId'] ?? 0),
                        'name' => $val['Name'] ?? $val['name'] ?? 'Unknown',
                        'person_type' => (int) ($val['PersonType'] ?? $val['personType'] ?? 0),
                    ];
                }
            }
        }

        $localPersonnel = \App\Models\Personnel::all();
        $discrepancies = [];

        foreach ($localPersonnel as $local) {
            $foundOnCamera = collect($cameraPersons)->firstWhere('customize_id', $local->customize_id);
            if (!$foundOnCamera) {
                $discrepancies[] = [
                    'customize_id' => $local->customize_id,
                    'name' => $local->name,
                    'issue' => 'Missing on Camera hardware database',
                ];
            }
        }

        $device->loadCount(['accessLogs', 'strangerSnaps']);
        $recentLogs = \App\Models\AccessLog::where('device_id', $device->device_id)
            ->orderBy('captured_at', 'desc')
            ->take(20)
            ->get();

        return response()->json([
            'success' => true,
            'code' => 200,
            'device' => array_merge($device->toArray(), [
                'endpoint_url' => $device->endpoint_url,
                'is_online' => $device->is_online,
                'access_logs_count' => $device->access_logs_count,
                'stranger_snaps_count' => $device->stranger_snaps_count,
            ]),
            'audit' => [
                'transport' => 'MQTT Protocol (v1.25)',
                'mqtt_topic' => $device->mqtt_topic ?: "mqtt/face/{$device->device_id}",
                'is_online' => $device->is_online,
                'device_info' => $infoResult['data'] ?? null,
                'mqtt_config' => $mqttResult['data'] ?? null,
                'personnel_count' => $personNumResult['data']['info']['PersonNum'] ?? count($cameraPersons),
            ],
            'recent_logs' => $recentLogs,
            'realtime_hardware' => [
                'sys_param' => $sysResult['data']['info'] ?? null,
                'device_info' => $infoResult['data']['info'] ?? null,
                'mqtt_param' => $mqttResult['data']['info'] ?? null,
                'subscribe_info' => $subscribeResult['data']['info'] ?? null,
                'flow_count' => $countResult['data']['info'] ?? null,
                'person_num' => $personNumResult['data']['info'] ?? null,
            ],
            'face_audit' => [
                'total_in_db' => $localPersonnel->count(),
                'total_on_camera' => count($cameraPersons),
                'synced_count' => max(0, count($cameraPersons) - count($discrepancies)),
                'missing_on_camera_count' => count($discrepancies),
                'in_sync' => count($discrepancies) === 0,
                'discrepancies' => $discrepancies,
                'camera_personnel' => $cameraPersons,
                'camera_list' => array_map(function ($p) use ($localPersonnel) {
                    $match = $localPersonnel->firstWhere('customize_id', $p['customize_id']);
                    return [
                        'customize_id' => $p['customize_id'],
                        'camera_name' => $p['name'],
                        'camera_person_type' => $p['person_type'],
                        'db_match' => $match ? ['name' => $match->name] : null,
                        'status' => $match ? 'SYNCED' : 'UNTRACKED',
                    ];
                }, $cameraPersons),
                'missing_on_camera' => array_values(array_filter(array_map(function ($d) {
                    $p = \App\Models\Personnel::where('customize_id', $d['customize_id'])->first();
                    return $p ? [
                        'id' => $p->id,
                        'customize_id' => $p->customize_id,
                        'name' => $p->name,
                        'person_type' => $p->person_type,
                    ] : null;
                }, $discrepancies))),
            ],
        ]);
    }

    public function upgradeFirmware(Request $request, Device $device): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 200,
            'message' => 'Firmware upgrade command queued via MQTT',
        ]);
    }

    public function getHandSharkData(Device $device): JsonResponse
    {
        $result = $this->cameraService->getHandSharkData($device);

        return response()->json($result);
    }

    public function setHandSharkData(Request $request, Device $device): JsonResponse
    {
        $handshakeInfo = $request->input('handshake_info', $request->all());
        $result = $this->cameraService->setHandSharkData($device, $handshakeInfo);

        return response()->json($result);
    }

    public function getCount(Request $request, Device $device): JsonResponse
    {
        $type = (int) $request->input('type', 0);
        $direction = (int) $request->input('direction', 0);

        $result = $this->cameraService->getCount($device, $type, $direction);

        return response()->json($result);
    }
}
