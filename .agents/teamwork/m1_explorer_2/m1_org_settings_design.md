# Milestone 1: Organization Hierarchy, Settings & Audit Trail — Implementation Blueprint

**Document Version:** 1.0.0  
**Author:** Explorer 2 (`m1_explorer_2`)  
**Status:** Approved for Implementation  
**Target Milestone:** Milestone 1 (Features 5, 7, 8 & Safe Device Extension §11.1)

---

## Executive Summary

This blueprint provides the comprehensive architectural and implementation design for:
1. **Organization Hierarchy Subsystem**: Multi-tenant organizations, physical locations/sites, recursive department trees with parent/head links, and job designations/seniority levels.
2. **Safe Device Extension**: Zero-breaking migration extending the `devices` table with nullable `organization_id`, `location_id`, and `device_role` (`entry`, `exit`, `bidirectional`, `visitor_kiosk`), ensuring 100% backward compatibility with edge cameras and existing test suites.
3. **Global System Settings Subsystem**: Strongly-typed key-value configuration engine with organization-level scoping, fallback resolution, fast cache invalidation, and default seeders.
4. **Comprehensive Audit Trail Subsystem**: Polymorphic, immutable audit logging with Eloquent model event observation (`Auditable` trait), differential attribute capture, actor tracking, and filterable log inspection API.
5. **Controllers & RESTful APIs**: Fully documented endpoints, validation request schemas, and hierarchical tree generators for `OrganizationController` and `SettingController`.

---

## 1. Domain Architecture & Schema Specifications

### 1.1 Topological Migration Order

To prevent circular dependency errors and foreign key failures on both PostgreSQL 16 and SQLite `:memory:`, migrations must follow this exact order:

```
Step 1: create_organizations_table.php                 (No dependencies)
Step 2: create_locations_table.php                     (Depends on organizations)
Step 3: create_departments_table.php                   (Depends on organizations; parent_id self-ref; head_id unconstrained in M1)
Step 4: create_designations_table.php                  (Depends on organizations)
Step 5: add_organization_and_role_to_devices_table.php (Extends devices; depends on organizations, locations)
Step 6: create_settings_table.php                      (Depends on organizations)
Step 7: create_audit_logs_table.php                    (Depends on users, organizations; polymorphic to all models)
```

> **Critical Chicken-and-Egg Invariant (`head_id` in `departments`):**  
> In Milestone 1, the `employees` table does not exist yet (scheduled for Milestone 2). Defining `$table->foreign('head_id')->references('id')->on('employees')` would crash migration execution. Therefore, `departments.head_id` is defined as `$table->unsignedBigInteger('head_id')->nullable()->index();`. Eloquent relationship `head()` functions seamlessly, and the foreign key constraint can be added in M2 once `employees` is migrated.

---

### 1.2 Table Schemas & Migrations

