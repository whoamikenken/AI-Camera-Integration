<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'personnel_id',
        'user_id',
        'organization_id',
        'department_id',
        'designation_id',
        'location_id',
        'reporting_manager_id',
        'shift_id',
        'employee_code',
        'first_name',
        'last_name',
        'employment_type',
        'employment_status',
        'date_of_joining',
        'date_of_leaving',
        'work_email',
        'personal_email',
        'phone',
        'avatar',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_leaving' => 'date',
    ];

    protected $appends = [
        'name',
        'email',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getEmailAttribute(): ?string
    {
        return $this->work_email ?? $this->personal_email;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function reportingManager(): BelongsTo
    {
        return $this->manager();
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    // =========================================================================
    // CONTRACT METHODS FOR M3 (ATTENDANCE & SCHEDULING ENGINE)
    // =========================================================================

    /**
     * Resolve the effective Shift for this employee on a given date.
     * Order of precedence:
     * 1. Active ShiftAssignment covering $date with matching assigned_days
     * 2. Active ShiftAssignment covering $date with null assigned_days (daily default)
     * 3. Employee's default assigned shift ($this->shift)
     * 4. Organization default active shift
     */
    public function currentShift(Carbon|string|null $date = null): ?Shift
    {
        $carbon = $date ? (is_string($date) ? Carbon::parse($date) : $date->copy()) : Carbon::today();
        $dateStr = $carbon->toDateString();
        $dayName = strtolower($carbon->format('l'));
        $dayShort = strtolower($carbon->format('D'));
        $dayIso = $carbon->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayNum = $carbon->dayOfWeek;    // 0 (Sun) - 6 (Sat)

        // Find active assignments
        $assignments = $this->shiftAssignments()
            ->with('shift')
            ->whereDate('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($assignments as $assignment) {
            if (!$assignment->shift) {
                continue;
            }

            if (empty($assignment->assigned_days)) {
                return $assignment->shift;
            }

            $days = array_map(fn($d) => is_string($d) ? strtolower(trim($d)) : $d, $assignment->assigned_days);
            if (in_array($dayName, $days, true) || in_array($dayShort, $days, true) || in_array($dayIso, $days, true) || in_array($dayNum, $days, true)) {
                return $assignment->shift;
            }
        }

        // Fall back to employee default shift
        if ($this->shift && $this->shift->is_active) {
            return $this->shift;
        }

        // Fall back to organization shift
        if ($this->organization_id) {
            return Shift::where('organization_id', $this->organization_id)
                ->where('is_active', true)
                ->first();
        }

        return Shift::where('is_active', true)->first();
    }

    /**
     * Determine if a given date is a Holiday for this employee.
     */
    public function isHoliday(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        
        return app(\App\Services\AttendanceProcessingService::class)->isHoliday($carbon, $this);
    }

    /**
     * Determine if a given date is a Rest Day (non-working day) for this employee.
     */
    public function isRestDay(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dateStr = $carbon->toDateString();
        $dayIso = $carbon->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayNum = $carbon->dayOfWeek;    // 0 (Sun) - 6 (Sat)
        $dayName = strtolower($carbon->format('l'));
        $dayShort = strtolower($carbon->format('D'));

        $assignment = $this->shiftAssignments()
            ->whereDate('effective_from', '<=', $dateStr)
            ->where(function ($query) use ($dateStr) {
                $query->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $days = $assignment?->assigned_days;

        if (empty($days)) {
            // Default standard business week: Saturday (6) and Sunday (7) are rest days
            return in_array($dayIso, [6, 7], true);
        }

        foreach ($days as $d) {
            if (is_numeric($d) && ((int) $d === $dayIso || (int) $d === $dayNum)) {
                return false; // Scheduled work day
            }
            if (is_string($d)) {
                $dLower = strtolower(trim($d));
                if ($dLower === $dayName || $dLower === $dayShort) {
                    return false; // Scheduled work day
                }
            }
        }

        return true; // Not in assigned days -> Rest day
    }
}
