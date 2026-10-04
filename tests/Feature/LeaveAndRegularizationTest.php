<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveAndRegularizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Employee $employee;
    protected LeaveType $annualLeave;

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

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-LEAVE-01',
            'first_name' => 'Alice',
            'last_name' => 'Walker',
            'employment_status' => 'active',
        ]);

        $this->annualLeave = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual Vacation',
            'code' => 'ANNUAL-VAC',
            'max_days_per_year' => 14.0,
            'is_paid' => true,
            'is_carry_forward' => true,
            'max_carry_forward_days' => 5.0,
        ]);
    }

    public function test_leave_type_crud(): void
    {
        $response = $this->postJson('/api/leave-types', [
            'name' => 'Sick Leave',
            'code' => 'SICK-LEAVE',
            'max_days_per_year' => 10.0,
            'is_paid' => true,
        ]);
        $response->assertStatus(201);
        $typeId = $response->json('data.id');

        $this->assertDatabaseHas('leave_types', ['code' => 'SICK-LEAVE']);

        // Update
        $upResp = $this->putJson("/api/leave-types/{$typeId}", ['max_days_per_year' => 12.0]);
        $upResp->assertStatus(200);

        // Delete
        $delResp = $this->deleteJson("/api/leave-types/{$typeId}");
        $delResp->assertStatus(200);
        $this->assertDatabaseMissing('leave_types', ['id' => $typeId]);
    }

    public function test_leave_allocation_and_working_days_calculation(): void
    {
        $service = app(LeaveService::class);

        // Add a holiday in the middle of the week (e.g. Wednesday 2026-10-07)
        Holiday::create([
            'name' => 'Mid-Week Holiday',
            'date' => '2026-10-07',
            'type' => 'public',
        ]);

        // Mon Oct 05 to Fri Oct 09 (5 calendar days, 1 holiday -> 4 working days)
        $days = $service->calculateRequestedDays('2026-10-05', '2026-10-09');
        $this->assertEquals(4.0, $days);
    }

    public function test_leave_approval_lifecycle_and_attendance_update(): void
    {
        // 1. Allocate 10 days
        $this->postJson('/api/leave-balances/allocate', [
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'year' => 2026,
            'allocated' => 10.0,
        ])->assertStatus(200);

        // 2. Submit request (Mon Oct 05 to Wed Oct 07 -> 3 days)
        $submitResp = $this->postJson('/api/leave-requests', [
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-07',
            'reason' => 'Mid-autumn family leave',
        ]);
        $submitResp->assertStatus(201);
        $requestId = $submitResp->json('data.id');

        // Check balance pending
        $balance = LeaveBalance::where('employee_id', $this->employee->id)->where('year', 2026)->first();
        $this->assertEquals(3.0, $balance->pending);
        $this->assertEquals(7.0, $balance->available);

        // 3. Approve request
        $appResp = $this->putJson("/api/leave-requests/{$requestId}/approve");
        $appResp->assertStatus(200);

        // Check balance used
        $balance->refresh();
        $this->assertEquals(0.0, $balance->pending);
        $this->assertEquals(3.0, $balance->used);
        $this->assertEquals(7.0, $balance->available);

        // Check attendance records updated to on_leave
        $attRecord = AttendanceRecord::where('employee_id', $this->employee->id)
            ->whereDate('date', '2026-10-05')
            ->first();
        $this->assertNotNull($attRecord);
        $this->assertEquals('on_leave', $attRecord->status);
    }

    public function test_regularization_request_and_approval(): void
    {
        $resp = $this->postJson('/api/regularization-requests', [
            'employee_id' => $this->employee->id,
            'date' => '2026-09-28',
            'requested_in' => '2026-09-28 09:05:00',
            'requested_out' => '2026-09-28 18:10:00',
            'reason' => 'Facial reader glitched during rainstorm',
        ]);
        $resp->assertStatus(201);
        $regId = $resp->json('data.id');

        // Approve
        $appResp = $this->putJson("/api/regularization-requests/{$regId}/approve");
        $appResp->assertStatus(200);

        $this->assertDatabaseHas('regularization_requests', [
            'id' => $regId,
            'status' => 'approved',
        ]);

        // Punches created and attendance record recomputed
        $this->assertDatabaseHas('attendance_punches', [
            'employee_id' => $this->employee->id,
            'source' => 'regularized',
        ]);
    }

    public function test_year_end_carry_forward(): void
    {
        $service = app(LeaveService::class);

        // Allocate 10 days in 2025 with 2 used -> 8 unused
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'year' => 2025,
            'allocated' => 10.0,
            'used' => 2.0,
        ]);

        // Process carry forward into 2026 (max carry forward is 5.0 days)
        $service->processYearEndCarryForward(2025, 2026);

        $bal2026 = LeaveBalance::where('employee_id', $this->employee->id)->where('year', 2026)->first();
        $this->assertNotNull($bal2026);
        $this->assertEquals(5.0, $bal2026->carried_over);
    }
}
