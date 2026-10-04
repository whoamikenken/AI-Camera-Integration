<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * List all settings grouped by category.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->has('organization_id') ? (int) $request->organization_id : null;
        $grouped = SettingService::getAllGrouped($orgId);

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /**
     * Public branding and general configuration (unauthenticated).
     */
    public function publicSettings(): JsonResponse
    {
        $publicSettings = Setting::whereNull('organization_id')
            ->where('is_public', true)
            ->get();

        $data = [];
        foreach ($publicSettings as $setting) {
            $data[$setting->key] = $setting->casted_value;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Bulk update settings (accepts key-value map or array of setting objects).
     */
    public function update(Request $request): JsonResponse
    {
        $orgId = $request->has('organization_id') ? (int) $request->organization_id : null;

        $payload = $request->all();
        $settingsData = $payload['settings'] ?? $payload;

        $updated = [];

        if (is_array($settingsData)) {
            // Check if sequential array of objects: [['key' => ..., 'value' => ...], ...]
            if (isset($settingsData[0]) && is_array($settingsData[0])) {
                foreach ($settingsData as $item) {
                    if (isset($item['key'])) {
                        $key = $item['key'];
                        $val = $item['value'] ?? null;
                        $type = $item['type'] ?? null;
                        $desc = $item['description'] ?? null;
                        $setting = SettingService::set($key, $val, $orgId, $type, $desc);
                        $updated[$key] = $setting->casted_value;
                    }
                }
            } else {
                // Associative map: ['key' => 'val', ...]
                foreach ($settingsData as $key => $val) {
                    if ($key === 'organization_id') {
                        continue;
                    }
                    $setting = SettingService::set($key, $val, $orgId);
                    $updated[$key] = $setting->casted_value;
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * Reset settings to global defaults.
     */
    public function reset(Request $request): JsonResponse
    {
        $orgId = $request->has('organization_id') ? (int) $request->organization_id : null;
        $group = $request->input('group');

        SettingService::reset($group, $orgId);

        return response()->json([
            'success' => true,
            'message' => 'Settings reset successfully.',
        ]);
    }

    /**
     * List filterable, paginated audit logs.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with(['user:id,name,email', 'organization:id,name,code'])
            ->orderByDesc('created_at');

        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }

        if ($request->has('auditable_type') && $request->auditable_type) {
            $type = $request->auditable_type;
            if (! str_contains($type, '\\')) {
                $type = "App\\Models\\{$type}";
            }
            $query->where('auditable_type', 'like', "%{$request->auditable_type}%");
        }

        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->has('search') && $request->search) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('auditable_id', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Show single audit log entry.
     */
    public function showAuditLog(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load(['user', 'organization']);

        return response()->json([
            'success' => true,
            'data' => $auditLog,
        ]);
    }
}
