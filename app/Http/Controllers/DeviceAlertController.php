<?php

namespace App\Http\Controllers;

use App\Events\DeviceAlertUpdated;
use App\Models\DeviceAlert;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DeviceAlert::with(['device', 'resolvedBy']);

        if ($deviceId = $request->input('device_id')) {
            $query->where('device_id', $deviceId);
        }

        if ($alertType = $request->input('alert_type')) {
            $query->where('alert_type', $alertType);
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->where('captured_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('captured_at', '<=', $to);
        }

        $alerts = $query->orderBy('captured_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($alerts);
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = \Illuminate\Support\Facades\Cache::remember('device_alert_stats', 5, function () {
            $today = Carbon::today();

            $aggregates = DeviceAlert::selectRaw("
                SUM(CASE WHEN captured_at >= ? THEN 1 ELSE 0 END) as total_today,
                SUM(CASE WHEN captured_at >= ? AND severity = 'CRITICAL' THEN 1 ELSE 0 END) as critical_today,
                SUM(CASE WHEN captured_at >= ? AND severity = 'WARNING' THEN 1 ELSE 0 END) as warning_today,
                SUM(CASE WHEN status = 'NEW' THEN 1 ELSE 0 END) as unacknowledged,
                SUM(CASE WHEN captured_at >= ? AND status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_today
            ", [$today, $today, $today, $today])->first();

            $byType = DeviceAlert::where('captured_at', '>=', $today)
                ->selectRaw('alert_type, count(*) as count')
                ->groupBy('alert_type')
                ->pluck('count', 'alert_type');

            return [
                'total_today' => (int) ($aggregates->total_today ?? 0),
                'critical_today' => (int) ($aggregates->critical_today ?? 0),
                'warning_today' => (int) ($aggregates->warning_today ?? 0),
                'unacknowledged' => (int) ($aggregates->unacknowledged ?? 0),
                'resolved_today' => (int) ($aggregates->resolved_today ?? 0),
                'by_type' => $byType,
            ];
        });

        return response()->json($stats);
    }

    public function show(DeviceAlert $deviceAlert): JsonResponse
    {
        return response()->json($deviceAlert->load(['device', 'resolvedBy']));
    }

    public function updateStatus(Request $request, DeviceAlert $deviceAlert): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:NEW,ACKNOWLEDGED,RESOLVED,DISMISSED',
        ]);

        $previousStatus = $deviceAlert->status;
        $updateData = ['status' => $validated['status']];

        if ($validated['status'] === 'RESOLVED' || $validated['status'] === 'ACKNOWLEDGED') {
            $updateData['resolved_at'] = now();
            $updateData['resolved_by'] = auth()->id();
        }

        $deviceAlert->update($updateData);

        broadcast(new DeviceAlertUpdated($deviceAlert, $previousStatus));

        return response()->json($deviceAlert->load(['device', 'resolvedBy']));
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:device_alerts,id',
            'status' => 'required|string|in:NEW,ACKNOWLEDGED,RESOLVED,DISMISSED',
        ]);

        $updateData = ['status' => $validated['status']];
        if ($validated['status'] === 'RESOLVED' || $validated['status'] === 'ACKNOWLEDGED') {
            $updateData['resolved_at'] = now();
            $updateData['resolved_by'] = auth()->id();
        }

        $alerts = DeviceAlert::whereIn('id', $validated['ids'])->get();
        foreach ($alerts as $alert) {
            $previousStatus = $alert->status;
            $alert->update($updateData);
            broadcast(new DeviceAlertUpdated($alert, $previousStatus));
        }

        return response()->json([
            'success' => true,
            'message' => count($validated['ids']) . ' alerts updated to ' . $validated['status'],
        ]);
    }
}
