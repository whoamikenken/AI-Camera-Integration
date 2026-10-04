<?php

namespace App\Events;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendancePunchReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
        return [
            'punch' => [
                'id' => $this->punch->id,
                'employee_id' => $this->punch->employee_id,
                'employee_name' => $this->punch->employee?->personnel?->name ?? 'Unknown',
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
