<?php

namespace App\Jobs;

use App\Models\BulkCampaign;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Services\AccessControlService;
use App\Services\CameraMqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkPersonnelSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public array $personnelIds = [],
        public ?int $campaignId = null,
        public string $action = 'sync', // 'sync' or 'delete'
        public ?int $targetDeviceId = null
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService, ?AccessControlService $accessControlService = null): void
    {
        $accessControlService = $accessControlService ?? app(AccessControlService::class);
        $campaign = $this->campaignId ? BulkCampaign::find($this->campaignId) : null;

        if ($campaign) {
            $campaign->markProcessing();
        }

        if (empty($this->personnelIds)) {
            if ($campaign) {
                $campaign->markCompleted('completed');
            }
            return;
        }

        if ($this->action === 'delete') {
            $this->handleBulkDelete($cameraService, $accessControlService, $campaign);
        } else {
            $this->handleBulkSync($cameraService, $accessControlService, $campaign);
        }
    }

    protected function handleBulkSync(CameraMqttService $cameraService, AccessControlService $accessControlService, ?BulkCampaign $campaign): void
    {
        $personnelRecords = Personnel::whereIn('id', $this->personnelIds)->get();

        // 1. Resolve target devices per personnel based on Access Control groups
        $deviceToPersonnelMap = []; // device_id => [Personnel...]

        foreach ($personnelRecords as $person) {
            if ($this->targetDeviceId) {
                $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
            } else {
                $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
            }

            foreach ($devices as $device) {
                $deviceToPersonnelMap[$device->id][] = $person;
            }
        }

        $processedIds = [];
        $failedIds = [];

        // 2. For each device, chunk personnel into packets of at most 50
        foreach ($deviceToPersonnelMap as $deviceId => $persons) {
            $device = Device::find($deviceId);
            if (!$device || !$device->is_active) {
                continue;
            }

            $chunks = array_chunk($persons, 50);

            foreach ($chunks as $chunk) {
                $payloadItems = [];
                foreach ($chunk as $person) {
                    $payloadItems[] = $cameraService->buildPersonnelInfo($person);
                }

                try {
                    $res = $cameraService->addPersons($device, $payloadItems);

                    foreach ($chunk as $person) {
                        SyncTask::create([
                            'device_id' => $device->device_id,
                            'personnel_id' => $person->id,
                            'action' => 'ADD',
                            'status' => !empty($res['success']) ? 'COMPLETED' : 'FAILED',
                            'attempts' => 1,
                            'error_message' => empty($res['success']) ? ($res['error'] ?? 'AddPersons failed') : null,
                        ]);

                        if (!empty($res['success'])) {
                            $processedIds[$person->id] = true;
                        } else {
                            $failedIds[$person->id] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error("BulkPersonnelSyncJob failed AddPersons to device #{$device->id}: " . $e->getMessage());
                    foreach ($chunk as $person) {
                        $failedIds[$person->id] = true;
                    }
                }
            }
        }

        // Account for personnel that had no active devices assigned
        foreach ($this->personnelIds as $id) {
            if (!isset($processedIds[$id]) && !isset($failedIds[$id])) {
                $processedIds[$id] = true; // completed with no-op
            }
        }

        if ($campaign) {
            $campaign->incrementProcessed(count($processedIds));
            if (!empty($failedIds)) {
                $campaign->incrementFailed(count($failedIds), "Failed to sync to one or more edge devices.");
            }
            $campaign->markCompleted();
        }
    }

    protected function handleBulkDelete(CameraMqttService $cameraService, AccessControlService $accessControlService, ?BulkCampaign $campaign): void
    {
        $personnelRecords = Personnel::whereIn('id', $this->personnelIds)->get();
        $deviceToCustomIdsMap = []; // device_id => [customize_id...]

        foreach ($personnelRecords as $person) {
            if ($this->targetDeviceId) {
                $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
            } else {
                $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
            }

            foreach ($devices as $device) {
                $deviceToCustomIdsMap[$device->id][] = (int) $person->customize_id;
            }
        }

        // 1. Dispatch deletion packets to edge devices
        foreach ($deviceToCustomIdsMap as $deviceId => $customIds) {
            $device = Device::find($deviceId);
            if (!$device || !$device->is_active) {
                continue;
            }

            $uniqueCustomIds = array_values(array_unique($customIds));
            try {
                $cameraService->deletePerson($device, $uniqueCustomIds);
            } catch (\Throwable $e) {
                Log::error("BulkPersonnelSyncJob: Error deleting from device {$device->device_id}: " . $e->getMessage());
            }
        }

        // 2. Remove personnel records from database
        $count = count($this->personnelIds);
        Personnel::whereIn('id', $this->personnelIds)->delete();

        if ($campaign) {
            $campaign->incrementProcessed($count);
            $campaign->markCompleted();
        }
    }
}
