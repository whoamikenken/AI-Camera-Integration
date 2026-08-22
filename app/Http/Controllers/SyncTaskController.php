<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPersonnelJob;
use App\Models\SyncTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SyncTask::with(['device', 'personnel']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($deviceId = $request->input('device_id')) {
            $query->where('device_id', $deviceId);
        }

        $tasks = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($tasks);
    }

    public function retry(SyncTask $syncTask): JsonResponse
    {
        $device = $syncTask->device;
        $targetDeviceId = $device ? $device->id : null;

        $syncTask->update([
            'status' => 'PENDING',
            'attempts' => $syncTask->attempts + 1,
            'error_message' => null,
        ]);

        SyncPersonnelJob::dispatch($syncTask->personnel_id, $syncTask->action, $targetDeviceId);

        return response()->json(['message' => 'Task queued for retry']);
    }
}
