<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'visitor_id',
        'host_employee_id',
        'personnel_id',
        'device_id',
        'purpose',
        'purpose_detail',
        'expected_arrival',
        'expected_departure',
        'check_in_time',
        'check_out_time',
        'overstay_alerted_at',
        'badge_number',
        'nda_signed',
        'status',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'expected_arrival' => 'datetime',
        'expected_departure' => 'datetime',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'overstay_alerted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'nda_signed' => 'boolean',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public function hostEmployee(): BelongsTo
    {
        return $this->host();
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