#### A. Organizations Table (`database/migrations/2026_09_30_000001_create_organizations_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->index();
            $table->string('code', 64)->unique();
            $table->string('logo', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
```

#### B. Locations Table (`database/migrations/2026_09_30_000002_create_locations_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('code', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->nullable()->comment('Null inherits from organization');
            $table->string('coordinates', 64)->nullable()->comment('lat,lng e.g. 14.5547,121.0244');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
```

#### C. Departments Table (`database/migrations/2026_09_30_000003_create_departments_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('code', 64)->nullable();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();
            // Unconstrained in M1 to prevent chicken-and-egg dependency before employees table exists
            $table->unsignedBigInteger('head_id')->nullable()->index()->comment('FK to employees.id in M2');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
```

#### D. Designations Table (`database/migrations/2026_09_30_000004_create_designations_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('code', 64)->nullable();
            $table->integer('level')->default(1)->index()->comment('Seniority ranking: 1=Junior, 5=Manager, 10=Executive');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
```

#### E. Safe Device Extension (`database/migrations/2026_09_30_000007_add_organization_and_role_to_devices_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->nullOnDelete();

            $table->foreignId('location_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('locations')
                ->nullOnDelete();

            $table->string('device_role', 32)
                ->default('bidirectional')
                ->after('device_type')
                ->comment('entry, exit, bidirectional, visitor_kiosk');

            $table->json('department_ids')
                ->nullable()
                ->after('device_role')
                ->comment('Optional array of department IDs for camera zoning');

            $table->index(['organization_id', 'location_id']);
            $table->index('device_role');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['organization_id', 'location_id', 'device_role', 'department_ids']);
        });
    }
};
```

#### F. Settings Table (`database/migrations/2026_09_30_000020_create_settings_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->cascadeOnDelete()
                ->comment('NULL represents global system default; non-null is tenant override');
            $table->string('group', 64)->default('general')->index();
            $table->string('key', 128)->index();
            $table->text('value')->nullable();
            $table->string('type', 32)->default('string')->comment('boolean, integer, float, string, json');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->timestampsTz();

            $table->index(['organization_id', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

#### G. Audit Logs Table (`database/migrations/2026_09_30_000021_create_audit_logs_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('action', 64)->index()->comment('create, update, delete, login, logout, override, approve, reject, sync');
            $table->string('auditable_type', 128)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
```

---

## 2. Model Definitions & Eloquent Architecture

### 2.1 `App\Models\Organization.php`

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'code',
        'logo',
        'address',
        'timezone',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function settingsList(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
```

### 2.2 `App\Models\Location.php`

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address',
        'timezone',
        'coordinates',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function getEffectiveTimezoneAttribute(): string
    {
        return $this->timezone ?: ($this->organization?->timezone ?: config('app.timezone', 'Asia/Manila'));
    }
}
```

### 2.3 `App\Models\Department.php` (Tree Hierarchy Engine)

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'parent_id',
        'head_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->with('children');
    }

    /**
     * Recursively retrieve all descendant IDs (sub-departments).
     * Prevents circular parent-child loops during updates.
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        $children = Department::where('parent_id', $this->id)->get();

        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }

    /**
     * Retrieve ancestral chain up to the root department.
     */
    public function getAncestors(): Collection
    {
        $ancestors = new Collection();
        $curr = $this->parent;

        while ($curr) {
            $ancestors->push($curr);
            $curr = $curr->parent;
        }

        return $ancestors;
    }
}
```

### 2.4 `App\Models\Designation.php`

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'level',
        'description',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

### 2.5 Extended `App\Models\Device.php` (Preserving 100% Invariants)

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory, Auditable;

    public const ROLE_ENTRY = 'entry';
    public const ROLE_EXIT = 'exit';
    public const ROLE_BIDIRECTIONAL = 'bidirectional';
    public const ROLE_VISITOR_KIOSK = 'visitor_kiosk';

    public const ROLES = [
        self::ROLE_ENTRY,
        self::ROLE_EXIT,
        self::ROLE_BIDIRECTIONAL,
        self::ROLE_VISITOR_KIOSK,
    ];

    protected $fillable = [
        'device_id',
        'name',
        'scheme',
        'ip_address',
        'port',
        'username',
        'password',
        'device_type',
        'mqtt_topic',
        'is_active',
        'last_heartbeat_at',
        // Extended attributes:
        'organization_id',
        'location_id',
        'device_role',
        'department_ids',
    ];

    protected $casts = [
        'port' => 'integer',
        'device_type' => 'integer',
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'department_ids' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'device_id', 'device_id');
    }

    public function strangerSnaps(): HasMany
    {
        return $this->hasMany(StrangerSnap::class, 'device_id', 'device_id');
    }

    public function syncTasks(): HasMany
    {
        return $this->hasMany(SyncTask::class, 'device_id', 'device_id');
    }

    public function getEndpointUrlAttribute(): string
    {
        $scheme = $this->scheme ?: 'http';
        $host = preg_replace('#^https?://#i', '', rtrim($this->ip_address, '/'));
        $portStr = ($scheme === 'https' && $this->port == 443) || ($scheme === 'http' && $this->port == 80)
            ? ''
            : ":{$this->port}";

        return "{$scheme}://{$host}{$portStr}";
    }

    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->diffInSeconds(now()) <= 90;
    }
}
```

### 2.6 `App\Models\Setting.php` (Type Casting Engine)

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'organization_id',
        'group',
        'key',
        'value',
        'type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getCastedValueAttribute(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean', 'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $this->value,
            'float', 'double' => (float) $this->value,
            'json', 'array' => json_decode($this->value, true),
            default => (string) $this->value,
        };
    }

    public function setCastedValueAttribute(mixed $val): void
    {
        if ($val === null) {
            $this->attributes['value'] = null;
            return;
        }

        $this->attributes['value'] = match ($this->type) {
            'boolean', 'bool' => $val ? '1' : '0',
            'json', 'array' => is_string($val) ? $val : json_encode($val),
            default => (string) $val,
        };
    }
}
```

