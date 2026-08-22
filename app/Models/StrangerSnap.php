<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrangerSnap extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'snap_id',
        'snap_pic_url',
        'scene_pic_url',
        'target_pos',
        'is_no_mask',
        'alarm_action',
        'captured_at',
        'created_at',
    ];

    protected $casts = [
        'snap_id' => 'integer',
        'target_pos' => 'array',
        'is_no_mask' => 'integer',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
