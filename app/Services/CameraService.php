<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;

class CameraService
{
    public function __construct(
        protected CameraMqttService $mqttService
    ) {
    }

    public function addOrUpdatePerson(Device $device, Personnel $person): array
    {
        return $this->mqttService->addOrUpdatePerson($device, $person);
    }

    public function deletePerson(Device $device, array $customizeIds): array
    {
        return $this->mqttService->deletePerson($device, $customizeIds);
    }

    public function deleteAllPersonnel(Device $device): array
    {
        return $this->mqttService->deleteAllPersonnel($device);
    }

    public function rebootDevice(Device $device): array
    {
        return $this->mqttService->rebootDevice($device);
    }

    public function configureMqtt(Device $device, array $params = []): array
    {
        return $this->mqttService->configureMqtt($device, $params);
    }

    public function getMqttParam(Device $device): array
    {
        return $this->mqttService->getMqttParam($device);
    }

    public function setSysTime(Device $device, ?string $time = null): array
    {
        return $this->mqttService->setSysTime($device, $time);
    }

    public function getDeviceInformation(Device $device): array
    {
        return $this->mqttService->getDeviceInformation($device);
    }

    public function getSceneSnap(Device $device): array
    {
        return $this->mqttService->getSceneSnap($device);
    }

    public function searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array
    {
        return $this->mqttService->searchPerson($device, $searchId, $searchType, $picture);
    }

    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array
    {
        return $this->mqttService->searchPersonList($device, $beginNo, $count);
    }

    public function searchPersonNum(Device $device): array
    {
        return $this->mqttService->searchPersonNum($device);
    }

    public function testConnection(Device $device, array $overrides = []): array
    {
        return $this->mqttService->testConnection($device);
    }

    public function buildPersonnelInfo(Personnel $person): array
    {
        return $this->mqttService->buildPersonnelInfo($person);
    }

    public function manualPushRecords(Device $device, string $timeS, string $timeE, ?string $subscribeAddr = null): array
    {
        return $this->mqttService->publishCommand($device, 'ManualPushRecords', [
            'TimeS' => $timeS,
            'TimeE' => $timeE,
        ]);
    }

    public function manualPushSnaps(Device $device, string $timeS, string $timeE, ?string $subscribeAddr = null): array
    {
        return $this->mqttService->publishCommand($device, 'ManualPushSnaps', [
            'TimeS' => $timeS,
            'TimeE' => $timeE,
        ]);
    }

    public function getHandSharkData(Device $device): array
    {
        return $this->mqttService->publishCommand($device, 'GetHandSharkData');
    }

    public function setHandSharkData(Device $device, array|string $params = []): array
    {
        return $this->mqttService->publishCommand($device, 'SetHandSharkData', is_array($params) ? $params : ['info' => $params]);
    }

    public function getCount(Device $device, int $type = 0, int $direction = 0): array
    {
        return $this->mqttService->publishCommand($device, 'GetCount', [
            'type' => $type,
            'direction' => $direction,
        ]);
    }

    public function getSysParam(Device $device): array
    {
        return $this->mqttService->getDeviceInformation($device);
    }

    public function setSysParam(Device $device, array $params = []): array
    {
        return $this->mqttService->publishCommand($device, 'UpSysParam', $params);
    }

    public function setFactoryDefault(Device $device, int $defaultNetPar = 0, int $defaultPerson = 1): array
    {
        return $this->mqttService->publishCommand($device, 'SetFactoryDefault', [
            'DefaltNetPar' => $defaultNetPar,
            'DefaltPerson' => $defaultPerson,
        ]);
    }

    public function subscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap'], ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1): array
    {
        return $this->mqttService->configureMqtt($device);
    }

    public function unsubscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap']): array
    {
        return ['success' => true, 'code' => 200];
    }

    public function getSubscribe(Device $device): array
    {
        return $this->mqttService->getMqttParam($device);
    }