### 2.7 `App\Models\AuditLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'organization_id',
        'action',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

---

## 3. Subsystem Services & Infrastructure

### 3.1 `App\Traits\Auditable.php`

```php
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
```

### 3.2 `App\Services\AuditService.php`

```php
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
                'description' => "{$action} on " . class_basename($model) . " #{$model->getKey()}",
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit logging failed: ' . $e->getMessage(), ['exception' => $e]);
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
            Log::error('Manual audit log failed: ' . $e->getMessage());
            return null;
        }
    }
}
```

### 3.3 `App\Services\SettingService.php` (Cached Key-Value Engine)

```php
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
    public static function set(string $key, mixed $value, ?int $organizationId = null): Setting
    {
        $setting = Setting::firstOrNew([
            'organization_id' => $organizationId,
            'key' => $key,
        ]);

        if (!$setting->exists) {
            // Infer group and type from key or defaults
            $parts = explode('.', $key);
            $setting->group = count($parts) > 1 ? $parts[0] : 'general';
            $setting->type = self::inferType($value);
        }

        $setting->casted_value = $value;
        $setting->save();

        // Invalidate cache
        if ($organizationId) {
            Cache::forget("settings.org.{$organizationId}.{$key}");
        } else {
            Cache::forget("settings.global.{$key}");
        }

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
                'is_public' => $effective->is_public,
                'is_tenant_override' => $effective->organization_id !== null,
            ];
        }

        return $grouped;
    }

    private static function inferType(mixed $val): string
    {
        if (is_bool($val)) return 'boolean';
        if (is_int($val)) return 'integer';
        if (is_float($val)) return 'float';
        if (is_array($val)) return 'json';
        return 'string';
    }
}
```

---

## 4. Default Seeders (`SettingsSeeder.php`)

`database/seeders/SettingsSeeder.php` populates the standard baseline settings required across all subsequent phases:

