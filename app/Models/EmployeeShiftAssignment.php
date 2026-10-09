<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftAssignment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'effective_from',
        'effective_to',
        'assigned_days',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'assigned_days' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (EmployeeShiftAssignment $assignment) {
            if ($assignment->employee_id) {
                app(\App\Services\AttendanceProcessingService::class)->invalidateEmployeeShiftCache((int) $assignment->employee_id);
            }
        });

        static::deleted(function (EmployeeShiftAssignment $assignment) {
            if ($assignment->employee_id) {
                app(\App\Services\AttendanceProcessingService::class)->invalidateEmployeeShiftCache((int) $assignment->employee_id);
            }
        });
    }

    // Helper to test if assignment applies to a specific day of week
    public function appliesToDay(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dayIso = $carbon->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayName = strtolower($carbon->format('l')); // 'monday', ...
        $dayShort = strtolower($carbon->format('D')); // 'mon', ...

        if (empty($this->assigned_days)) {
            return in_array($dayIso, [1, 2, 3, 4, 5], true);
        }

        foreach ($this->assigned_days as $d) {
            if (is_numeric($d) && (int) $d === $dayIso) {
                return true;
            }
            if (is_string($d)) {
                $dl = strtolower($d);
                if ($dl === $dayName || $dl === $dayShort) {
                    return true;
                }
            }
        }

        return false;
    }

    // Scopes
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $dateStr);
            });
    }

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