    /**
     * Query personnel library from camera hardware and import/sync into system database via MQTT.
     */
    public function importPersonnelFromCamera(Device $device): array
    {
        $listResult = $this->searchPersonList($device, 0, 100);
        $listData = $listResult['data'] ?? $listResult;
        $info = $listData['info'] ?? $listData;

        $items = [];
        if (is_array($info)) {
            foreach ($info as $key => $val) {
                if (is_array($val) && (str_starts_with($key, 'Personinfo_') || isset($val['CustomizeID']) || isset($val['customId']))) {
                    $items[] = $val;
                }
            }
            if (empty($items) && array_is_list($info)) {
                $items = $info;
            }
        }

        if (empty($items)) {
            return [
                'success' => true,
                'code' => 200,
                'message' => 'No personnel records found on camera via MQTT.',
                'imported_count' => 0,
                'updated_count' => 0,
                'total_count' => 0,
                'personnel' => [],
            ];
        }

        $importedCount = 0;
        $updatedCount = 0;
        $importedRecords = [];
        $storageService = app(ImageStorageService::class);

        foreach ($items as $item) {
            $customizeId = (int) ($item['CustomizeID'] ?? $item['customId'] ?? $item['ID'] ?? $item['id'] ?? 0);
            if ($customizeId <= 0) {
                continue;
            }

            $name = trim((string) ($item['Name'] ?? $item['name'] ?? "Person {$customizeId}"));
            $personType = (int) ($item['PersonType'] ?? $item['personType'] ?? 0);
            $gender = (int) ($item['Gender'] ?? $item['gender'] ?? 0);
            $idCard = $item['IDCard'] ?? $item['idCard'] ?? null;
            $telNum = $item['TelNum'] ?? $item['telNum'] ?? $item['phone'] ?? null;
            $address = $item['Address'] ?? $item['address'] ?? null;

            // Check if photo is present or query single person record with Picture: 1
            $photoBase64 = $item['pic'] ?? $item['Pic'] ?? $item['picinfo'] ?? $item['picURI'] ?? $item['photo_base64'] ?? null;
            if (empty($photoBase64)) {
                try {
                    $personDetail = $this->searchPerson($device, (string) $customizeId, 0, 1);
                    $detailData = $personDetail['data'] ?? $personDetail;
                    $photoBase64 = $detailData['pic'] ?? $detailData['Pic'] ?? $detailData['info']['pic'] ?? $detailData['info']['picinfo'] ?? $detailData['info']['Pic'] ?? null;
                } catch (\Throwable $e) {
                    // Ignore single person photo search error
                }
            }

            $photoResult = null;
            if ($photoBase64) {
                $photoResult = $storageService->storeFromBase64($photoBase64, 'personnel');
            }

            // If camera firmware stores template-only without raw photo, fallback to verified edge snapshot
            if (empty($photoResult)) {
                $recentLog = \App\Models\AccessLog::where('customize_id', $customizeId)
                    ->orWhere('person_name', $name)
                    ->whereNotNull('snap_pic_url')
                    ->where('snap_pic_url', '!=', '')
                    ->latest('captured_at')
                    ->first();

                if ($recentLog && $recentLog->snap_pic_url) {
                    $photoResult = $storageService->storeFromUrlOrPath($recentLog->snap_pic_url, 'personnel');
                }
            }

            $existing = Personnel::where('customize_id', $customizeId)->first();
            $dataToSave = [
                'name' => $name,
                'person_type' => $personType,
                'gender' => $gender,
                'id_card' => $idCard,
                'tel_num' => $telNum,
                'address' => $address,
            ];

            if ($photoResult) {
                $dataToSave['photo_path'] = $photoResult['path'];
                $dataToSave['photo_base64'] = $photoResult['base64'];
            }

            if ($existing) {
                $existing->update($dataToSave);
                $updatedCount++;
                $importedRecords[] = $existing->fresh();
            } else {
                $dataToSave['customize_id'] = $customizeId;
                $newPerson = Personnel::create($dataToSave);
                $importedCount++;
                $importedRecords[] = $newPerson;
            }
        }

        return [
            'success' => true,
            'code' => 200,
            'message' => "Imported {$importedCount} new personnel and updated {$updatedCount} existing records from camera via MQTT.",
            'imported_count' => $importedCount,
            'updated_count' => $updatedCount,
            'total_count' => count($importedRecords),
            'personnel' => $importedRecords,
        ];
    }

    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand
    {
        return $this->mqttService->dispatchCommandAsync($device, $operator, $params);
    }

    public function getMqttService(): CameraMqttService
    {
        return $this->mqttService;
    }
}