```php
<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Attendance Group
            [
                'group' => 'attendance',
                'key' => 'attendance.auto_process',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Automatically pair camera verification punches into daily attendance records',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.late_grace_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'Global grace period in minutes before clock-in is flagged as Late',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.early_out_threshold_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'Threshold in minutes before shift end flagged as Early Out',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.overtime_threshold_minutes',
                'value' => '30',
                'type' => 'integer',
                'description' => 'Minimum minutes worked beyond shift to count towards overtime',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.min_hours_full_day',
                'value' => '8.0',
                'type' => 'float',
                'description' => 'Minimum net work hours required for Full-Day Present status',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.half_day_threshold_hours',
                'value' => '4.0',
                'type' => 'float',
                'description' => 'Hours threshold below which a day is counted as Half-Day',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.weekend_days',
                'value' => json_encode([0, 6]),
                'type' => 'json',
                'description' => 'Designated weekend days (0 = Sunday, 6 = Saturday)',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.punch_debounce_minutes',
                'value' => '3',
                'type' => 'integer',
                'description' => 'Ignore duplicate clock punches within this time window',
                'is_public' => false,
            ],

            // Visitor Management Group
            [
                'group' => 'visitor',
                'key' => 'visitor.require_photo',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Mandatory visitor facial capture during check-in',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.require_nda',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Require electronic NDA acknowledgment before badge issuance',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.auto_checkout_time',
                'value' => '23:59:59',
                'type' => 'string',
                'description' => 'Time of day when un-checked-out visits are automatically closed',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.max_visit_duration_hours',
                'value' => '8',
                'type' => 'integer',
                'description' => 'Maximum allowed stay duration for visitor passes',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.enroll_face_to_camera',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Automatically sync visitor biometric template to edge cameras upon check-in',
                'is_public' => false,
            ],

            // Notification Group
            [
                'group' => 'notification',
                'key' => 'notification.email_enabled',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Enable outbound SMTP email notifications',
                'is_public' => false,
            ],
            [
                'group' => 'notification',
                'key' => 'notification.sms_enabled',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Enable SMS alert dispatch',
                'is_public' => false,
            ],
            [
                'group' => 'notification',
                'key' => 'notification.late_alert_enabled',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Dispatch push/in-app alert to employee upon late arrival',
                'is_public' => false,
            ],

            // System Group
            [
                'group' => 'system',
                'key' => 'system.company_name',
                'value' => 'Intelligent AI Camera Hub',
                'type' => 'string',
                'description' => 'Organization/System Display Name',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.timezone',
                'value' => 'Asia/Manila',
                'type' => 'string',
                'description' => 'Primary system operational timezone',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.date_format',
                'value' => 'YYYY-MM-DD',
                'type' => 'string',
                'description' => 'Frontend date display format',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.time_format',
                'value' => '24h',
                'type' => 'string',
                'description' => 'Frontend time display format (12h or 24h)',
                'is_public' => true,
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['organization_id' => null, 'key' => $setting['key']],
                $setting
            );
        }
    }
}
```

---

## 5. Controllers & RESTful API Specifications

### 5.1 `OrganizationController` (`app/Http/Controllers/OrganizationController.php`)

Handles organizations, locations, departments (with tree structure), and designations.

#### Endpoint Inventory

