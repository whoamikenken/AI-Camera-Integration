<?php

namespace Tests\Feature;

use App\Jobs\DailyAttendanceFinalizerJob;
use App\Jobs\ProcessAttendancePunchJob;
use App\Models\AccessLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricAttendanceEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Shift $dayShift;
    protected Employee $employee;
    protected Personnel $personnel;
    protected Device $entryCam;
    protected Device $exitCam;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        $this->org = Organization::create([
            'name' => 'Pinnacle Technologies Inc.',
            'code' => 'PINNACLE-HQ',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$role->id]);
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->dayShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day Shift',
            'code' => 'SHIFT-09-18',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'grace_period_minutes' => 15,
            'early_out_threshold_minutes' => 30,
            'break_duration_minutes' => 60,
            'min_hours_full_day' => 8.0,
            'half_day_threshold_hours' => 4.0,
            'is_overnight' => false,
        ]);

        $this->personnel = Personnel::create([
            'customize_id' => 8801,
            'name' => 'Sarah Connor',
            'person_type' => 0,
        ]);

        $this->employee = Employee::create([
            'personnel_id' => $this->personnel->id,
            'shift_id' => $this->dayShift->id,
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-CONNOR-01',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'employment_status' => 'active',
        ]);

        $this->entryCam = Device::create([
            'device_id' => 'CAM-ENT-01',
            'name' => 'Entry Turnstile Camera',
            'ip_address' => '192.168.1.101',
            'device_role' => 'entry',
            'is_active' => true,
        ]);

        $this->exitCam = Device::create([
            'device_id' => 'CAM-EXT-01',
            'name' => 'Exit Turnstile Camera',
            'ip_address' => '192.168.1.102',
            'device_role' => 'exit',
            'is_active' => true,
        ]);
    }

    public function test_debounce_filters_punches_within_60_seconds(): void
    {
        $service = app(AttendanceProcessingService::class);
        $time = Carbon::parse('2026-09-29 08:55:00');

        $p1 = $service->processPunch($this->employee, $time, 'in', $this->entryCam);
        $p2 = $service->processPunch($this->employee, $time->copy()->addSeconds(30), 'in', $this->entryCam);

        $this->assertEquals($p1->id, $p2->id);
        $this->assertEquals(1, AttendancePunch::where('employee_id', $this->employee->id)->count());
    }

    public function test_grace_period_and_late_calculation(): void
    {
        $service = app(AttendanceProcessingService::class);
        $shiftStart = Carbon::parse('2026-09-29 09:00:00');

        // On time (before grace period cutoff)
        $this->assertEquals(0, $service->calculateLateMinutes('2026-09-29 08:58:00', $shiftStart, 15));
        $this->assertEquals(0, $service->calculateLateMinutes('2026-09-29 09:15:00', $shiftStart, 15));

        // Late (after grace cutoff: 09:20 is 20 min late from scheduled start)
        $this->assertEquals(20, $service->calculateLateMinutes('2026-09-29 09:20:00', $shiftStart, 15));
    }

    public function test_early_out_calculation(): void
    {
        $service = app(AttendanceProcessingService::class);
        $shiftEnd = Carbon::parse('2026-09-29 18:00:00');

        // On time (within 30m early out threshold)
        $this->assertEquals(0, $service->calculateEarlyOutMinutes('2026-09-29 17:35:00', $shiftEnd, 30));
        $this->assertEquals(0, $service->calculateEarlyOutMinutes('2026-09-29 18:05:00', $shiftEnd, 30));

        // Early out (leaving at 17:15 is 45 min before shift end)
        $this->assertEquals(45, $service->calculateEarlyOutMinutes('2026-09-29 17:15:00', $shiftEnd, 30));
    }

    public function test_workday_cycle_calculates_net_hours_and_overtime(): void
    {
        $service = app(AttendanceProcessingService::class);
        $date = Carbon::parse('2026-09-29');

        // Clock in at 08:50 AM
        $service->processPunch($this->employee, $date->copy()->setHour(8)->setMinute(50), 'in', $this->entryCam);

        // Clock out at 18:50 PM (10 hours raw, 60m break -> 9 hours net -> 1 hour overtime)
        $service->processPunch($this->employee, $date->copy()->setHour(18)->setMinute(50), 'out', $this->exitCam);

        $record = AttendanceRecord::where('employee_id', $this->employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals(9.0, $record->total_work_hours);
        $this->assertEquals(1.0, $record->overtime_hours);
        $this->assertEquals('present', $record->status);
        $this->assertFalse($record->is_late);
        $this->assertFalse($record->is_early_out);
    }

    public function test_webhook_verify_triggers_attendance_job(): void
    {
        $response = $this->postJson('/Subscribe/Verify', [
            'operator' => 'VerifyPush',
            'info' => [
                'DeviceID' => 'CAM-ENT-01',
                'PersonID' => 101,
                'CustomizeID' => $this->personnel->customize_id,
                'Name' => $this->personnel->name,
                'PersonType' => 0,
                'VerifyStatus' => 1,
                'Similarity1' => 96.5,
                'CreateTime' => '2026-09-29 08:52:00',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('access_logs', [
            'device_id' => 'CAM-ENT-01',
            'customize_id' => $this->personnel->customize_id,
        ]);

        // Verify that punch was created
        $this->assertDatabaseHas('attendance_punches', [
            'employee_id' => $this->employee->id,
            'direction' => 'in',
        ]);
    }

    public function test_daily_attendance_finalizer_marks_non_clocked_as_absent(): void
    {
        $date = Carbon::parse('2026-09-29');

        // Run finalizer on employee who did not clock in
        $job = new DailyAttendanceFinalizerJob($date);
        $job->handle(app(AttendanceProcessingService::class));

        $record = AttendanceRecord::where('employee_id', $this->employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('absent', $record->status);
    }
}
