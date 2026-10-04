<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Personnel;
use App\Services\CameraMqttService;
use App\Services\ImageStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncCameraPersonnelCommand extends Command
{
    protected $signature = 'camera:sync-personnel {--device= : Specific device ID to sync}';
    protected $description = 'Periodically sync personnel records and face photos from connected camera devices into the system database';

    public function handle(CameraMqttService $mqttService, ImageStorageService $storageService): int
    {
        $targetDeviceId = $this->option('device');

        $query = Device::where('is_active', true);
        if ($targetDeviceId) {
            $query->where('device_id', $targetDeviceId);
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->info("No active devices found for personnel sync.");
            return Command::SUCCESS;
        }

        $this->info("Starting periodic personnel sync across " . $devices->count() . " active device(s)...");

        foreach ($devices as $device) {
            $this->syncDevicePersonnel($device, $mqttService, $storageService);
        }

        $this->info("Personnel sync completed successfully.");
        return Command::SUCCESS;
    }

    protected function syncDevicePersonnel(Device $device, CameraMqttService $mqttService, ImageStorageService $storageService): void
    {
        $deviceId = $device->device_id;
        $this->line("Processing device <fg=yellow>{$deviceId}</> ({$device->name})...");

        // 1. Query device for all registered custom IDs
        $queryRes = $mqttService->publishCommandAndWait($device, 'QueryPerson', [], [], 3.0);
        if (!$queryRes['success'] || empty($queryRes['data']['info'])) {
            $this->warn("Device {$deviceId} did not respond to QueryPerson or is offline.");
            return;
        }

        $qInfo = $queryRes['data']['info'];
        $rawIds = $qInfo['customId'] ?? $qInfo['CustomizeID'] ?? $qInfo['customIds'] ?? '';
        $idList = [];
        if (is_string($rawIds)) {
            $idList = array_values(array_filter(array_map('trim', explode(',', $rawIds))));
        } elseif (is_array($rawIds)) {
            $idList = array_values(array_filter(array_map('trim', array_map('strval', $rawIds))));
        }

        $edgeCount = count($idList);
        $this->info("Device {$deviceId} has {$edgeCount} person record(s) on edge.");

        // Cache the edge custom IDs roster
        Cache::put("camera_edge_roster:{$deviceId}", $idList, 86400);

        if (empty($idList)) {
            return;
        }

        $localMap = Personnel::whereIn('customize_id', array_map('intval', $idList))
            ->get()
            ->keyBy('customize_id');

        $imported = 0;
        $updated = 0;

        foreach ($idList as $cIdStr) {
            $cId = (int) $cIdStr;
            $existing = $localMap->get($cId);

            // Fetch details & photo if new or if existing record is missing photo
            $needPhoto = !$existing || empty($existing->photo_path);

            if ($needPhoto) {
                $detailRes = $mqttService->publishCommandAndWait($device, 'SearchPerson', [
                    'customId' => (string) $cId,
                    'SearchType' => 0,
                    'Picture' => 1,
                ], [], 3.0);

                $detailData = $detailRes['data'] ?? [];
                $pInfo = $detailData['info'] ?? [];
                $photoBase64 = $detailData['pic'] ?? $detailData['Pic'] ?? $pInfo['pic'] ?? $pInfo['picinfo'] ?? null;

                $photoResult = null;
                if ($photoBase64) {
                    $photoResult = $storageService->storeFromBase64($photoBase64, 'personnel');
                }

                // Fallback to recent access log snapshot if camera flash stores template-only
                if (empty($photoResult)) {
                    $recentLog = \App\Models\AccessLog::where('customize_id', $cId)
                        ->whereNotNull('snap_pic_url')
                        ->where('snap_pic_url', '!=', '')
                        ->latest('captured_at')
                        ->first();

                    if ($recentLog && $recentLog->snap_pic_url) {
                        $photoResult = $storageService->storeFromUrlOrPath($recentLog->snap_pic_url, 'personnel');
                    }
                }

                $name = trim((string) ($pInfo['name'] ?? $pInfo['Name'] ?? "Person {$cId}"));
                $personType = (int) ($pInfo['personType'] ?? $pInfo['PersonType'] ?? 0);
                $gender = (int) ($pInfo['gender'] ?? $pInfo['Gender'] ?? 0);
                $idCard = trim((string) ($pInfo['idCard'] ?? $pInfo['IDCard'] ?? ''));
                $telNum = trim((string) ($pInfo['telnum1'] ?? $pInfo['TelNum'] ?? ''));
                $address = trim((string) ($pInfo['address'] ?? $pInfo['Address'] ?? ''));

                $saveData = [
                    'name' => $name,
                    'person_type' => $personType,
                    'gender' => $gender,
                    'id_card' => $idCard,
                    'tel_num' => $telNum,
                    'address' => $address,
                ];

                if ($photoResult) {
                    $saveData['photo_path'] = $photoResult['path'];
                    $saveData['photo_base64'] = $photoResult['base64'];
                }

                if ($existing) {
                    $existing->update($saveData);
                    $updated++;
                } else {
                    $saveData['customize_id'] = $cId;
                    Personnel::create($saveData);
                    $imported++;
                }
            }
        }

        $this->info("Device {$deviceId}: Imported {$imported} new, updated {$updated} existing personnel.");
    }
}
