<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Services\AccessControlService;
use App\Services\CameraMqttService;
use App\Services\CameraService;
use App\Services\CameraHttpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPersonnelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public ?int $personnelId = null;
    public ?Personnel $personnel = null;

    public function __construct(
        Personnel|int|null $personnel = null,
        public string $action = 'ADD', // 'ADD', 'EDIT', 'DELETE'
        public ?int $targetDeviceId = null,
        public ?int $customizeIdToDelete = null
    ) {
        if ($personnel instanceof Personnel) {
            $this->personnel = $personnel;
            $this->personnelId = $personnel->id;
        } else {
            $this->personnelId = $personnel;
            $this->personnel = $personnel ? Personnel::find($personnel) : null;
        }

        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService, ?AccessControlService $accessControlService = null): void
    {
        $accessControlService = $accessControlService ?? app(AccessControlService::class);
        $person = $this->personnelId ? Personnel::find($this->personnelId) : null;

        if ($this->targetDeviceId) {
            $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
        } elseif ($person) {
            $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
        } else {
            $devices = Device::where('is_active', true)->get();
        }

        if ($devices->isEmpty()) {
            Log::info("SyncPersonnelJob: No active devices found for personnel {$this->personnelId}");
            return;
        }

        $cId = (int) ($this->customizeIdToDelete ?? ($person ? $person->customize_id : $this->personnelId));

        foreach ($devices as $device) {
            SyncDevicePersonnelJob::dispatch(
                $device->id,
                $this->personnelId,
                $this->action,
                $cId
            );
        }
    }
}
