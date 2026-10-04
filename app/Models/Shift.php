<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'shift_start',
        'shift_end',
        'grace_period_minutes',
        'early_out_threshold_minutes',
        'half_day_threshold_hours',
        'min_hours_full_day',
        'is_overnight',
        'break_duration_minutes',
        'is_flexible',
        'color',
        'is_active',
    ];

    protected $casts = [
        'grace_period_minutes' => 'integer',
        'early_out_threshold_minutes' => 'integer',
        'half_day_threshold_hours' => 'float',
        'min_hours_full_day' => 'float',
        'is_overnight' => 'boolean',
        'break_duration_minutes' => 'integer',
        'is_flexible' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Compatibility accessors for M3 interface contract
    public function getStartTimeAttribute(): ?string
    {
        return $this->shift_start;
    }

    public function getEndTimeAttribute(): ?string
    {
        return $this->shift_end;
    }

    // Helper Methods
    public function crossesMidnight(): bool
    {
        if ($this->is_overnight) {
            return true;
        }
        if (!$this->shift_start || !$this->shift_end) {
            return false;
        }
        return Carbon::parse($this->shift_end)->lessThan(Carbon::parse($this->shift_start));
    }

    public function isOvernight(): bool
    {
        return (bool) $this->is_overnight || $this->crossesMidnight();
    }

    public function isDayShift(): bool
    {
        return !$this->isOvernight() && !$this->is_flexible;
    }

    public function durationMinutes(bool $netOfBreak = false): int
    {
        if ($this->is_flexible) {
            $totalMin = (int) (($this->min_hours_full_day ?? 8.0) * 60);
            return $netOfBreak ? max(0, $totalMin - ($this->break_duration_minutes ?? 0)) : $totalMin;
        }

        $start = Carbon::parse($this->shift_start);
        $end = Carbon::parse($this->shift_end);

        if ($this->crossesMidnight() || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $grossMinutes = (int) $start->diffInMinutes($end);

        return $netOfBreak
            ? max(0, $grossMinutes - ($this->break_duration_minutes ?? 0))
            : $grossMinutes;
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }
}
