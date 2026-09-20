<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'port' => 'integer',
        'device_type' => 'integer',
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

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
