<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /**
     * Calculate deductible working days between start and end date.
     * Excludes weekends and public/company holidays.
     */
    public function calculateRequestedDays(Carbon|string $startDate, Carbon|string $endDate, ?Employee $employee = null): float
    {
        $start = is_string($startDate) ? Carbon::parse($startDate)->startOfDay() : $startDate->copy()->startOfDay();
        $end = is_string($endDate) ? Carbon::parse($endDate)->startOfDay() : $endDate->copy()->startOfDay();

        if ($end->lessThan($start)) {
            return 0.0;
        }

        $period = CarbonPeriod::create($start, $end);
        $days = 0.0;

        foreach ($period as $date) {
            // Skip weekends
            if ($date->isWeekend()) {
                continue;
            }

            // Skip public holidays
            $dateStr = $date->format('Y-m-d');
            $isHoliday = Holiday::whereDate('date', $dateStr)
                ->orWhere(function ($q) use ($date) {
                    $q->where('is_recurring', true)
                      ->whereMonth('date', $date->month)
                      ->whereDay('date', $date->day);
                })
                ->exists();

            if ($isHoliday) {
                continue;
            }

            $days += 1.0;
        }

        return $days;
    }

    /**
     * Allocate leave days to an employee for a year.
     */
    public function allocateBalance(Employee $employee, int $leaveTypeId, int $year, float $allocatedDays): LeaveBalance
    {
        if ($allocatedDays < 0) {
            throw ValidationException::withMessages([
                'allocated' => ['Allocated days cannot be negative.'],
            ]);
        }

        return LeaveBalance::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
            ],
            [
                'allocated' => $allocatedDays,
            ]
        );
    }

    /**
     * Submit a new leave request.
     */
    public function submitLeaveRequest(
        Employee $employee,
        int $leaveTypeId,
        Carbon|string $startDate,
        Carbon|string $endDate,
        ?string $reason = null
    ): LeaveRequest {
        $start = is_string($startDate) ? Carbon::parse($startDate)->startOfDay() : $startDate->copy()->startOfDay();
        $end = is_string($endDate) ? Carbon::parse($endDate)->startOfDay() : $endDate->copy()->startOfDay();
        $year = $start->year;

        $totalDays = $this->calculateRequestedDays($start, $end, $employee);
        if ($totalDays <= 0) {
            $totalDays = 1.0;
        }

        $leaveType = LeaveType::findOrFail($leaveTypeId);

        $balance = LeaveBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
            ],
            [
                'allocated' => 5.0,
                'used' => 0.0,
                'pending' => 0.0,
                'carried_over' => 0.0,
            ]
        );

        if ($totalDays > $balance->available) {
            throw ValidationException::withMessages([
                'days' => ["Insufficient leave balance. Requested: {$totalDays} days, Available: {$balance->available} days."],
            ]);
        }

        return DB::transaction(function () use ($employee, $leaveTypeId, $start, $end, $totalDays, $reason, $balance) {
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $totalDays,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $balance->increment('pending', $totalDays);

            return $leaveRequest;
        });
    }

    /**
     * Helper to create leave request from array data.
     */
    public function createRequest(Employee $employee, array $data): LeaveRequest
    {
        return $this->submitLeaveRequest(
            $employee,
            (int) $data['leave_type_id'],
            $data['start_date'],
            $data['end_date'],
            $data['reason'] ?? null
        );
    }

    /**
     * Helper to approve request by user id or user instance.
     */
    public function approveRequest(LeaveRequest $request, int|User|null $approver = null): LeaveRequest
    {
        $user = is_numeric($approver) ? User::find($approver) : $approver;
        return $this->approveLeaveRequest($request, $user);
    }

    /**
     * Approve a pending leave request.
     */
    public function approveLeaveRequest(LeaveRequest $request, ?User $approver = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot approve leave request with status '{$request->status}'."],
            ]);
        }

        return DB::transaction(function () use ($request, $approver) {
            $year = Carbon::parse($request->start_date)->year;
            $balance = LeaveBalance::where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', $year)
                ->first();

            if ($balance) {
                $balance->pending = max(0.0, $balance->pending - $request->total_days);
                $balance->used += $request->total_days;
                $balance->save();
            }

            $request->update([
                'status' => 'approved',
                'approved_by' => $approver?->id,
                'approved_at' => now(),
            ]);

            // Update attendance records for the requested date range
            $period = CarbonPeriod::create(Carbon::parse($request->start_date), Carbon::parse($request->end_date));
            foreach ($period as $date) {
                if (!$date->isWeekend()) {
                    AttendanceRecord::updateOrCreate(
                        [
                            'employee_id' => $request->employee_id,
                            'date' => $date->format('Y-m-d'),
                        ],
                        [
                            'status' => 'on_leave',
                            'remarks' => 'Approved leave: ' . ($request->leaveType?->name ?? 'Leave'),
                            'source' => 'auto',
                        ]
                    );
                }
            }

            return $request->fresh();
        });
    }

    /**
     * Reject a pending leave request.
     */
    public function rejectLeaveRequest(LeaveRequest $request, ?string $reason = null, ?User $approver = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot reject leave request with status '{$request->status}'."],
            ]);
        }

        return DB::transaction(function () use ($request, $reason, $approver) {
            $year = Carbon::parse($request->start_date)->year;
            $balance = LeaveBalance::where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', $year)
                ->first();

            if ($balance) {
                $balance->pending = max(0.0, $balance->pending - $request->total_days);
                $balance->save();
            }

            $request->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'approved_by' => $approver?->id,
            ]);

            return $request->fresh();
        });
    }

    /**
     * Process year-end annual carry forward capping at max allowed days.
     */
    public function processYearEndCarryForward(int $fromYear, int $toYear): void
    {
        $balances = LeaveBalance::with('leaveType')
            ->where('year', $fromYear)
            ->get();

        foreach ($balances as $balance) {
            $leaveType = $balance->leaveType;
            if (!$leaveType || !$leaveType->is_carry_forward) {
                continue;
            }

            $unused = max(0.0, ($balance->allocated + $balance->carried_over) - $balance->used);
            $maxAllowed = (float) $leaveType->max_carry_forward_days;
            $carriedOver = min($unused, $maxAllowed);

            LeaveBalance::updateOrCreate(
                [
                    'employee_id' => $balance->employee_id,
                    'leave_type_id' => $balance->leave_type_id,
                    'year' => $toYear,
                ],
                [
                    'allocated' => $leaveType->max_days_per_year,
                    'carried_over' => $carriedOver,
                ]
            );
        }
    }
}
