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
    ];

    public function getAvailableAttribute(): float
    {
        return max(0.0, ($this->allocated + $this->carried_over) - ($this->used + $this->pending));
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
