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

        $parsed = CameraHttpService::parseEndpoint(
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

        return response()->json($device, 201);
    }

    public function show(Device $device): JsonResponse
    {
        return response()->json(array_merge($device->toArray(), [
            'endpoint_url' => $device->endpoint_url,
            'is_online' => $device->is_online,
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

        $parsed = CameraHttpService::parseEndpoint(
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
            $updates = ['last_heartbeat_at' => now()];

            $hardwareDeviceId = $result['data']['info']['DeviceID'] ?? null;
            if ($hardwareDeviceId && $hardwareDeviceId !== $device->device_id) {
                $updates['device_id'] = $hardwareDeviceId;
            }

            if (!empty($result['scheme']) && $result['scheme'] !== $device->scheme) {
                $updates['scheme'] = $result['scheme'];
            }
            if (!empty($result['port']) && (int)$result['port'] !== (int)$device->port) {
                $updates['port'] = (int)$result['port'];
            }

            $device->update($updates);
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
        // 1. Probe System Parameters via HTTP API (/action/GetSysParam)
        $sysResult = $this->cameraService->getSysParam($device);

        // 2. Query Device Information via HTTP API (/action/GetDeviceInformation)
        $infoResult = $this->cameraService->getDeviceInformation($device);

        // 3. Query Total Person Count via HTTP API (/action/SearchPersonNum)
        $personNumResult = $this->cameraService->searchPersonNum($device);

        // 4. Query Person List via HTTP API (/action/SearchPersonList)
        $listResult = $this->cameraService->searchPersonList($device, 0, 100);

        // 5. Query MQTT Parameters via HTTP API (/action/GetMQTTParam)
        $mqttResult = $this->cameraService->getMqttParam($device);

        // 6. Query Webhook Subscription Status via HTTP API (/action/GetSubscribe)
        $subscribeResult = $this->cameraService->getSubscribe($device);

        // 7. Query Passage Flow / Directional Counts via HTTP API (/action/GetCount)
        $countResult = $this->cameraService->getCount($device, 0, 0);

        // 8. Query Handshake Storage Data via HTTP API (/action/GetHandSharkData)
        $handshakeResult = $this->cameraService->getHandSharkData($device);

        // Extract camera enrolled persons
        // Extract camera enrolled persons flexibly
        $cameraPersons = [];
        $listData = $listResult['data'] ?? [];
        $info = $listData['info'] ?? $listData;

        if (is_array($info)) {
            // Pattern 1: Numbered keys like Personinfo_0, Personinfo_1, etc.
            foreach ($info as $key => $val) {
                if (is_string($key) && preg_match('/^(Personinfo|personinfo|Person|person)_?\d+$/i', $key) && is_array($val)) {
                    $cameraPersons[] = $val;
                }
            }

            // Pattern 2: Array keys like Personinfo, personinfo, personList, list, persons, data
            if (empty($cameraPersons)) {
                foreach (['Personinfo', 'personinfo', 'personList', 'person_list', 'list', 'persons', 'data', 'PersonList'] as $arrayKey) {
                    if (isset($info[$arrayKey]) && is_array($info[$arrayKey])) {
                        $cameraPersons = $info[$arrayKey];
                        break;
                    }
                }
            }

            // Pattern 3: Sequential list of objects in $info itself
            if (empty($cameraPersons) && array_is_list($info)) {
                $cameraPersons = array_filter($info, fn($item) => is_array($item) && (isset($item['CustomizeID']) || isset($item['customId']) || isset($item['Name']) || isset($item['name'])));
            }
        }

        // Match against Central Personnel Database
        $dbPersonnel = \App\Models\Personnel::all()->keyBy('customize_id');

        $auditComparison = [];
        $matchedCount = 0;

        foreach ($cameraPersons as $cPerson) {
            if (!is_array($cPerson)) continue;

            $cId = (int) ($cPerson['CustomizeID'] ?? $cPerson['customId'] ?? $cPerson['id'] ?? $cPerson['Id'] ?? 0);
            $cName = $cPerson['Name'] ?? $cPerson['name'] ?? $cPerson['personName'] ?? $cPerson['persionName'] ?? 'Unknown';
            $cType = (int) ($cPerson['PersonType'] ?? $cPerson['personType'] ?? 0);

            $dbMatch = $dbPersonnel->get($cId);

            if ($dbMatch) {
                $matchedCount++;
            }

            $auditComparison[] = [
                'customize_id' => $cId,
                'camera_name' => $cName,
                'camera_person_type' => $cType,
                'db_match' => $dbMatch ? [
                    'id' => $dbMatch->id,
                    'name' => $dbMatch->name,
                    'person_type' => $dbMatch->person_type,
                ] : null,
                'status' => $dbMatch ? 'SYNCED' : 'UNTRACKED_ON_CAMERA',
            ];
        }

        // Check for personnel in DB that aren't on camera
        $cameraCids = collect($auditComparison)->map(fn($p) => (int) $p['customize_id'])->filter(fn($id) => $id > 0)->toArray();
        $missingOnCamera = $dbPersonnel->filter(fn($p) => !in_array($p->customize_id, $cameraCids))->values();

        // Fetch recent access logs for this device from local DB
        $recentLogs = \App\Models\AccessLog::where('device_id', $device->device_id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        $recentSnaps = \App\Models\StrangerSnap::where('device_id', $device->device_id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'device' => [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'port' => $device->port,
                'scheme' => $device->scheme,
                'endpoint_url' => $device->endpoint_url,
                'is_online' => $device->is_online,
                'last_heartbeat_at' => $device->last_heartbeat_at ? $device->last_heartbeat_at->toIso8601String() : null,
            ],
            'realtime_hardware' => [
                'sys_param' => $sysResult['success'] ? ($sysResult['data']['info'] ?? null) : null,
                'device_info' => $infoResult['success'] ? ($infoResult['data']['info'] ?? null) : null,
                'person_num' => $personNumResult['success'] ? ($personNumResult['data']['info'] ?? null) : null,
                'mqtt_param' => $mqttResult['success'] ? ($mqttResult['data']['info'] ?? null) : null,
                'subscribe_info' => $subscribeResult['success'] ? ($subscribeResult['data']['info'] ?? null) : null,
                'flow_count' => $countResult['success'] ? ($countResult['data']['info'] ?? null) : null,
                'handshake_data' => $handshakeResult['success'] ? ($handshakeResult['data']['info'] ?? null) : null,
            ],
            'hardware_info' => $sysResult['data']['info'] ?? null,
            'face_audit' => [
                'total_on_camera' => count($cameraPersons),
                'total_in_db' => $dbPersonnel->count(),
                'synced_count' => $matchedCount,
                'untracked_on_camera' => count($cameraPersons) - $matchedCount,
                'missing_on_camera_count' => $missingOnCamera->count(),
                'camera_list' => $auditComparison,
                'missing_on_camera' => $missingOnCamera,
            ],
            'recent_logs' => $recentLogs,
            'recent_snaps' => $recentSnaps,
        ]);
    }

    public function getHandSharkData(Device $device): JsonResponse
    {
        $result = $this->cameraService->getHandSharkData($device);

        return response()->json($result);
    }

    public function setHandSharkData(Request $request, Device $device): JsonResponse
    {
        $request->validate([
            'handshake_info' => 'required|string',
        ]);

        $result = $this->cameraService->setHandSharkData($device, $request->input('handshake_info'));

        return response()->json($result);
    }

    public function getCount(Request $request, Device $device): JsonResponse
    {
        $objectType = (int) $request->input('object_type', 0);
        $behaviourDirection = (int) $request->input('behaviour_direction', 0);

        $result = $this->cameraService->getCount($device, $objectType, $behaviourDirection);

        return response()->json($result);
    }

    public function upgradeFirmware(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'upgrade_type' => 'nullable|integer',
        ]);

        $result = $this->cameraService->upgradeFirmware(
            $device,
            $validated['name'],
            $validated['url'],
            (int) ($validated['upgrade_type'] ?? 1)
        );

        return response()->json($result);
    }
}