| Method | URI | Description | Auth / Permission |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/organizations` | List all organizations | `auth:sanctum` |
| `POST` | `/api/organizations` | Create organization | `auth:sanctum`, `org.manage` |
| `GET` | `/api/organizations/{id}` | Show organization details & stats | `auth:sanctum` |
| `PUT` | `/api/organizations/{id}` | Update organization | `auth:sanctum`, `org.manage` |
| `DELETE` | `/api/organizations/{id}` | Soft delete organization | `auth:sanctum`, `org.manage` |
| `GET` | `/api/locations` | List locations (filterable by `organization_id`) | `auth:sanctum` |
| `POST` | `/api/locations` | Create location | `auth:sanctum`, `org.manage` |
| `GET` | `/api/locations/{id}` | Show location with assigned devices | `auth:sanctum` |
| `PUT` | `/api/locations/{id}` | Update location | `auth:sanctum`, `org.manage` |
| `DELETE` | `/api/locations/{id}` | Delete location (guarded if devices attached) | `auth:sanctum`, `org.manage` |
| `GET` | `/api/departments` | Flat list of departments with search | `auth:sanctum` |
| `GET` | `/api/departments/tree` | Hierarchical nested tree (`children: []`) | `auth:sanctum` |
| `POST` | `/api/departments` | Create department | `auth:sanctum`, `org.manage` |
| `GET` | `/api/departments/{id}` | Show department | `auth:sanctum` |
| `PUT` | `/api/departments/{id}` | Update department (with circular loop check) | `auth:sanctum`, `org.manage` |
| `DELETE` | `/api/departments/{id}` | Delete department (guarded if children exist) | `auth:sanctum`, `org.manage` |
| `GET` | `/api/designations` | List designations sorted by `level` | `auth:sanctum` |
| `POST` | `/api/designations` | Create designation | `auth:sanctum`, `org.manage` |
| `GET` | `/api/designations/{id}` | Show designation | `auth:sanctum` |
| `PUT` | `/api/designations/{id}` | Update designation | `auth:sanctum`, `org.manage` |
| `DELETE` | `/api/designations/{id}` | Delete designation | `auth:sanctum`, `org.manage` |

#### Department Tree Response Schema (`GET /api/departments/tree`)

```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "organization_id": 1,
      "name": "Engineering",
      "code": "ENG",
      "parent_id": null,
      "head_id": null,
      "is_active": true,
      "children": [
        {
          "id": 2,
          "organization_id": 1,
          "name": "AI Vision Core",
          "code": "ENG-AI",
          "parent_id": 1,
          "head_id": null,
          "is_active": true,
          "children": []
        }
      ]
    }
  ]
}
```

#### Circular Hierarchy Validation Logic
```php
if ($request->has('parent_id') && $request->parent_id) {
    if ($department->id == $request->parent_id) {
        return response()->json(['message' => 'A department cannot be its own parent.'], 422);
    }
    if (in_array($request->parent_id, $department->getDescendantIds())) {
        return response()->json(['message' => 'Circular dependency detected: cannot set parent to one of its sub-departments.'], 422);
    }
}
```

---

### 5.2 `SettingController` (`app/Http/Controllers/SettingController.php`)

Handles key-value settings management and the audit log browser.

#### Endpoint Inventory

| Method | URI | Description | Auth / Permission |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/settings` | List all settings grouped by category | `auth:sanctum` |
| `GET` | `/api/settings/public` | Public settings (branding, timezone, formats) | Unauthenticated |
| `PUT` | `/api/settings` | Bulk update settings key-value payload | `auth:sanctum`, `settings.manage` |
| `POST` | `/api/settings/reset` | Reset keys/groups to default seeded values | `auth:sanctum`, `settings.manage` |
| `GET` | `/api/audit-logs` | Paginated audit trail with multi-filter query | `auth:sanctum`, `audit.view` |
| `GET` | `/api/audit-logs/{id}` | Inspect single audit record with full JSON diff | `auth:sanctum`, `audit.view` |

#### Bulk Settings Update Schema (`PUT /api/settings`)
Request:
```json
{
  "organization_id": 1,
  "settings": {
    "attendance.auto_process": true,
    "attendance.late_grace_minutes": 20,
    "visitor.require_photo": false
  }
}
```
Response:
```json
{
  "status": "success",
  "message": "Settings updated successfully",
  "data": {
    "attendance.auto_process": true,
    "attendance.late_grace_minutes": 20,
    "visitor.require_photo": false
  }
}
```

#### Audit Log Query Parameters (`GET /api/audit-logs`)
- `page`: int (default 1)
- `per_page`: int (default 25)
- `action`: string (`create`, `update`, `delete`, `login`, `override`)
- `auditable_type`: string (`Device`, `Department`, `Location`, `Employee`)
- `user_id`: int
- `start_date`: `YYYY-MM-DD`
- `end_date`: `YYYY-MM-DD`
- `search`: string (matches in `description` or `auditable_id`)

---

## 6. Route Registration Architecture (`routes/api.php`)

