<?php

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DailyAttendanceFinalizerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Carbon $date;

    public function __construct(Carbon|string|null $date = null)
    {
        if ($date instanceof Carbon) {
            $this->date = $date->startOfDay();
        } elseif (is_string($date)) {
            $this->date = Carbon::parse($date)->startOfDay();
        } else {
            $this->date = Carbon::today();
        }
    }

    public function handle(AttendanceProcessingService $service): void
    {
        $employees = Employee::where('employment_status', 'active')->get();

        foreach ($employees as $employee) {
            $existing = AttendanceRecord::where('employee_id', $employee->id)
                ->where('date', $this->date->toDateString())
                ->first();

            // If no record exists or record has no clock-in, calculate status
            if (!$existing || (!$existing->first_clock_in && $existing->status === 'present')) {
                $service->recalculateDailyAttendance($employee, $this->date);
            }
        }
    }
}
