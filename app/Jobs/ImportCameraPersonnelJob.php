<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\CameraService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportCameraPersonnelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public static ?array $lastResult = null;

    public function __construct(
        public Device $device,
        public ?int $userId = null,
        public ?string $taskToken = null
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraService $cameraService): array
    {
        Log::info("ImportCameraPersonnelJob: Starting personnel import for camera {$this->device->device_id}", [
            'device_id' => $this->device->device_id,
            'user_id' => $this->userId,
            'task_token' => $this->taskToken,
        ]);

        try {
            $result = $cameraService->importPersonnelFromCamera($this->device);
            self::$lastResult = $result;

            Log::info("ImportCameraPersonnelJob: Successfully finished import for camera {$this->device->device_id}", [
                'device_id' => $this->device->device_id,
                'result' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error("ImportCameraPersonnelJob: Failed to import personnel from camera {$this->device->device_id}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
