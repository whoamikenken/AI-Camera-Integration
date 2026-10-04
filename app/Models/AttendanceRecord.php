<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'shift_id',
        'first_clock_in',
        'last_clock_out',
        'total_work_hours',
        'overtime_hours',
        'status',
        'is_late',
        'late_minutes',
        'is_early_out',
        'early_out_minutes',
        'source',
        'remarks',
    ];

    protected $casts = [
        'date' => 'date',
        'first_clock_in' => 'datetime',
        'last_clock_out' => 'datetime',
        'total_work_hours' => 'float',
        'overtime_hours' => 'float',
        'is_late' => 'boolean',
        'late_minutes' => 'integer',
        'is_early_out' => 'boolean',
        'early_out_minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
