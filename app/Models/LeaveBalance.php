<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'allocated',
        'used',
        'pending',
        'carried_over',
        'allocated_days',
        'used_days',
        'pending_days',
        'remaining_days',
    ];

    protected $casts = [
        'year' => 'integer',
        'allocated' => 'float',
        'used' => 'float',
        'pending' => 'float',
        'carried_over' => 'float',
    ];

    protected $appends = [
        'available',
        'allocated_days',
        'used_days',
        'pending_days',
        'remaining_days',
    ];

    public function getAvailableAttribute(): float
    {
        return max(0.0, ($this->allocated + $this->carried_over) - ($this->used + $this->pending));
    }

    public function getAllocatedDaysAttribute(): float
    {
        return (float) ($this->attributes['allocated'] ?? 0.0);
    }

    public function setAllocatedDaysAttribute($value): void
    {
        $this->attributes['allocated'] = (float) $value;
    }

    public function getUsedDaysAttribute(): float
    {
        return (float) ($this->attributes['used'] ?? 0.0);
    }

    public function setUsedDaysAttribute($value): void
    {
        $this->attributes['used'] = (float) $value;
    }

    public function getPendingDaysAttribute(): float
    {
        return (float) ($this->attributes['pending'] ?? 0.0);
    }

    public function setPendingDaysAttribute($value): void
    {
        $this->attributes['pending'] = (float) $value;
    }

    public function getRemainingDaysAttribute(): float
    {
        return (float) $this->available;
    }

    public function setRemainingDaysAttribute($value): void
    {
        // Virtual/read-only computed attribute
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
