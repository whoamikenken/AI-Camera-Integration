<?php

namespace App\Jobs;

use App\Models\BulkCampaign;
use App\Models\Device;
use App\Services\CameraMqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkDeviceCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public int $campaignId
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService): void
    {
        $campaign = BulkCampaign::find($this->campaignId);
        if (!$campaign) {
            Log::error("BulkDeviceCampaignJob: Campaign #{$this->campaignId} not found.");
            return;
        }

        $campaign->markProcessing();

        $payload = $campaign->payload ?? [];
        $deviceIds = $payload['device_ids'] ?? [];

        if (empty($deviceIds)) {
            $campaign->markCompleted('completed');
            return;
        }

        $isUnitTest = app()->runningUnitTests();

        foreach ($deviceIds as $deviceId) {
            try {
                $device = Device::find($deviceId);
                if (!$device) {
                    $campaign->incrementFailed(1, "Device #{$deviceId} not found.");
                    continue;
                }

                if (!$device->is_active) {
                    $campaign->incrementFailed(1, "Device #{$deviceId} ({$device->name}) is inactive.");
                    continue;
                }

                $res = null;
                if ($campaign->campaign_type === 'reboot_fleet') {
                    $res = $cameraService->rebootDevice($device);
                } elseif ($campaign->campaign_type === 'update_mqtt_config') {
                    $mqttConfig = $payload['mqtt_config'] ?? [];
                    $res = $cameraService->configureMqtt($device, $mqttConfig);
                    if (!empty($res['success']) && !empty($mqttConfig['MQTopic'])) {
                        $device->update(['mqtt_topic' => $mqttConfig['MQTopic']]);
                    }
                } else {
                    $campaign->incrementFailed(1, "Unknown campaign type: {$campaign->campaign_type}");
                    continue;
                }

                if (!empty($res['success'])) {
                    $campaign->incrementProcessed(1);
                } else {
                    $err = $res['error'] ?? "Failed command on device #{$deviceId}";
                    $campaign->incrementFailed(1, (string) $err);
                }

                // Rate limiting between device dispatches to prevent broker congestion
                if (!$isUnitTest) {
                    usleep(50000); // 50ms pause
                }
            } catch (\Throwable $e) {
                Log::error("BulkDeviceCampaignJob error on device #{$deviceId}: " . $e->getMessage());
                $campaign->incrementFailed(1, "Exception on device #{$deviceId}: " . $e->getMessage());
            }
        }

        $campaign->markCompleted();
    }
}
