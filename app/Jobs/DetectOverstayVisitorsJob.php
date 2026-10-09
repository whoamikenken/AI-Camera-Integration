<?php

namespace App\Jobs;

use App\Events\DeviceAlertReceived;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectOverstayVisitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // 15-minute grace threshold before flagging as overstayed
        $cutoff = Carbon::now()->subMinutes(15);

        $overstayedVisits = Visit::where('status', 'checked_in')
            ->whereNotNull('expected_departure')
            ->where('expected_departure', '<=', $cutoff)
            ->whereNull('overstay_alerted_at')
            ->with(['visitor', 'host'])
            ->get();

        if ($overstayedVisits->isEmpty()) {
            return;
        }

        foreach ($overstayedVisits as $visit) {
            $visit->update([
                'status' => 'overstayed',
                'overstay_alerted_at' => now(),
            ]);

            // Determine target camera device ID for DeviceAlert
            $deviceId = $visit->device_id
                ?? ($visit->personnel_id ? \App\Models\AccessLog::where('customize_id', $visit->personnel?->customize_id)->latest('captured_at')->value('device_id') : null)
                ?? Device::where('is_active', true)->value('device_id')
                ?? Device::value('device_id');

            if ($deviceId) {
                $visitorName = $visit->visitor ? $visit->visitor->name : "Visitor #{$visit->id}";
                $departureFormatted = $visit->expected_departure ? $visit->expected_departure->format('H:i') : 'N/A';

                $alert = DeviceAlert::create([
                    'device_id' => $deviceId,
                    'alert_type' => 'visitor_overstay',
                    'operator' => 'DetectOverstayVisitorsJob',
                    'severity' => 'WARNING',
                    'title' => "Visitor Overstay: {$visitorName}",
                    'description' => "Visitor {$visitorName} (Badge: {$visit->badge_number}) exceeded expected departure time of {$departureFormatted}.",
                    'details' => [
                        'visit_id' => $visit->id,
                        'visitor_id' => $visit->visitor_id,
                        'visitor_name' => $visitorName,
                        'badge_number' => $visit->badge_number,
                        'expected_departure' => $visit->expected_departure?->toISOString(),
                        'host_employee_id' => $visit->host_employee_id,
                    ],
                    'status' => 'NEW',
                    'captured_at' => now(),
                ]);

                try {
                    broadcast(new DeviceAlertReceived($alert));
                } catch (\Throwable $e) {
                    Log::warning("Failed to broadcast DeviceAlertReceived for overstay visit #{$visit->id}: " . $e->getMessage());
                }
            }
        }

        Log::info("DetectOverstayVisitorsJob: Flagged {$overstayedVisits->count()} overstayed visits.");
    }
}
