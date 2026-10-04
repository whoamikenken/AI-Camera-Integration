<?php

namespace App\Jobs;

use App\Models\AccessLog;
use App\Models\Employee;
use App\Models\Personnel;
use App\Services\AttendanceProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAttendancePunchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public AccessLog $accessLog
    ) {}

    public function handle(AttendanceProcessingService $service): void
    {
        // Only process whitelist allowed verifications
        if ($this->accessLog->verify_status !== 1) {
            return;
        }

        // Find linked employee via customize_id or personnel_id
        $personnel = null;
        if ($this->accessLog->customize_id) {
            $personnel = Personnel::where('customize_id', $this->accessLog->customize_id)->first();
        }

        $employee = null;
        if ($personnel) {
            $employee = Employee::where('personnel_id', $personnel->id)->first();
        }

        if (!$employee && $this->accessLog->customize_id) {
            $employee = Employee::where('employee_code', (string) $this->accessLog->customize_id)
                ->orWhere('id', $this->accessLog->customize_id)
                ->first();
        }

        if (!$employee) {
            Log::info("No employee linked for access log #{$this->accessLog->id} (customize_id: {$this->accessLog->customize_id})");
            return;
        }

        $device = $this->accessLog->device;
        $punchTime = $this->accessLog->captured_at ?? now();

        $service->processPunch(
            employee: $employee,
            punchTime: $punchTime,
            direction: null,
            device: $device,
            accessLog: $this->accessLog,
            source: 'camera_auto'
        );
    }
}
