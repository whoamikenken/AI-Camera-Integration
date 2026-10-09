<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegularizationRequest extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'employee_id',
        'date',
        'requested_in',
        'requested_out',
        'requested_clock_in',
        'requested_clock_out',
        'reason',
        'status',
        'approved_by',
        'rejection_reason',
        'approved_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'date' => 'date',
        'requested_in' => 'datetime',
        'requested_out' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getRequestedClockInAttribute()
    {
        return $this->requested_in;
    }

    public function setRequestedClockInAttribute($value): void
    {
        $this->attributes['requested_in'] = $value;
    }

    public function getRequestedClockOutAttribute()
    {
        return $this->requested_out;
    }

    public function setRequestedClockOutAttribute($value): void
    {
        $this->attributes['requested_out'] = $value;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

if (!class_exists(\App\Models\AttendanceRegularization::class)) {
    class_alias(RegularizationRequest::class, \App\Models\AttendanceRegularization::class);
}
