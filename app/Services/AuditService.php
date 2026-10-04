<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class AuditService
{
    /**
     * Automatically called by the Auditable trait on Eloquent model lifecycle events.
     */
    public static function record(Model $model, string $action): ?AuditLog
    {
        try {
            $exclude = method_exists($model, 'getAuditExclude') ? $model->getAuditExclude() : [];
            $oldValues = null;
            $newValues = null;

            if ($action === 'create') {
                $newValues = array_diff_key($model->getAttributes(), array_flip($exclude));
            } elseif ($action === 'update') {
                $dirty = $model->getDirty();
                $newValues = array_diff_key($dirty, array_flip($exclude));
                if (empty($newValues)) {
                    return null; // Nothing audited changed
                }
                $oldValues = array_intersect_key($model->getOriginal(), $newValues);
            } elseif ($action === 'delete') {
                $oldValues = array_diff_key($model->getAttributes(), array_flip($exclude));
            }

            $user = Auth::user();
            $orgId = $model->organization_id ?? ($user?->organization_id ?? null);

            return AuditLog::create([
                'user_id' => $user?->id,
                'organization_id' => $orgId,
                'action' => $action,
                'auditable_type' => get_class($model),
                'auditable_id' => (string) $model->getKey(),
                'description' => "{$action} on ".class_basename($model)." #{$model->getKey()}",
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit logging failed: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Programmatic manual audit log entry (e.g. for login, HR override, hardware sync).
     */
    public static function log(
        string $action,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $orgId = null
    ): ?AuditLog {
        try {
            $user = Auth::user();
            $organizationId = $orgId ?? ($auditable->organization_id ?? ($user?->organization_id ?? null));

            return AuditLog::create([
                'user_id' => $user?->id,
                'organization_id' => $organizationId,
                'action' => $action,
                'auditable_type' => $auditable ? get_class($auditable) : null,
                'auditable_id' => $auditable ? (string) $auditable->getKey() : null,
                'description' => $description,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual audit log failed: '.$e->getMessage());

            return null;
        }
    }
}
