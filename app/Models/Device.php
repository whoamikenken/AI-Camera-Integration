<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use Auditable, HasFactory;

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
        'organization_id',
        'location_id',
        'device_role',
        'department_ids',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
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

    public function alerts(): HasMany
    {
        return $this->hasMany(DeviceAlert::class, 'device_id', 'device_id');
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
        if (! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->diffInSeconds(now()) <= 90;
    }
}
