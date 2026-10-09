<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceProcessingService
{
    /**
     * Process an incoming attendance punch.
     */
    public function processPunch(
        Employee $employee,
        Carbon|string $punchTime,
        ?string $direction = null,
        ?Device $device = null,
        ?AccessLog $accessLog = null,
        string $source = 'camera_auto',
        ?string $reason = null
    ): ?AttendancePunch {
        $punchTime = is_string($punchTime) ? Carbon::parse($punchTime) : $punchTime->copy();

        // 1. Debounce Check (60 seconds window)
        $recentPunch = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_time', [
                $punchTime->copy()->subSeconds(60),
                $punchTime->copy()->addSeconds(60),
            ])
            ->first();

        if ($recentPunch) {
            Log::info("Debounced duplicate punch for employee {$employee->id} at {$punchTime}");
            return $recentPunch;
        }

        // 2. Resolve Direction
        if (!$direction || !in_array($direction, ['in', 'out'])) {
            if ($device) {
                if ($device->device_role === 'entry') {
                    $direction = 'in';
                } elseif ($device->device_role === 'exit') {
                    $direction = 'out';
                }
            }

            if (!$direction || !in_array($direction, ['in', 'out'])) {
                // Infer based on last punch of the day using SARGable time window
                $startOfDay = $punchTime->copy()->startOfDay();
                $endOfDay = $punchTime->copy()->endOfDay();

                $lastPunchToday = AttendancePunch::where('employee_id', $employee->id)
                    ->whereBetween('punch_time', [$startOfDay, $endOfDay])
                    ->orderBy('punch_time', 'desc')
                    ->first();

                $direction = ($lastPunchToday && $lastPunchToday->direction === 'in') ? 'out' : 'in';
            }
        }

        // 3. Create Punch Record
        $punch = AttendancePunch::create([
            'employee_id' => $employee->id,
            'access_log_id' => $accessLog?->id,
            'device_id' => $device?->device_id ?? $accessLog?->device_id,
            'punch_time' => $punchTime,
            'direction' => $direction,
            'source' => $source,
            'reason' => $reason,
        ]);

        if (!$employee->relationLoaded('personnel') && $employee->personnel_id) {
            $personnel = Cache::remember("personnel_cache:{$employee->personnel_id}", 3600, function () use ($employee) {
                return $employee->personnel;
            });
            if ($personnel) {
                $employee->setRelation('personnel', $personnel);
            }
        }
        $punch->setRelation('employee', $employee);

        // 4. Resolve Work Date (considering overnight shifts)
        $workDate = $this->resolveWorkDate($employee, $punchTime);

        // 5. Recalculate Daily Attendance Record
        $record = $this->recalculateDailyAttendance($employee, $workDate);

        try {
            \App\Events\AttendancePunchReceived::dispatch($punch->withoutRelations(), $record);
        } catch (\Throwable $e) {
            Log::warning("Failed to broadcast AttendancePunchReceived: " . $e->getMessage());
        }

        return $punch;
    }

    /**
     * Determine the effective work date for a punch.
     */
    public function resolveWorkDate(Employee $employee, Carbon $punchTime): Carbon
    {
        $shift = $this->resolveEffectiveShift($employee, $punchTime->toDateString());

        if ($shift && $shift->is_overnight) {
            $shiftStartHour = (int) explode(':', $shift->shift_start)[0];
            // If punch is in early morning (e.g., between 00:00 and midday) and shift started late night
            if ($punchTime->hour < ($shiftStartHour - 4) && $punchTime->hour < 12) {
                return $punchTime->copy()->subDay()->startOfDay();
            }
        }

        return $punchTime->copy()->startOfDay();
    }

    /**
     * Resolve effective shift for an employee on a specific date with versioned caching.
     */
    public function resolveEffectiveShift(Employee $employee, string|Carbon $date): ?Shift
    {
        $dateStr = is_string($date) ? Carbon::parse($date)->toDateString() : $date->toDateString();
        $version = (int) Cache::get("emp_shift_v:{$employee->id}", 0);
        $cacheKey = $version > 0
            ? "emp_shift:{$employee->id}:v{$version}:{$dateStr}"
            : "emp_shift:{$employee->id}:{$dateStr}";

        $shiftId = Cache::remember($cacheKey, 300, function () use ($employee, $dateStr, $cacheKey) {
            try {
                $tracked = Cache::get("emp_shift_keys:{$employee->id}", []);
                if (!in_array($cacheKey, $tracked, true)) {
                    $tracked[] = $cacheKey;
                    Cache::put("emp_shift_keys:{$employee->id}", $tracked, 86400);
                }
            } catch (\Throwable $e) {
                // Ignore cache tracking errors
            }

            $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->whereDate('effective_from', '<=', $dateStr)
                ->where(function ($q) use ($dateStr) {
                    $q->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $dateStr);
                })
                ->orderBy('effective_from', 'desc')
                ->first();

            if ($assignment && $assignment->shift_id) {
                return $assignment->shift_id;
            }

            return $employee->shift_id ?? Shift::first()?->id;
        });

        if (is_object($shiftId) && $shiftId instanceof Shift) {
            return $shiftId;
        }

        return $shiftId ? Shift::find($shiftId) : null;
    }

    /**
     * Invalidate shift cache for a single employee in O(1) without blocking Redis KEYS.
     */
    public static function invalidateEmployeeShiftCache(int $employeeId): void
    {
        try {
            Cache::increment("emp_shift_v:{$employeeId}");
            $trackedKeys = Cache::get("emp_shift_keys:{$employeeId}", []);
            if (!empty($trackedKeys)) {
                foreach ($trackedKeys as $k) {
                    Cache::forget($k);
                }
                Cache::forget("emp_shift_keys:{$employeeId}");
            }
        } catch (\Throwable $e) {
            // Graceful fallback
        }
    }

    /**
     * Invalidate shift cache for multiple employees in bulk without blocking Redis KEYS.
     *
     * @param array<int> $employeeIds
     */
    public static function invalidateShiftCacheForEmployees(array $employeeIds): void
    {
        foreach ($employeeIds as $id) {
            self::invalidateEmployeeShiftCache((int) $id);
        }
    }

    /**
     * Check if a given date is a holiday (cached per year).
     */
    public function isHoliday(Carbon $date, ?Employee $employee = null): bool
    {
        $year = $date->year;

        // Primary cache key: holidays_{year} (contracted by PerformanceOptimizationTest)
        $holidays = Cache::remember("holidays_{$year}", 3600, function () use ($year) {
            $records = Holiday::whereYear('date', $year)
                ->orWhere('is_recurring', true)
                ->get();

            // Maintain secondary alias holiday_ids_{year} for backwards/forward compatibility
            try {
                Cache::put("holiday_ids_{$year}", $records->pluck('id')->toArray(), 3600);
            } catch (\Throwable $e) {
                // Ignore cache put issues
            }

            // Return plain arrays to eliminate model serialization overhead and __PHP_Incomplete_Class
            return $records->map(function ($h) {
                return [
                    'id' => $h->id,
                    'organization_id' => $h->organization_id,
                    'name' => $h->name,
                    'date' => $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date,
                    'type' => $h->type,
                    'is_recurring' => (bool) $h->is_recurring,
                    'applies_to' => $h->applies_to,
                ];
            })->all();
        });

        // Ensure holiday_ids_{year} alias is populated if missing
        if (!Cache::has("holiday_ids_{$year}")) {
            try {
                $ids = is_array($holidays)
                    ? array_filter(array_map(fn($item) => is_array($item) ? ($item['id'] ?? null) : (is_object($item) ? ($item->id ?? null) : $item), $holidays))
                    : [];
                Cache::put("holiday_ids_{$year}", array_values($ids), 3600);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Support Collection, array, or hydrated models transparently
        if (!is_array($holidays) && !($holidays instanceof \Illuminate\Support\Collection)) {
            $holidays = [];
        }

        $dateStr = $date->format('Y-m-d');
        foreach ($holidays as $h) {
            // Guard against dummy strings (e.g. ['dummy'] in cache invalidation tests)
            if (!is_object($h) && !is_array($h)) {
                continue;
            }

            // Path 1: Array representation (preferred high-performance path)
            if (is_array($h)) {
                $orgId = $h['organization_id'] ?? null;
                if ($employee && $orgId && $employee->organization_id && $orgId !== $employee->organization_id) {
                    continue;
                }

                $applies = $h['applies_to'] ?? null;
                if ($employee && !empty($applies)) {
                    if (isset($applies['departments']) && is_array($applies['departments']) && !in_array($employee->department_id, $applies['departments'])) {
                        continue;
                    }
                    if (isset($applies['locations']) && is_array($applies['locations']) && !in_array($employee->location_id, $applies['locations'])) {
                        continue;
                    }
                    if (array_is_list($applies) && !empty($applies) && !in_array($employee->department_id, $applies)) {
                        continue;
                    }
                }

                $hDateRaw = $h['date'] ?? null;
                if (!$hDateRaw) {
                    continue;
                }
                $hDate = $hDateRaw instanceof Carbon ? $hDateRaw : Carbon::parse($hDateRaw);
                if ($hDate->format('Y-m-d') === $dateStr) {
                    return true;
                }
                if (!empty($h['is_recurring']) && (int) $hDate->month === (int) $date->month && (int) $hDate->day === (int) $date->day) {
                    return true;
                }
                continue;
            }

            // Path 2: Eloquent Model representation
            if ($h instanceof Holiday) {
                if ($employee && $h->organization_id && $employee->organization_id && $h->organization_id !== $employee->organization_id) {
                    continue;
                }
                if ($employee && !$h->appliesToEmployee($employee)) {
                    continue;
                }
                if ($h->isHolidayOn($date)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Process/recalculate daily attendance for a specific employee and date.
     */
    public function processDay(Employee $employee, Carbon|string $date): AttendanceRecord
    {
        return $this->recalculateDailyAttendance($employee, $date);
    }

    /**
     * Recalculate and upsert daily attendance record.
     */
    public function recalculateDailyAttendance(Employee $employee, string|Carbon $date): AttendanceRecord
    {
        $dateObj = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dateStr = $dateObj->format('Y-m-d');
        $shift = $this->resolveEffectiveShift($employee, $dateObj);

        // Retrieve all punches associated with this work date
        $startDate = $dateObj->copy()->startOfDay();
        $endDate = ($shift && $shift->is_overnight)
            ? $dateObj->copy()->addDay()->setHour(14)->setMinute(0)
            : $dateObj->copy()->endOfDay();

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_time', [$startDate, $endDate])
            ->orderBy('punch_time', 'asc')
            ->get();

        // Check Holiday with cache
        $isHoliday = $this->isHoliday($dateObj, $employee);

        $existing = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $dateStr)
            ->first();

        if ($punches->isEmpty()) {
            $status = $isHoliday ? 'holiday' : 'absent';
            $data = [
                'shift_id' => $shift?->id,
                'first_clock_in' => null,
                'last_clock_out' => null,
                'total_work_hours' => 0.0,
                'overtime_hours' => 0.0,
                'status' => $status,
                'is_late' => false,
                'late_minutes' => 0,
                'is_early_out' => false,
                'early_out_minutes' => 0,
                'source' => 'auto',
            ];

            if ($existing) {
                $existing->update($data);
                return $existing;
            }

            return AttendanceRecord::create(array_merge(
                ['employee_id' => $employee->id, 'date' => $dateStr],
                $data
            ));
        }

        $firstInPunch = $punches->firstWhere('direction', 'in') ?? $punches->first();
        $firstIn = $firstInPunch ? Carbon::parse($firstInPunch->punch_time) : null;

        $lastOutPunch = $punches->where('direction', 'out')->last() ?? ($punches->count() > 1 ? $punches->last() : null);
        $lastOut = $lastOutPunch ? Carbon::parse($lastOutPunch->punch_time) : null;

        $breakMinutes = $shift ? (int) $shift->break_duration_minutes : 0;
        $totalHours = 0.0;
        if ($firstIn && $lastOut && $lastOut->getTimestamp() > $firstIn->getTimestamp()) {
            $totalHours = $this->calculateNetWorkHours($firstIn, $lastOut, $breakMinutes);
        }

        // Late calculations
        $isLate = false;
        $lateMinutes = 0;
        if ($shift && $shift->shift_start) {
            $grace = (int) ($shift->grace_period_minutes ?? 0);
            $lateMinutes = $this->calculateLateMinutes($firstIn, $shift->shift_start, $grace);
            $isLate = ($lateMinutes > 0);
        }

        // Early out calculations
        $isEarlyOut = false;
        $earlyOutMinutes = 0;
        if ($shift && $shift->shift_end && $lastOut) {
            $earlyThreshold = (int) ($shift->early_out_threshold_minutes ?? 0);
            $earlyOutMinutes = $this->calculateEarlyOutMinutes($lastOut, $shift->shift_end, $earlyThreshold, (bool) $shift->is_overnight);
            $isEarlyOut = ($earlyOutMinutes > 0);
        }

        // Overtime calculation
        $minFullDay = $shift ? (float) ($shift->min_hours_full_day ?? 8.0) : 8.0;
        $overtimeHours = $isHoliday
            ? $totalHours
            : $this->calculateOvertimeHours($totalHours, $minFullDay);

        // Status classification
        $halfDayThreshold = $shift ? (float) ($shift->half_day_threshold_hours ?? 4.0) : 4.0;
        if ($totalHours > 0 && $totalHours < $halfDayThreshold) {
            $status = 'half_day';
        } elseif ($isLate && $isEarlyOut) {
            $status = 'late_and_early_out';
        } elseif ($isLate) {
            $status = 'late';
        } elseif ($isEarlyOut) {
            $status = 'early_out';
        } else {
            $status = 'present';
        }

        $recordData = [
            'shift_id' => $shift?->id,
            'first_clock_in' => $firstIn,
            'last_clock_out' => $lastOut,
            'total_work_hours' => $totalHours,
            'overtime_hours' => $overtimeHours,
            'status' => $status,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'is_early_out' => $isEarlyOut,
            'early_out_minutes' => $earlyOutMinutes,
            'source' => 'auto',
        ];

        if ($existing) {
            $existing->update($recordData);
            return $existing;
        }

        return AttendanceRecord::create(array_merge(
            ['employee_id' => $employee->id, 'date' => $dateStr],
            $recordData
        ));
    }

    /**
     * Calculate late minutes from scheduled start.
     * Rule: Punch exactly at or before (start + grace) is 0 min late.
     * Punch after (start + grace) is late by total minutes elapsed since shift start.
     */
    public function calculateLateMinutes(Carbon|string $clockIn, Carbon|string $shiftStart, int $graceMinutes = 0): int
    {
        $in = is_string($clockIn) ? Carbon::parse($clockIn) : $clockIn->copy();
        
        if (is_string($shiftStart) && strlen($shiftStart) <= 8) {
            $start = Carbon::parse($in->format('Y-m-d') . ' ' . $shiftStart);
        } else {
            $parsed = is_string($shiftStart) ? Carbon::parse($shiftStart) : $shiftStart->copy();
            $start = Carbon::parse($in->format('Y-m-d') . ' ' . $parsed->format('H:i:s'));
        }

        $graceCutoff = $start->copy()->addMinutes($graceMinutes);

        if ($in->getTimestamp() <= $graceCutoff->getTimestamp()) {
            return 0;
        }

        return max(0, (int) ceil(($in->getTimestamp() - $start->getTimestamp()) / 60));
    }

    /**
     * Calculate early out minutes before scheduled end.
     * Rule: Punch at or after (end - threshold) is 0 min early out.
     * Punch before (end - threshold) is early out by total minutes remaining until shift end.
     */
    public function calculateEarlyOutMinutes(Carbon|string $clockOut, Carbon|string $shiftEnd, int $earlyOutThreshold = 0, bool $isOvernight = false): int
    {
        $out = is_string($clockOut) ? Carbon::parse($clockOut) : $clockOut->copy();

        if (is_string($shiftEnd) && strlen($shiftEnd) <= 8) {
            $end = Carbon::parse($out->format('Y-m-d') . ' ' . $shiftEnd);
        } else {
            $parsed = is_string($shiftEnd) ? Carbon::parse($shiftEnd) : $shiftEnd->copy();
            $end = Carbon::parse($out->format('Y-m-d') . ' ' . $parsed->format('H:i:s'));
        }

        $thresholdCutoff = $end->copy()->subMinutes($earlyOutThreshold);

        if ($out->getTimestamp() >= $thresholdCutoff->getTimestamp()) {
            return 0;
        }

        return max(0, (int) ceil(($end->getTimestamp() - $out->getTimestamp()) / 60));
    }

    /**
     * Calculate net work hours minus unpaid break.
     */
    public function calculateNetWorkHours(Carbon|string $clockIn, Carbon|string $clockOut, int $breakMinutes = 0): float
    {
        $in = is_string($clockIn) ? Carbon::parse($clockIn) : $clockIn->copy();
        $out = is_string($clockOut) ? Carbon::parse($clockOut) : $clockOut->copy();

        $diffSeconds = $out->getTimestamp() - $in->getTimestamp();
        if ($diffSeconds <= 0) {
            return 0.0;
        }

        $totalMinutes = (int) floor($diffSeconds / 60);
        $netMinutes = max(0, $totalMinutes - $breakMinutes);

        return round($netMinutes / 60.0, 2);
    }

    /**
     * Calculate overtime hours beyond minimum full day requirement.
     */
    public function calculateOvertimeHours(float $netWorkHours, float $minFullDayHours = 8.0): float
    {
        if ($netWorkHours <= $minFullDayHours) {
            return 0.0;
        }

        return round($netWorkHours - $minFullDayHours, 2);
    }
}
