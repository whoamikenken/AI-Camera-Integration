<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\StrangerSnap;
use App\Models\SyncTask;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        // Optimize access log queries into a single query
        $accessLogStats = AccessLog::toBase()
            ->where('captured_at', '>=', $today)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when verify_status = 1 then 1 else 0 end) as allowed')
            ->selectRaw('sum(case when verify_status = 2 then 1 else 0 end) as rejected')
            ->first();

        $totalScansToday = (int) ($accessLogStats->total ?? 0);
        $allowedToday = (int) ($accessLogStats->allowed ?? 0);
        $rejectedToday = (int) ($accessLogStats->rejected ?? 0);

        $strangersToday = StrangerSnap::where('captured_at', '>=', $today)->count();

        // Optimize device queries into a single query
        $deviceStats = Device::toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = 1 and last_heartbeat_at >= ? then 1 else 0 end) as online', [now()->subSeconds(90)])
            ->first();

        $totalDevices = (int) ($deviceStats->total ?? 0);
        $onlineDevices = (int) ($deviceStats->online ?? 0);

        // Optimize personnel queries into a single query
        $personnelStats = Personnel::toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when person_type = 0 then 1 else 0 end) as whitelisted')
            ->selectRaw('sum(case when person_type = 1 then 1 else 0 end) as blacklisted')
            ->first();

        $totalPersonnel = (int) ($personnelStats->total ?? 0);
        $whitelisted = (int) ($personnelStats->whitelisted ?? 0);
        $blacklisted = (int) ($personnelStats->blacklisted ?? 0);

        // Optimize sync task queries into a single query
        $syncTaskStats = SyncTask::toBase()
            ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as pending', ['PENDING', 'PROCESSING'])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', ['FAILED'])
            ->first();

        $pendingSyncs = (int) ($syncTaskStats->pending ?? 0);
        $failedSyncs = (int) ($syncTaskStats->failed ?? 0);

        return response()->json([
            'telemetry' => [
                'total_scans_today' => $totalScansToday,
                'allowed_today' => $allowedToday,
                'rejected_today' => $rejectedToday,
                'strangers_today' => $strangersToday,
            ],
            'devices' => [
                'total' => $totalDevices,
                'online' => $onlineDevices,
                'offline' => max(0, $totalDevices - $onlineDevices),
            ],
            'personnel' => [
                'total' => $totalPersonnel,
                'whitelisted' => $whitelisted,
                'blacklisted' => $blacklisted,
            ],
            'sync' => [
                'pending' => $pendingSyncs,
                'failed' => $failedSyncs,
            ],
        ]);
    }
}
