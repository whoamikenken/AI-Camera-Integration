<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Resolve setting value with fallback from Organization-specific to Global default.
     */
    public static function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        if ($organizationId) {
            $orgCacheKey = "settings.org.{$organizationId}.{$key}";
            $cached = Cache::get($orgCacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $orgSetting = Setting::where('organization_id', $organizationId)
                ->where('key', $key)
                ->first();

            if ($orgSetting) {
                $val = $orgSetting->casted_value;
                Cache::put($orgCacheKey, $val, self::CACHE_TTL_SECONDS);

                return $val;
            }
        }

        // Fallback to Global setting
        $globalCacheKey = "settings.global.{$key}";
        $cachedGlobal = Cache::get($globalCacheKey);
        if ($cachedGlobal !== null) {
            return $cachedGlobal;
        }

        $globalSetting = Setting::whereNull('organization_id')
            ->where('key', $key)
            ->first();

        if ($globalSetting) {
            $val = $globalSetting->casted_value;
            Cache::put($globalCacheKey, $val, self::CACHE_TTL_SECONDS);

            return $val;
        }

        return $default;
    }

    /**
     * Set/update setting and invalidate cache.
     */
    public static function set(string $key, mixed $value, ?int $organizationId = null, ?string $type = null, ?string $description = null): Setting
    {
        $setting = Setting::firstOrNew([
            'organization_id' => $organizationId,
            'key' => $key,
        ]);

        if (! $setting->exists) {
            $parts = explode('.', $key);
            $setting->group = count($parts) > 1 ? $parts[0] : 'general';
            $setting->type = $type ?? self::inferType($value);
            if ($description) {
                $setting->description = $description;
            }
        } elseif ($type) {
            $setting->type = $type;
        }

        $setting->casted_value = $value;
        $setting->save();

        // Invalidate cache
        if ($organizationId) {
            Cache::forget("settings.org.{$organizationId}.{$key}");
        } else {
            Cache::forget("settings.global.{$key}");
        }
        Cache::forget('settings.public');

        return $setting;
    }

    /**
     * Retrieve all settings grouped by category with tenant overrides merged.
     */
    public static function getAllGrouped(?int $organizationId = null): array
    {
        $globals = Setting::whereNull('organization_id')->get();
        $orgSettings = $organizationId ? Setting::where('organization_id', $organizationId)->get()->keyBy('key') : collect();

        $grouped = [];

        foreach ($globals as $global) {
            $effective = $orgSettings->get($global->key, $global);
            $grouped[$effective->group][] = [
                'id' => $effective->id,
                'key' => $effective->key,
                'group' => $effective->group,
                'value' => $effective->casted_value,
                'type' => $effective->type,
                'description' => $effective->description,
                'is_public' => (bool) $effective->is_public,
                'is_tenant_override' => $effective->organization_id !== null,
            ];
        }

        return $grouped;
    }

    /**
     * Reset settings to global defaults for an organization.
     */
    public static function reset(?string $group = null, ?int $organizationId = null): void
    {
        $query = Setting::query();
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        if ($group !== null) {
            $query->where('group', $group);
        }

        $settings = $query->get();
        foreach ($settings as $setting) {
            if ($setting->organization_id) {
                Cache::forget("settings.org.{$setting->organization_id}.{$setting->key}");
                $setting->delete();
            } else {
                Cache::forget("settings.global.{$setting->key}");
            }
        }

        Cache::forget('settings.public');
    }

    public static function inferType(mixed $val): string
    {
        if (is_bool($val)) {
            return 'boolean';
        }
        if (is_int($val)) {
            return 'integer';
        }
        if (is_float($val)) {
            return 'float';
        }
        if (is_array($val)) {
            return 'json';
        }

        return 'string';
    }
}
