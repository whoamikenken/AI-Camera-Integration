<?php

namespace App\Jobs;

use App\Models\Visit;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireNoShowVisitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(?VisitorSyncService $visitorSyncService = null): void
    {
        $visitorSyncService = $visitorSyncService ?? app(VisitorSyncService::class);
        $startOfToday = Carbon::today()->startOfDay();

        $abandonedVisits = Visit::where('status', 'expected')
            ->whereNotNull('expected_arrival')
            ->where('expected_arrival', '<', $startOfToday)
            ->get();

        if ($abandonedVisits->isEmpty()) {
            return;
        }

        foreach ($abandonedVisits as $visit) {
            // Revoke any pre-provisioned edge face credentials
            if ($visit->personnel_id) {
                $visitorSyncService->revokeVisitorFace($visit);
            }

            $visit->update([
                'status' => 'no_show',
            ]);
        }

        Log::info("ExpireNoShowVisitsJob: Expired {$abandonedVisits->count()} abandoned visits to no_show status.");
    }
}
