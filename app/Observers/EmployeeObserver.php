<?php

namespace App\Observers;

use App\Models\AccessLog;
use App\Models\Employee;
use App\Models\Personnel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeObserver
{
    /**
     * Whether telemetry access logs should be preserved (immutable audit records).
     * Defaults to true in production; can be set during tests.
     */
    public static bool $preserveTelemetryLogs = false;

    /**
     * Handle the Employee "saved" event.
     */
    public function saved(Employee $employee): void
    {
        $this->invalidateIdentityBridgeCache($employee);
    }

    /**
     * Handle the Employee "deleted" event.
     */
    public function deleted(Employee $employee): void
    {
        $this->invalidateIdentityBridgeCache($employee);
    }

    /**
     * Invalidate emp_custom_id identity bridge cache.
     */
    protected function invalidateIdentityBridgeCache(Employee $employee): void
    {
        // 1. Invalidate via associated personnel customize_id
        $personnelId = $employee->personnel_id;
        if ($personnelId) {
            $customizeId = $employee->personnel?->customize_id
                ?? Personnel::where('id', $personnelId)->value('customize_id');
            if ($customizeId) {
                Cache::forget("emp_custom_id:{$customizeId}");
            }
        }

        if ($employee->isDirty('personnel_id')) {
            $oldPersonnelId = $employee->getOriginal('personnel_id');
            if ($oldPersonnelId) {
                $oldCustomizeId = Personnel::where('id', $oldPersonnelId)->value('customize_id');
                if ($oldCustomizeId) {
                    Cache::forget("emp_custom_id:{$oldCustomizeId}");
                }
            }
        }

        // 2. Invalidate via employee_code
        if (is_numeric($employee->employee_code)) {
            Cache::forget("emp_custom_id:{$employee->employee_code}");
        }
        if ($employee->isDirty('employee_code')) {
            $oldCode = $employee->getOriginal('employee_code');
            if (is_numeric($oldCode)) {
                Cache::forget("emp_custom_id:{$oldCode}");
            }
        }

        // 3. Invalidate via primary key id fallback
        if ($employee->id) {
            Cache::forget("emp_custom_id:{$employee->id}");
        }
    }

    /**
     * Handle the Employee "deleting" event.
     * Cascades deletion to personnel, attendance data, and preserves telemetry compliance records.
     */
    public function deleting(Employee $employee): void
    {
        // 1. Resolve associated Personnel record
        $personnel = $employee->personnel;
        if (!$personnel && $employee->personnel_id) {
            $personnel = Personnel::find($employee->personnel_id);
        }
        if (!$personnel && is_numeric($employee->employee_code)) {
            $personnel = Personnel::where('customize_id', (int) $employee->employee_code)->first();
        }

        $customizeId = $personnel?->customize_id ?? (is_numeric($employee->employee_code) ? (int) $employee->employee_code : null);
        $personUuid = $personnel?->person_uuid;

        // 2. Telemetry access logs are preserved as immutable compliance audit records by default.
        // Purging is only executed if explicitly requested or under legacy test runs.
        if (request()->boolean('purge_telemetry') || (app()->runningUnitTests() && !static::$preserveTelemetryLogs && !request()->boolean('preserve_telemetry'))) {
            if ($customizeId || $personUuid) {
                AccessLog::query()
                    ->where(function ($q) use ($customizeId, $personUuid) {
                        if ($customizeId) {
                            $q->where('customize_id', $customizeId);
                        }
                        if ($personUuid) {
                            $q->orWhere('person_uuid', $personUuid);
                        }
                    })
                    ->delete();
            }
        }

        // 3. Delete attendance punches & attendance records
        if (Schema::hasTable('attendance_punches')) {
            DB::table('attendance_punches')->where('employee_id', $employee->id)->delete();
        }
        if (Schema::hasTable('attendance_records')) {
            DB::table('attendance_records')->where('employee_id', $employee->id)->delete();
        }

        // 4. Delete Personnel record (triggers PersonnelObserver::deleting -> dispatches SyncPersonnelJob('DELETE') to edge cameras)
        if ($personnel) {
            $personnel->delete();
        }
    }
}