```php
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\SettingController;

// Public Branding
Route::get('settings/public', [SettingController::class, 'publicSettings']);

// Protected Management Routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Organizations
    Route::apiResource('organizations', OrganizationController::class);

    // Locations
    Route::get('locations', [OrganizationController::class, 'listLocations']);
    Route::post('locations', [OrganizationController::class, 'storeLocation']);
    Route::get('locations/{location}', [OrganizationController::class, 'showLocation']);
    Route::put('locations/{location}', [OrganizationController::class, 'updateLocation']);
    Route::delete('locations/{location}', [OrganizationController::class, 'destroyLocation']);

    // Departments
    Route::get('departments/tree', [OrganizationController::class, 'departmentTree']);
    Route::get('departments', [OrganizationController::class, 'listDepartments']);
    Route::post('departments', [OrganizationController::class, 'storeDepartment']);
    Route::get('departments/{department}', [OrganizationController::class, 'showDepartment']);
    Route::put('departments/{department}', [OrganizationController::class, 'updateDepartment']);
    Route::delete('departments/{department}', [OrganizationController::class, 'destroyDepartment']);

    // Designations
    Route::get('designations', [OrganizationController::class, 'listDesignations']);
    Route::post('designations', [OrganizationController::class, 'storeDesignation']);
    Route::get('designations/{designation}', [OrganizationController::class, 'showDesignation']);
    Route::put('designations/{designation}', [OrganizationController::class, 'updateDesignation']);
    Route::delete('designations/{designation}', [OrganizationController::class, 'destroyDesignation']);

    // Settings
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);
    Route::post('settings/reset', [SettingController::class, 'reset']);

    // Audit Trail
    Route::get('audit-logs', [SettingController::class, 'auditLogs']);
    Route::get('audit-logs/{auditLog}', [SettingController::class, 'showAuditLog']);
});
```

---

## 7. Zero-Breaking Regression & Invariant Analysis

| Invariant | Protection Mechanism | Verification |
| :--- | :--- | :--- |
| **Existing Device Operations** | `organization_id` & `location_id` are nullable. `device_role` defaults to `'bidirectional'`. | `php artisan test` tests pass without modifying existing device fixtures. |
| **Camera Webhooks** | `/Subscribe/heartbeat`, `/Subscribe/Verify`, `/Subscribe/Snap` are excluded from auth. | Edge cameras post raw telemetry without 401 Unauthorized errors. |
| **Edge Hardware Sync** | `SyncPersonnelJob` and `PersonnelObserver` continue to target hardware via direct LAN HTTP. | Face sync pipeline unaffected by organization hierarchy tables. |
| **Dual Database Support** | Migrations use standard ANSI types supported by PostgreSQL 16 and SQLite `:memory:`. | Test suite runs cleanly in in-memory SQLite and Postgres. |
| **Chicken-and-Egg FKs** | `head_id` on `departments` is created as unconstrained `unsignedBigInteger` in M1. | No migration crashes when running before M2 `employees` table exists. |

---

## 8. Verification & Test Plan

1. **Migration Integrity Test**:
   Execute `php artisan migrate:fresh --seed` in test environment. Verify all tables and seeded settings create cleanly.
2. **Device Backward Compatibility Test**:
   Run existing test suite:
   ```bash
   php artisan test --filter DeviceManagementTest
   php artisan test --filter DeviceProbeAndHttpsTest
   ```
   Must pass with 0 errors.
3. **Department Hierarchy & Circular Guard Test**:
   - Create Parent Department (A) and Child Department (B).
   - Verify `GET /api/departments/tree` returns B nested in A.
   - Attempt to set B as parent of A; assert HTTP 422 with circular dependency message.
4. **Settings Scoping & Fallback Test**:
   - Query key `attendance.late_grace_minutes` without org; returns default 15.
   - Set override for Org #1 to 25.
   - Query for Org #1 returns 25; query for Org #2 returns 15.
5. **Polymorphic Audit Trail Test**:
   - Create a Location. Check `audit_logs` has `action = 'create'` and `auditable_type = 'App\Models\Location'`.
   - Update Location address. Check `audit_logs` has `action = 'update'`, `old_values`, and `new_values`.
