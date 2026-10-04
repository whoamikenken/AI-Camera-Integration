<?php

namespace App\Traits;

use App\Services\AuditService;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditService::record($model, 'create');
        });

        static::updated(function ($model) {
            AuditService::record($model, 'update');
        });

        static::deleted(function ($model) {
            AuditService::record($model, 'delete');
        });
    }

    public function getAuditExclude(): array
    {
        $defaultExclude = [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        return array_merge($defaultExclude, $this->auditExclude ?? []);
    }
}
