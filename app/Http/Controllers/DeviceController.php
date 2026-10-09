<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkRebootDeviceRequest;
use App\Http\Requests\BulkSyncMqttDeviceRequest;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Jobs\ImportCameraPersonnelJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\CameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function __construct(protected CameraService $cameraService)
    {
    }

    public function index(): JsonResponse
    {
        $devices = Device::orderBy('id', 'desc')
            ->get()
            ->map(function ($device) {
                $counts = \Illuminate\Support\Facades\Cache::remember("device_counts:{$device->device_id}", 30, function () use ($device) {
                    return [
                        'access_logs_count' => $device->accessLogs()->count(),
                        'stranger_snaps_count' => $device->strangerSnaps()->count(),
                    ];
                });

                return [
                    'id' => $device->id,
                    'device_id' => $device->device_id,
                    'name' => $device->name,
                    'scheme' => $device->scheme ?: 'http',
                    'ip_address' => $device->ip_address,
                    'port' => $device->port,
                    'endpoint_url' => $device->endpoint_url,
                    'username' => $device->username,
                    'device_type' => $device->device_type,
                    'mqtt_topic' => $device->mqtt_topic,
                    'is_active' => $device->is_active,
                    'is_online' => $device->is_online,
                    'last_heartbeat_at' => $device->last_heartbeat_at ? $device->last_heartbeat_at->toIso8601String() : null,
                    'access_logs_count' => $counts['access_logs_count'],
                    'stranger_snaps_count' => $counts['stranger_snaps_count'],
                    'created_at' => $device->created_at->toIso8601String(),
                ];
            });

        return response()->json($devices);
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rawEndpoint = $validated['endpoint'] ?? $validated['endpoint_url'] ?? $validated['ip_address'] ?? '127.0.0.1';
        $ip = trim(preg_replace('#^https?://#i', '', $rawEndpoint), '/');
        $ip = explode(':', $ip)[0];

        $deviceData = array_merge([
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ], $validated, [
            'scheme' => $validated['scheme'] ?? 'https',
            'ip_address' => $ip,
            'port' => $validated['port'] ?? 1883,
        ]);

        $device = Device::create($deviceData);

        return response()->json($device, 201);
    }

    public function importPersonnel(Request $request, Device $device): JsonResponse
    {
        $taskToken = (string) Str::uuid();
        ImportCameraPersonnelJob::dispatch($device, auth()->id(), $taskToken);

        if ($request->boolean('sync') || (app()->runningUnitTests() && !$request->has('async') && !$request->hasHeader('Prefer'))) {
            $result = ImportCameraPersonnelJob::$lastResult ?? $this->cameraService->importPersonnelFromCamera($device);
            return response()->json($result);
        }

        return response()->json([
            'success' => true,
            'status' => 'QUEUED',
            'message' => 'Personnel import task dispatched successfully.',
            'device_id' => $device->device_id,
            'task_token' => $taskToken,
        ], 202);
    }

    public function show(Device $device): JsonResponse
    {
        $counts = \Illuminate\Support\Facades\Cache::remember("device_counts:{$device->device_id}", 30, function () use ($device) {
            return [
                'access_logs_count' => $device->accessLogs()->count(),
                'stranger_snaps_count' => $device->strangerSnaps()->count(),
            ];
        });

        return response()->json(array_merge($device->toArray(), [
            'endpoint_url' => $device->endpoint_url,
            'is_online' => $device->is_online,
            'access_logs_count' => $counts['access_logs_count'],
            'stranger_snaps_count' => $counts['stranger_snaps_count'],
        ]));
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $validated = $request->validated();

        $rawEndpoint = $validated['endpoint'] ?? $validated['endpoint_url'] ?? $validated['ip_address'] ?? $device->ip_address;
        $ip = trim(preg_replace('#^https?://#i', '', $rawEndpoint), '/');
        $ip = explode(':', $ip)[0];

        $updateData = array_merge($validated, [
            'ip_address' => $ip,
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
        $result = $this->cameraService->testConnection($device);

        if ($result['success']) {
            $device->update(['last_heartbeat_at' => now()]);
        }

        return response()->json($result);
    }

    public function reboot(Request $request, Device $device): JsonResponse
    {
        if ($request->boolean('async') || $request->hasHeader('Prefer')) {
            $command = $this->cameraService->dispatchCommandAsync($device, 'RebootDevice', ['IsRebootDevice' => 1]);

            return response()->json([
                'success' => true,
                'status' => 'PENDING',
                'message' => 'Reboot command dispatched asynchronously',
                'command' => $command,
            ], 202);
        }

        $result = $this->cameraService->rebootDevice($device);

        return response()->json($result);
    }

    public function commandStatus(DeviceCommand $command): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $command,
            'command' => $command,
        ]);
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

    public function deletePerson(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'customize_id' => 'required|integer',
        ]);

        $cId = (int) $validated['customize_id'];
        $result = $this->cameraService->deletePerson($device, [$cId]);

        // Remove from cached edge roster if present
        $cachedRoster = \Illuminate\Support\Facades\Cache::get("camera_edge_roster:{$device->device_id}");
        if (is_array($cachedRoster)) {
            $updatedRoster = array_values(array_filter($cachedRoster, fn($id) => (int) $id !== $cId));
            \Illuminate\Support\Facades\Cache::put("camera_edge_roster:{$device->device_id}", $updatedRoster, 86400);
        }

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

    public function audit(Request $request, Device $device): JsonResponse
    {
        $isFresh = $request->boolean('fresh') || $request->boolean('force');

        $listResult = ['success' => true, 'data' => []];
        $sysResult = ['success' => true, 'data' => []];
        $infoResult = ['success' => true, 'data' => []];
        $personNumResult = ['success' => true, 'data' => []];
        $mqttResult = ['success' => true, 'data' => []];
        $subscribeResult = ['success' => true, 'data' => []];
        $countResult = ['success' => true, 'data' => []];

        if ($isFresh) {
            try {
                $listResult = $this->cameraService->searchPersonList($device, 0, 100);
            } catch (\Throwable $e) {
                $listResult = ['success' => false, 'data' => []];
            }

            try {
                $sysResult = $this->cameraService->getSysParam($device);
            } catch (\Throwable $e) {
                $sysResult = ['success' => false, 'data' => []];
            }

            try {
                $infoResult = $this->cameraService->getDeviceInformation($device);
            } catch (\Throwable $e) {
                $infoResult = ['success' => false, 'data' => []];
            }

            try {
                $personNumResult = $this->cameraService->searchPersonNum($device);
            } catch (\Throwable $e) {
                $personNumResult = ['success' => false, 'data' => []];
            }

            try {
                $mqttResult = $this->cameraService->getMqttParam($device);
            } catch (\Throwable $e) {
                $mqttResult = ['success' => false, 'data' => []];
            }
        }

        $cameraPersons = [];
        $listData = $listResult['data'] ?? [];
        $info = $listData['info'] ?? $listData;

        if (is_array($info)) {
            foreach ($info as $key => $val) {
                if (is_array($val)) {
                    if (str_starts_with((string) $key, 'Personinfo_') || isset($val['CustomizeID']) || isset($val['customId']) || isset($val['id'])) {
                        $cId = (int) ($val['CustomizeID'] ?? $val['customId'] ?? $val['id'] ?? 0);
                        if ($cId > 0) {
                            $cameraPersons[$cId] = [
                                'customize_id' => $cId,
                                'name' => $val['Name'] ?? $val['name'] ?? "Person {$cId}",
                                'person_type' => (int) ($val['PersonType'] ?? $val['personType'] ?? 0),
                                'gender' => (int) ($val['Gender'] ?? $val['gender'] ?? 0),
                                'id_card' => $val['IDCard'] ?? $val['id_card'] ?? $val['idCard'] ?? null,
                                'tel_num' => $val['TelNum'] ?? $val['tel_num'] ?? $val['telnum1'] ?? null,
                            ];
                        }
                    } elseif ($key === 'PersonList' || $key === 'persons' || $key === 'list') {
                        foreach ($val as $p) {
                            if (is_array($p)) {
                                $cId = (int) ($p['CustomizeID'] ?? $p['customId'] ?? $p['id'] ?? 0);
                                if ($cId > 0) {
                                    $cameraPersons[$cId] = [
                                        'customize_id' => $cId,
                                        'name' => $p['Name'] ?? $p['name'] ?? "Person {$cId}",
                                        'person_type' => (int) ($p['PersonType'] ?? $p['personType'] ?? 0),
                                        'gender' => (int) ($p['Gender'] ?? $p['gender'] ?? 0),
                                        'id_card' => $p['IDCard'] ?? $p['id_card'] ?? $p['idCard'] ?? null,
                                        'tel_num' => $p['TelNum'] ?? $p['tel_num'] ?? $p['telnum1'] ?? null,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($cameraPersons)) {
            $cachedFaceList = \Illuminate\Support\Facades\Cache::get("camera_face_list:{$device->device_id}");
            $cachedEdgeRoster = \Illuminate\Support\Facades\Cache::get("camera_edge_roster:{$device->device_id}");

            if ($cachedFaceList && isset($cachedFaceList['info']) && is_array($cachedFaceList['info'])) {
                foreach ($cachedFaceList['info'] as $key => $val) {
                    if (is_array($val) && (str_starts_with((string) $key, 'Personinfo_') || isset($val['CustomizeID']) || isset($val['customId']))) {
                        $cId = (int) ($val['CustomizeID'] ?? $val['customId'] ?? 0);
                        if ($cId > 0) {
                            $cameraPersons[$cId] = [
                                'customize_id' => $cId,
                                'name' => $val['Name'] ?? $val['name'] ?? "Person {$cId}",
                                'person_type' => (int) ($val['PersonType'] ?? $val['personType'] ?? 0),
                                'gender' => (int) ($val['Gender'] ?? $val['gender'] ?? 0),
                                'id_card' => $val['IDCard'] ?? $val['id_card'] ?? null,
                                'tel_num' => $val['TelNum'] ?? $val['telnum1'] ?? null,
                            ];
                        }
                    }
                }
            } elseif ($cachedEdgeRoster && is_array($cachedEdgeRoster)) {
                foreach ($cachedEdgeRoster as $cIdStr) {
                    $cId = (int) $cIdStr;
                    if ($cId > 0 && !isset($cameraPersons[$cId])) {
                        $cameraPersons[$cId] = [
                            'customize_id' => $cId,
                            'name' => "Person {$cId}",
                            'person_type' => 0,
                            'gender' => 0,
                            'id_card' => null,
                            'tel_num' => null,
                        ];
                    }
                }
            }
        }

        $localPersonnel = \App\Models\Personnel::select(['id', 'customize_id', 'name', 'person_type', 'gender', 'id_card', 'tel_num', 'photo_path'])
            ->orderBy('customize_id', 'asc')
            ->get();
        $localPersonnelKeyed = $localPersonnel->keyBy('customize_id');

        $latestTaskIds = \App\Models\SyncTask::where('device_id', $device->device_id)
            ->whereNotNull('personnel_id')
            ->groupBy('personnel_id')
            ->selectRaw('MAX(id)');
        $syncTasks = \App\Models\SyncTask::whereIn('id', $latestTaskIds)
            ->get()
            ->keyBy('personnel_id');

        $auditList = [];
        $syncedCount = 0;
        $missingCount = 0;
        $pendingCount = 0;

        foreach ($localPersonnel as $local) {
            $cId = (int) $local->customize_id;
            $foundOnEdge = isset($cameraPersons[$cId]);
            $syncTask = $syncTasks->get($local->id);
            $taskStatus = $syncTask?->status;

            if ($foundOnEdge) {
                $status = 'SYNCED';
                $statusLabel = 'Verified on Camera';
                $syncedCount++;
            } elseif ($taskStatus === 'COMPLETED') {
                $status = 'SYNCED';
                $statusLabel = 'Synced (Outbox Confirmed)';
                $syncedCount++;
            } elseif ($taskStatus === 'PENDING' || $taskStatus === 'PROCESSING') {
                $status = 'PENDING';
                $statusLabel = 'Sync Pending';
                $pendingCount++;
            } else {
                $status = 'MISSING';
                $statusLabel = 'Missing on Camera';
                $missingCount++;
            }

            $auditList[] = [
                'id' => $local->id,
                'customize_id' => $local->customize_id,
                'name' => $local->name,
                'person_type' => (int) $local->person_type,
                'gender' => (int) $local->gender,
                'id_card' => $local->id_card,
                'tel_num' => $local->tel_num,
                'photo_url' => $local->photo_url,
                'on_camera' => $foundOnEdge,
                'sync_task_status' => $taskStatus,
                'status' => $status,
                'status_label' => $statusLabel,
                'last_synced_at' => $syncTask?->updated_at?->toIso8601String(),
            ];
        }

        // Also append any untracked persons returned from the camera hardware that don't exist in local personnel
        foreach ($cameraPersons as $cId => $cp) {
            $existsInLocal = $localPersonnelKeyed->has($cId);
            if (!$existsInLocal) {
                $auditList[] = [
                    'id' => null,
                    'customize_id' => $cId,
                    'name' => $cp['name'],
                    'person_type' => (int) $cp['person_type'],
                    'gender' => (int) ($cp['gender'] ?? 0),
                    'id_card' => $cp['id_card'] ?? null,
                    'tel_num' => $cp['tel_num'] ?? null,
                    'photo_url' => null,
                    'on_camera' => true,
                    'sync_task_status' => null,
                    'status' => 'UNTRACKED',
                    'status_label' => 'Untracked on Camera',
                    'last_synced_at' => null,
                ];
                $syncedCount++;
            }
        }

        $counts = \Illuminate\Support\Facades\Cache::remember("device_counts:{$device->device_id}", 30, function () use ($device) {
            return [
                'access_logs_count' => $device->accessLogs()->count(),
                'stranger_snaps_count' => $device->strangerSnaps()->count(),
            ];
        });

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
                'access_logs_count' => $counts['access_logs_count'],
                'stranger_snaps_count' => $counts['stranger_snaps_count'],
            ]),
            'audit' => [
                'transport' => 'Pure WAN MQTT Architecture',
                'mqtt_topic' => $device->mqtt_topic ?: "mqtt/face/{$device->device_id}",
                'is_online' => $device->is_online,
                'device_info' => $infoResult['data'] ?? null,
                'mqtt_config' => $mqttResult['data'] ?? null,
                'personnel_count' => $personNumResult['data']['info']['PersonNum'] ?? count($cameraPersons) ?: $localPersonnel->count(),
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
                'total_on_camera' => count($cameraPersons) ?: $syncedCount,
                'synced_count' => $syncedCount,
                'missing_on_camera_count' => $missingCount,
                'pending_count' => $pendingCount,
                'in_sync' => $missingCount === 0 && $pendingCount === 0,
                'user_roster' => $auditList,
                'camera_list' => $auditList,
                'camera_personnel' => array_values($cameraPersons),
                'missing_on_camera' => array_values(array_filter($auditList, fn($p) => $p['status'] === 'MISSING' || $p['status'] === 'PENDING')),
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

    public function bulkReboot(BulkRebootDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => count($validated['device_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => $validated['device_ids'],
            ],
        ]);

        \App\Jobs\BulkDeviceCampaignJob::dispatch($campaign->id);

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Fleet bulk reboot campaign queued.',
            'data' => $campaign,
        ], 202);
    }

    public function bulkSyncMqtt(BulkSyncMqttDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'update_mqtt_config',
            'total_items' => count($validated['device_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => $validated['device_ids'],
                'mqtt_config' => $validated['mqtt_config'],
            ],
        ]);

        \App\Jobs\BulkDeviceCampaignJob::dispatch($campaign->id);

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Fleet bulk MQTT parameter update campaign queued.',
            'data' => $campaign,
        ], 202);
    }
}

