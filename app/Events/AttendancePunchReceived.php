<?php

namespace App\Events;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class AttendancePunchReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public AttendancePunch $punch,
        public ?AttendanceRecord $record = null
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('attendance'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AttendancePunchReceived';
    }

    public function broadcastWith(): array
    {
        $employee = $this->punch->relationLoaded('employee') ? $this->punch->employee : $this->punch->employee;
        $employeeName = 'Unknown';
        if ($employee) {
            if ($employee->relationLoaded('personnel') && $employee->personnel) {
                $employeeName = $employee->personnel->name;
                if ($employee->personnel_id) {
                    Cache::put("emp_personnel_name:{$employee->personnel_id}", $employeeName, 3600);
                }
            } elseif ($employee->personnel_id) {
                $employeeName = Cache::remember("emp_personnel_name:{$employee->personnel_id}", 3600, function () use ($employee) {
                    return $employee->personnel?->name ?? 'Unknown';
                });
            }
        }

        return [
            'punch' => [
                'id' => $this->punch->id,
                'employee_id' => $this->punch->employee_id,
                'employee_name' => $employeeName,
                'employee_code' => $this->punch->employee?->employee_code,
                'department_name' => $this->punch->employee?->department?->name,
                'punch_time' => $this->punch->punch_time ? $this->punch->punch_time->toISOString() : now()->toISOString(),
                'direction' => $this->punch->direction,
                'source' => $this->punch->source,
                'device_id' => $this->punch->device_id,
            ],
            'record' => $this->record ? [
                'id' => $this->record->id,
                'employee_id' => $this->record->employee_id,
                'date' => $this->record->date ? $this->record->date->format('Y-m-d') : null,
                'first_clock_in' => $this->record->first_clock_in ? $this->record->first_clock_in->toISOString() : null,
                'last_clock_out' => $this->record->last_clock_out ? $this->record->last_clock_out->toISOString() : null,
                'total_work_hours' => (float) $this->record->total_work_hours,
                'status' => $this->record->status,
                'is_late' => (bool) $this->record->is_late,
                'late_minutes' => (int) $this->record->late_minutes,
            ] : null,
        ];
    }
}
