<?php

namespace App\Http\Controllers;

use App\Models\BulkCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BulkCampaign::query()->with('user:id,name,email');

        if ($type = $request->input('campaign_type')) {
            $query->where('campaign_type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $campaigns = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json($campaigns);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = BulkCampaign::with('user:id,name,email')->findOrFail($id);

        return response()->json($campaign);
    }
}
