<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'alert_type',
        'operator',
        'severity',
        'title',
        'description',
        'snap_pic_url',
        'scene_pic_url',
        'video_url',
        'details',
        'status',
        'resolved_at',
        'resolved_by',
        'captured_at',
    ];

    protected $casts = [
        'details' => 'array',
        'captured_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
