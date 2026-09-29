<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
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

    public function __construct(
        public ?int $personnelId = null,
        public string $action = 'ADD', // 'ADD', 'EDIT', 'DELETE'
        public ?int $targetDeviceId = null,
        public ?int $customizeIdToDelete = null
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService): void
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
            $personnelIdForTask = $person ? $person->id : null;
            $cId = (int) ($this->customizeIdToDelete ?? ($person ? $person->customize_id : $this->personnelId));

            $task = SyncTask::create([
                'device_id' => $device->device_id,
                'personnel_id' => $personnelIdForTask,
                'action' => $this->action,
                'status' => 'PROCESSING',
                'attempts' => 1,
            ]);

            try {
                if ($this->action === 'DELETE') {
                    $payload = [
                        'operator' => 'DelPerson',
                        'info' => [
                            'facesluiceId' => $device->device_id,
                            'customId' => (string) $cId,
                            'CustomizeID' => [$cId],
                        ],
                    ];

                    Log::info("SyncPersonnelJob [SyncTask #{$task->id}]: Dispatching MQTT {$this->action} payload to device {$device->device_id}", [
                        'sync_task_id' => $task->id,
                        'device_id' => $device->device_id,
                        'personnel_id' => $this->personnelId,
                        'customize_id' => $cId,
                        'action' => $this->action,
                        'payload' => $payload,
                    ]);

                    $res = $cameraService->deletePerson($device, [$cId]);
                } else {
                    if (!$person) {
                        $task->update([
                            'status' => 'FAILED',
                            'error_message' => 'Personnel record not found',
                        ]);
                        Log::error("SyncPersonnelJob [SyncTask #{$task->id}]: Personnel record #{$this->personnelId} not found, aborting sync to device {$device->device_id}");
                        continue;
                    }

                    $info = $cameraService->buildPersonnelInfo($person);
                    $payload = [
                        'operator' => 'EditPerson',
                        'info' => array_merge([
                            'facesluiceId' => $device->device_id,
                        ], $info),
                    ];

                    $loggablePayload = $payload;
                    if (!empty($loggablePayload['info']['pic'])) {
                        $loggablePayload['info']['pic'] = '[Base64 Image: ' . strlen($loggablePayload['info']['pic']) . ' chars]';
                    }

                    Log::info("SyncPersonnelJob [SyncTask #{$task->id}]: Dispatching MQTT {$this->action} payload to device {$device->device_id}", [
                        'sync_task_id' => $task->id,
                        'device_id' => $device->device_id,
                        'personnel_id' => $this->personnelId,
                        'personnel_name' => $person->name,
                        'customize_id' => $person->customize_id,
                        'action' => $this->action,
                        'payload' => $loggablePayload,
                    ]);

                    $res = $cameraService->addOrUpdatePerson($device, $person);
                }

                if ($res['success']) {
                    $task->update([
                        'status' => 'COMPLETED',
                        'error_message' => null,
                    ]);
                    Log::info("SyncPersonnelJob [SyncTask #{$task->id}]: MQTT sync successful for person {$this->personnelId} ({$person?->name}) on device {$device->device_id}", [
                        'sync_task_id' => $task->id,
                        'device_id' => $device->device_id,
                        'response_code' => $res['code'] ?? 200,
                        'message_id' => $res['message_id'] ?? null,
                    ]);
                } else {
                    $task->update([
                        'status' => 'FAILED',
                        'error_message' => $res['error'] ?? "Error code {$res['code']}",
                    ]);
                    Log::warning("SyncPersonnelJob [SyncTask #{$task->id}]: MQTT sync failed for person {$this->personnelId} on device {$device->device_id}: " . ($res['error'] ?? 'Unknown'), [
                        'sync_task_id' => $task->id,
                        'device_id' => $device->device_id,
                        'error' => $res['error'] ?? null,
                        'response_code' => $res['code'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                $task->update([
                    'status' => 'FAILED',
                    'error_message' => $e->getMessage(),
                ]);
                Log::error("SyncPersonnelJob [SyncTask #{$task->id}]: Exception on device {$device->device_id}: " . $e->getMessage(), [
                    'sync_task_id' => $task->id,
                    'device_id' => $device->device_id,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
