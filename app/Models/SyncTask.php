<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'personnel_id',
        'action',
        'status',
        'attempts',
        'error_message',
    ];

    protected $casts = [
        'personnel_id' => 'integer',
        'attempts' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'personnel_id');
    }
}
