<?php

namespace App\Http\Controllers;

use App\Models\StrangerSnap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrangerSnapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StrangerSnap::with('device');

        if ($deviceId = $request->input('device_id')) {
            $query->where('device_id', $deviceId);
        }

        if ($from = $request->input('from')) {
            $query->where('captured_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('captured_at', '<=', $to);
        }

        $snaps = $query->orderBy('captured_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($snaps);
    }

    public function show(StrangerSnap $strangerSnap): JsonResponse
    {
        return response()->json($strangerSnap->load('device'));
    }
}
