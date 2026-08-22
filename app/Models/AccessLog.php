<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'person_id',
        'customize_id',
        'person_uuid',
        'person_name',
        'verify_status',
        'verify_type',
        'person_type',
        'similarity',
        'snap_pic_url',
        'scene_pic_url',
        'target_pos',
        'is_no_mask',
        'captured_at',
        'created_at',
    ];

    protected $casts = [
        'person_id' => 'integer',
        'customize_id' => 'integer',
        'verify_status' => 'integer',
        'verify_type' => 'integer',
        'person_type' => 'integer',
        'similarity' => 'decimal:2',
        'target_pos' => 'array',
        'is_no_mask' => 'integer',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'customize_id', 'customize_id');
    }
}
