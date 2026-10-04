<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Personnel;
use App\Models\StrangerSnap;
use App\Models\SyncTask;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        $stats = Cache::remember('dashboard_telemetry_stats', 5, function () use ($today) {
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

            // Consolidated single query for device alerts
            $alertStats = DeviceAlert::toBase()
                ->selectRaw('count(case when captured_at >= ? then 1 end) as alerts_today', [$today])
                ->selectRaw('count(case when captured_at >= ? and severity = ? then 1 end) as critical_alerts_today', [$today, 'CRITICAL'])
                ->selectRaw('count(case when status in (?, ?) then 1 end) as unresolved_alerts', ['NEW', 'ACKNOWLEDGED'])
                ->first();

            $alertsToday = (int) ($alertStats->alerts_today ?? 0);
            $criticalAlertsToday = (int) ($alertStats->critical_alerts_today ?? 0);
            $unresolvedAlerts = (int) ($alertStats->unresolved_alerts ?? 0);

            // Optimize device queries into a single query
            $deviceStats = Device::toBase()
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when is_active = true and last_heartbeat_at >= ? then 1 else 0 end) as online', [now()->subSeconds(90)])
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

            return [
                'telemetry' => [
                    'total_scans_today' => $totalScansToday,
                    'allowed_today' => $allowedToday,
                    'rejected_today' => $rejectedToday,
                    'strangers_today' => $strangersToday,
                    'alerts_today' => $alertsToday,
                    'critical_alerts_today' => $criticalAlertsToday,
                    'unresolved_alerts' => $unresolvedAlerts,
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
            ];
        });

        return response()->json($stats);
    }
}
