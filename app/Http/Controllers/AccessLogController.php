<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccessLog::with(['device', 'personnel']);

        if ($deviceId = $request->input('device_id')) {
            $query->where('device_id', $deviceId);
        }

        if ($request->has('verify_status') && $request->input('verify_status') !== '') {
            $query->where('verify_status', (int) $request->input('verify_status'));
        }

        if ($request->has('person_type') && $request->input('person_type') !== '') {
            $query->where('person_type', (int) $request->input('person_type'));
        }

        if ($minSim = $request->input('min_similarity')) {
            $query->where('similarity', '>=', (float) $minSim);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('person_name', 'ilike', "%{$search}%")
                  ->orWhere('device_id', 'ilike', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('customize_id', (int) $search);
                }
            });
        }

        if ($from = $request->input('from')) {
            $query->where('captured_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('captured_at', '<=', $to);
        }

        $logs = $query->orderBy('captured_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($logs);
    }

    public function show(AccessLog $accessLog): JsonResponse
    {
        return response()->json($accessLog->load(['device', 'personnel']));
    }
}
