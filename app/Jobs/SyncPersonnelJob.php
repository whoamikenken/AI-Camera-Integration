<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
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

    public function __construct(
        public int $personnelId,
        public string $action = 'ADD', // 'ADD', 'EDIT', 'DELETE'
        public ?int $targetDeviceId = null,
        public ?int $customizeIdToDelete = null
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraHttpService $cameraService): void
    {
        $person = Personnel::find($this->personnelId);
        $devices = $this->targetDeviceId
            ? Device::where('id', $this->targetDeviceId)->where('is_active', true)->get()
            : Device::where('is_active', true)->get();

        if ($devices->isEmpty()) {
            Log::info("SyncPersonnelJob: No active devices found for personnel {$this->personnelId}");
            return;
        }

        foreach ($devices as $device) {
            $task = SyncTask::create([
                'device_id' => $device->device_id,
                'personnel_id' => $this->personnelId,
                'action' => $this->action,
                'status' => 'PROCESSING',
                'attempts' => 1,
            ]);

            try {
                if ($this->action === 'DELETE') {
                    $cId = $this->customizeIdToDelete ?? ($person ? $person->customize_id : $this->personnelId);
                    $res = $cameraService->deletePerson($device, [$cId]);
                } else {
                    if (!$person) {
                        $task->update([
                            'status' => 'FAILED',
                            'error_message' => 'Personnel record not found',
                        ]);
                        continue;
                    }
                    $res = $cameraService->addOrUpdatePerson($device, $person);
                }

                if ($res['success']) {
                    $task->update([
                        'status' => 'COMPLETED',
                        'error_message' => null,
                    ]);
                    Log::info("SyncPersonnelJob successful for person {$this->personnelId} on device {$device->device_id}");
                } else {
                    $task->update([
                        'status' => 'FAILED',
                        'error_message' => $res['error'] ?? "Error code {$res['code']}",
                    ]);
                    Log::warning("SyncPersonnelJob failed for person {$this->personnelId} on device {$device->device_id}: " . ($res['error'] ?? 'Unknown'));
                }
            } catch (\Throwable $e) {
                $task->update([
                    'status' => 'FAILED',
                    'error_message' => $e->getMessage(),
                ]);
                Log::error("SyncPersonnelJob exception on device {$device->device_id}: " . $e->getMessage());
            }
        }
    }
}
