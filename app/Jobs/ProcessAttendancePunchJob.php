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
use Illuminate\Support\Facades\Cache;
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
        $customizeId = $this->accessLog->customize_id;
        if (!$customizeId) {
            Log::info("No customize_id on access log #{$this->accessLog->id}");
            return;
        }

        $cacheKey = "emp_custom_id:{$customizeId}";
        $cached = Cache::remember($cacheKey, 3600, function () use ($customizeId) {
            $personnel = Personnel::where('customize_id', $customizeId)->first();
            $employee = null;
            if ($personnel) {
                $employee = Employee::where('personnel_id', $personnel->id)->first();
            }

            if (!$employee) {
                $employee = Employee::where('employee_code', (string) $customizeId)
                    ->orWhere('id', $customizeId)
                    ->first();
            }

            return [
                'employee_id' => $employee?->id,
                'personnel_id' => $personnel?->id ?? $employee?->personnel_id,
            ];
        });

        $employeeId = $cached['employee_id'] ?? null;
        if (!$employeeId) {
            Log::info("No employee linked for access log #{$this->accessLog->id} (customize_id: {$customizeId})");
            return;
        }

        $employee = Employee::find($employeeId);
        if (!$employee) {
            Cache::forget($cacheKey);
            Log::info("Employee #{$employeeId} not found in database for access log #{$this->accessLog->id}");
            return;
        }

        if (!empty($cached['personnel_id'])) {
            $personnel = Cache::remember("personnel_cache:{$cached['personnel_id']}", 3600, function () use ($cached) {
                return Personnel::find($cached['personnel_id']);
            });
            if ($personnel) {
                $employee->setRelation('personnel', $personnel);
            }
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
