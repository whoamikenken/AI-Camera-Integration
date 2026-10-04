<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'access_log_id',
        'device_id',
        'punch_time',
        'direction',
        'source',
        'reason',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'punch_time' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function accessLog(): BelongsTo
    {
        return $this->belongsTo(AccessLog::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
