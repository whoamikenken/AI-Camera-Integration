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

        $totalScansToday = AccessLog::where('captured_at', '>=', $today)->count();
        $allowedToday = AccessLog::where('captured_at', '>=', $today)->where('verify_status', 1)->count();
        $rejectedToday = AccessLog::where('captured_at', '>=', $today)->where('verify_status', 2)->count();
        $strangersToday = StrangerSnap::where('captured_at', '>=', $today)->count();

        $totalDevices = Device::count();
        $onlineDevices = Device::where('is_active', true)
            ->where('last_heartbeat_at', '>=', now()->subSeconds(90))
            ->count();

        $totalPersonnel = Personnel::count();
        $whitelisted = Personnel::where('person_type', 0)->count();
        $blacklisted = Personnel::where('person_type', 1)->count();

        $pendingSyncs = SyncTask::where('status', 'PENDING')->orWhere('status', 'PROCESSING')->count();
        $failedSyncs = SyncTask::where('status', 'FAILED')->count();

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
