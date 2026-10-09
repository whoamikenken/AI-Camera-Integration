<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RegularizationRequest;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\RegularizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialMilestone3Challenger1Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUserA;
    protected User $employeeUserB;
    protected Employee $employeeA;
    protected Employee $employeeB;
    protected Organization $org;
    protected Shift $shift;
    protected LeaveType $vacationType;
    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Adversarial Defense Systems',
            'code' => 'ADS-CORP',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day Shift',
            'code' => 'STD-0817',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'grace_period_minutes' => 15,
            'early_out_threshold_minutes' => 15,
            'is_active' => true,
        ]);

        $this->device = Device::create([
            'device_id' => 'CAM-CHALLENGE-M3-01',
            'name' => 'Challenger Entry Gate',
            'ip_address' => '192.168.10.150',
            'device_role' => 'bidirectional',
            'is_active' => true,
        ]);

        $this->vacationType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Executive Vacation',
            'code' => 'EXEC-VAC',
            'max_days_per_year' => 20.0,
            'is_paid' => true,
            'is_carry_forward' => true,
            'max_carry_forward_days' => 10.0,
        ]);

        // Roles & Permissions setup
        $adminRole = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        $employeeRole = Role::firstOrCreate(
            ['slug' => 'employee'],
            ['name' => 'Standard Employee', 'is_system' => true]
        );

        $permLeavesApply = Permission::firstOrCreate(['slug' => 'leaves.apply'], ['name' => 'Apply Leaves', 'group' => 'leaves']);
        $permLeavesManage = Permission::firstOrCreate(['slug' => 'leaves.manage'], ['name' => 'Manage Leaves', 'group' => 'leaves']);
        $permSelfService = Permission::firstOrCreate(['slug' => 'selfservice.view'], ['name' => 'Self Service View', 'group' => 'selfservice']);
        $permAttendanceView = Permission::firstOrCreate(['slug' => 'attendance.view'], ['name' => 'View Attendance', 'group' => 'attendance']);
        $permAttendanceManage = Permission::firstOrCreate(['slug' => 'attendance.manage'], ['name' => 'Manage Attendance', 'group' => 'attendance']);

        $employeeRole->permissions()->sync([
            $permLeavesApply->id,
            $permSelfService->id,
            $permAttendanceView->id,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        // Employee A
        $this->employeeUserA = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->employeeUserA->roles()->sync([$employeeRole->id]);

        $this->employeeA = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->employeeUserA->id,
            'employee_code' => 'EMP-ADV-001',
            'first_name' => 'Alice',
            'last_name' => 'Challenger',
            'employment_status' => 'active',
        ]);

        // Employee B
        $this->employeeUserB = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->employeeUserB->roles()->sync([$employeeRole->id]);

        $this->employeeB = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->employeeUserB->id,
            'employee_code' => 'EMP-ADV-002',
            'first_name' => 'Bob',
            'last_name' => 'Target',
            'employment_status' => 'active',
        ]);
    }

    /**
     * Helper to assert balance conservation equation:
     * allocated + carried_over = used + pending + available
     */
    private function assertBalanceConserved(LeaveBalance $balance, string $context = ''): void
    {
        $balance->refresh();
        $totalEntitlement = (float) $balance->allocated + (float) $balance->carried_over;
        $totalAccounted = (float) $balance->used + (float) $balance->pending + (float) $balance->available;

        $this->assertEqualsWithDelta(
            $totalEntitlement,
            $totalAccounted,
            0.001,
            "INVARIANT VIOLATION [{$context}]: allocated ({$balance->allocated}) + carried_over ({$balance->carried_over}) [{$totalEntitlement}] != used ({$balance->used}) + pending ({$balance->pending}) + available ({$balance->available}) [{$totalAccounted}]"
        );
    }

    // =========================================================================
    // 1. Double Cancellation Attacks (HTTP 422)
    // =========================================================================

    public function test_double_cancellation_attack_on_pending_leave_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 10.0,
            'used' => 0.0,
            'pending' => 2.0,
            'carried_over' => 0.0,
        ]);
        $this->assertBalanceConserved($balance, 'Initial pending');

        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'total_days' => 2.0,
            'status' => 'pending',
            'reason' => 'First planned trip',
        ]);

        // Attempt 1: Valid cancellation
        $resp1 = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Trip called off',
        ]);
        $resp1->assertStatus(200);
        $this->assertEquals('cancelled', $leaveReq->fresh()->status);
        $this->assertBalanceConserved($balance, 'After 1st cancellation');
        $this->assertEquals(0.0, (float) $balance->fresh()->pending);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);

        // Attempt 2: Adversarial duplicate cancellation attack
        $resp2 = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Duplicate cancellation attack',
        ]);
        $resp2->assertStatus(422);
        $resp2->assertJsonFragment([
            'message' => "Cannot cancel leave request with status 'cancelled'.",
        ]);

        // Balance MUST NOT be decremented again
        $this->assertBalanceConserved($balance, 'After duplicate cancellation attempt');
        $this->assertEquals(0.0, (float) $balance->fresh()->pending);
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);
    }

    public function test_double_cancellation_attack_on_approved_leave_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 10.0,
            'used' => 3.0,
            'pending' => 0.0,
            'carried_over' => 0.0,
        ]);
        $this->assertBalanceConserved($balance, 'Initial approved');

        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-12',
            'total_days' => 3.0,
            'status' => 'approved',
            'reason' => 'Annual conference',
        ]);

        // Attempt 1: First cancel succeeds
        $resp1 = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Conference moved online',
        ]);
        $resp1->assertStatus(200);
        $this->assertEquals('cancelled', $leaveReq->fresh()->status);
        $this->assertBalanceConserved($balance, 'After 1st approved cancellation');
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);

        // Attempt 2: Second cancel must be rejected with 422
        $resp2 = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Re-cancelling conference leave',
        ]);
        $resp2->assertStatus(422);
        $this->assertBalanceConserved($balance, 'After 2nd cancellation attack');
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);
    }

    public function test_double_cancellation_attack_on_regularization_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $regReq = RegularizationRequest::create([
            'employee_id' => $this->employeeA->id,
            'date' => '2026-10-01',
            'requested_in' => '2026-10-01 08:05:00',
            'requested_out' => '2026-10-01 17:05:00',
            'status' => 'pending',
            'reason' => 'Turnstile badge reader offline',
        ]);

        // Attempt 1: First cancel succeeds
        $resp1 = $this->postJson("/api/regularization-requests/{$regReq->id}/cancel", [
            'reason' => 'Badge reader actually recorded',
        ]);
        $resp1->assertStatus(200);
        $this->assertEquals('cancelled', $regReq->fresh()->status);

        // Attempt 2: Second cancel must fail with 422
        $resp2 = $this->postJson("/api/regularization-requests/{$regReq->id}/cancel", [
            'reason' => 'Attacking with duplicate cancellation',
        ]);
        $resp2->assertStatus(422);
        $resp2->assertJsonFragment([
            'message' => "Cannot cancel regularization with status 'cancelled'.",
        ]);
    }

    // =========================================================================
    // 2. Cancellation of Rejected Requests (HTTP 422)
    // =========================================================================

    public function test_cancellation_of_rejected_leave_request_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 10.0,
            'used' => 0.0,
            'pending' => 0.0,
            'carried_over' => 0.0,
        ]);

        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-16',
            'total_days' => 2.0,
            'status' => 'rejected',
            'rejection_reason' => 'Team coverage conflict',
        ]);

        $response = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Attempting to cancel already rejected leave',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "Cannot cancel leave request with status 'rejected'.",
        ]);

        // Ensure balance remains unmodified
        $this->assertBalanceConserved($balance, 'Rejected cancellation attack');
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(0.0, (float) $balance->fresh()->pending);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);

        // Ensure service layer also directly throws ValidationException
        $this->expectException(ValidationException::class);
        app(LeaveService::class)->cancelLeaveRequest($leaveReq);
    }

    public function test_cancellation_of_rejected_regularization_request_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $regReq = RegularizationRequest::create([
            'employee_id' => $this->employeeA->id,
            'date' => '2026-10-02',
            'requested_in' => '2026-10-02 08:30:00',
            'requested_out' => '2026-10-02 17:30:00',
            'status' => 'rejected',
            'rejection_reason' => 'No supervisor verification',
            'reason' => 'Missed clock-in',
        ]);

        $response = $this->postJson("/api/regularization-requests/{$regReq->id}/cancel", [
            'reason' => 'Cancelling rejected regularization',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "Cannot cancel regularization with status 'rejected'.",
        ]);

        // Ensure service layer throws ValidationException
        $this->expectException(ValidationException::class);
        app(RegularizationService::class)->cancelRegularization($regReq);
    }

    // =========================================================================
    // 3. Regularization Invariants (Cancel Approved Must Fail with 422)
    // =========================================================================

    public function test_cancellation_of_approved_regularization_request_fails_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $regReq = RegularizationRequest::create([
            'employee_id' => $this->employeeA->id,
            'date' => '2026-10-03',
            'requested_in' => '2026-10-03 08:00:00',
            'requested_out' => '2026-10-03 17:00:00',
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->adminUser->id,
            'reason' => 'Manager pre-approved on-site audit',
        ]);

        $response = $this->postJson("/api/regularization-requests/{$regReq->id}/cancel", [
            'reason' => 'Trying to revoke approved regularization',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "Cannot cancel regularization with status 'approved'.",
        ]);

        // Ensure service layer throws ValidationException
        $this->expectException(ValidationException::class);
        app(RegularizationService::class)->cancelRegularization($regReq);
    }

    // =========================================================================
    // 4. Balance Conservation Invariant Across Multi-Stage Transitions
    // =========================================================================

    public function test_balance_conservation_invariant_across_multi_step_lifecycle(): void
    {
        $service = app(LeaveService::class);

        // Step 0: Initial allocation
        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 15.0,
            'carried_over' => 5.0,
            'used' => 0.0,
            'pending' => 0.0,
        ]);
        $this->assertBalanceConserved($balance, 'Step 0: Initial allocation');
        $this->assertEquals(20.0, (float) $balance->available);

        // Step 1: Submit pending leave 1 (3 days)
        $req1 = $service->submitLeaveRequest($this->employeeA, $this->vacationType->id, '2026-11-02', '2026-11-04', 'Vacation 1');
        $this->assertEquals(3.0, (float) $req1->total_days);
        $this->assertBalanceConserved($balance, 'Step 1: Submit Req 1 (3 days)');
        $this->assertEquals(3.0, (float) $balance->fresh()->pending);
        $this->assertEquals(17.0, (float) $balance->fresh()->available);

        // Step 2: Submit pending leave 2 (4 days)
        $req2 = $service->submitLeaveRequest($this->employeeA, $this->vacationType->id, '2026-11-09', '2026-11-12', 'Vacation 2');
        $this->assertEquals(4.0, (float) $req2->total_days);
        $this->assertBalanceConserved($balance, 'Step 2: Submit Req 2 (4 days)');
        $this->assertEquals(7.0, (float) $balance->fresh()->pending);
        $this->assertEquals(13.0, (float) $balance->fresh()->available);

        // Step 3: Approve leave 1 (3 days)
        $service->approveLeaveRequest($req1, $this->adminUser);
        $this->assertBalanceConserved($balance, 'Step 3: Approve Req 1');
        $this->assertEquals(3.0, (float) $balance->fresh()->used);
        $this->assertEquals(4.0, (float) $balance->fresh()->pending);
        $this->assertEquals(13.0, (float) $balance->fresh()->available);

        // Step 4: Reject leave 2 (4 days)
        $service->rejectLeaveRequest($req2, 'Rejected due to staffing', $this->adminUser);
        $this->assertBalanceConserved($balance, 'Step 4: Reject Req 2');
        $this->assertEquals(3.0, (float) $balance->fresh()->used);
        $this->assertEquals(0.0, (float) $balance->fresh()->pending);
        $this->assertEquals(17.0, (float) $balance->fresh()->available);

        // Step 5: Cancel approved leave 1 (3 days)
        $service->cancelLeaveRequest($req1, $this->adminUser, 'Cancelled conference');
        $this->assertBalanceConserved($balance, 'Step 5: Cancel Approved Req 1');
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(0.0, (float) $balance->fresh()->pending);
        $this->assertEquals(20.0, (float) $balance->fresh()->available);
    }

    // =========================================================================
    // 5. Partial-Day Leave Cancellation (0.5 Day Exact Float Restoration)
    // =========================================================================

    public function test_partial_day_leave_cancellation_restores_exact_half_day_increment(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 10.0,
            'used' => 2.5,
            'pending' => 0.5,
            'carried_over' => 0.0,
        ]);
        $this->assertBalanceConserved($balance, 'Initial fractional state');
        $this->assertEquals(7.0, (float) $balance->available);

        // 1. Cancel pending 0.5-day request
        $pendingReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'total_days' => 0.5,
            'status' => 'pending',
            'reason' => 'Morning medical checkup',
        ]);

        $resp1 = $this->postJson("/api/leave-requests/{$pendingReq->id}/cancel", [
            'reason' => 'Checkup cancelled',
        ]);
        $resp1->assertStatus(200);

        $balance->refresh();
        $this->assertBalanceConserved($balance, 'After pending 0.5-day cancel');
        $this->assertEquals(0.0, (float) $balance->pending);
        $this->assertEquals(2.5, (float) $balance->used);
        $this->assertEquals(7.5, (float) $balance->available);

        // 2. Cancel approved 0.5-day request
        $approvedReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'total_days' => 0.5,
            'status' => 'approved',
            'reason' => 'Afternoon dental procedure',
        ]);

        $resp2 = $this->postJson("/api/leave-requests/{$approvedReq->id}/cancel", [
            'reason' => 'Dental appointment rescheduled',
        ]);
        $resp2->assertStatus(200);

        $balance->refresh();
        $this->assertBalanceConserved($balance, 'After approved 0.5-day cancel');
        $this->assertEquals(2.0, (float) $balance->used);
        $this->assertEquals(0.0, (float) $balance->pending);
        $this->assertEquals(8.0, (float) $balance->available);
    }

    public function test_fractional_sequences_prevent_floating_point_drift(): void
    {
        $service = app(LeaveService::class);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 5.0,
            'carried_over' => 0.0,
            'used' => 0.0,
            'pending' => 0.0,
        ]);

        // Ten 0.5-day requests submitted and cancelled
        $requests = [];
        for ($i = 0; $i < 5; $i++) {
            $req = LeaveRequest::create([
                'employee_id' => $this->employeeA->id,
                'leave_type_id' => $this->vacationType->id,
                'start_date' => Carbon::parse('2026-12-01')->addDays($i)->toDateString(),
                'end_date' => Carbon::parse('2026-12-01')->addDays($i)->toDateString(),
                'total_days' => 0.5,
                'status' => 'approved',
            ]);
            $balance->used += 0.5;
            $balance->save();
            $requests[] = $req;
        }

        $balance->refresh();
        $this->assertEquals(2.5, (float) $balance->used);
        $this->assertEquals(2.5, (float) $balance->available);
        $this->assertBalanceConserved($balance, 'After 5 half-day approvals');

        // Cancel all five requests in reverse order
        foreach (array_reverse($requests) as $idx => $req) {
            $service->cancelLeaveRequest($req, $this->adminUser, 'Rollback ' . $idx);
            $this->assertBalanceConserved($balance, "After cancellation of half-day {$idx}");
        }

        $balance->refresh();
        $this->assertEquals(0.0, (float) $balance->used);
        $this->assertEquals(5.0, (float) $balance->available);
    }

    // =========================================================================
    // 6. Attendance Rollback & Re-evaluation Invariants
    // =========================================================================

    public function test_attendance_rollback_reevaluates_presence_when_punches_exist(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Yesterday was a working day
        $yesterday = Carbon::yesterday();
        if ($yesterday->isWeekend()) {
            $yesterday = Carbon::parse('2026-10-06'); // A known Tuesday
        }
        $workDate = $yesterday->toDateString();

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => (int) $yesterday->year,
            'allocated' => 10.0,
            'used' => 1.0,
            'pending' => 0.0,
            'carried_over' => 0.0,
        ]);

        // Approved leave previously existed
        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => $workDate,
            'end_date' => $workDate,
            'total_days' => 1.0,
            'status' => 'approved',
            'reason' => 'Emergency personal day',
        ]);

        // Attendance record marked on_leave
        $attRecord = AttendanceRecord::create([
            'employee_id' => $this->employeeA->id,
            'date' => $workDate,
            'status' => 'on_leave',
            'remarks' => 'Approved leave: Executive Vacation',
        ]);

        // Employee was unexpectedly called into office: physical punches captured
        AttendancePunch::create([
            'employee_id' => $this->employeeA->id,
            'device_id' => $this->device->device_id,
            'punch_time' => "{$workDate} 08:00:00",
            'direction' => 'in',
            'source' => 'device',
        ]);
        AttendancePunch::create([
            'employee_id' => $this->employeeA->id,
            'device_id' => $this->device->device_id,
            'punch_time' => "{$workDate} 17:00:00",
            'direction' => 'out',
            'source' => 'device',
        ]);

        // Cancel the leave request
        $response = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Employee reported to site, cancelling leave',
        ]);
        $response->assertStatus(200);

        // Verification: AttendanceRecord MUST be recalculated to 'present'
        $attRecord->refresh();
        $this->assertEquals('present', $attRecord->status, 'Attendance record must be re-evaluated to present when punches exist');
        $this->assertNull($attRecord->remarks, 'Remarks should be cleared or updated');

        // Balance restored
        $balance->refresh();
        $this->assertEquals(0.0, (float) $balance->used);
        $this->assertEquals(10.0, (float) $balance->available);
    }

    public function test_attendance_rollback_deletes_speculative_future_leave_records(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $futureDate = Carbon::tomorrow()->addDays(5);
        if ($futureDate->isWeekend()) {
            $futureDate = $futureDate->next(Carbon::MONDAY);
        }
        $dateStr = $futureDate->toDateString();

        LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => (int) $futureDate->year,
            'allocated' => 10.0,
            'used' => 1.0,
            'pending' => 0.0,
            'carried_over' => 0.0,
        ]);

        $leaveReq = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => $dateStr,
            'end_date' => $dateStr,
            'total_days' => 1.0,
            'status' => 'approved',
            'reason' => 'Future vacation',
        ]);

        AttendanceRecord::create([
            'employee_id' => $this->employeeA->id,
            'date' => $dateStr,
            'status' => 'on_leave',
        ]);

        // Cancel the future leave
        $response = $this->postJson("/api/leave-requests/{$leaveReq->id}/cancel", [
            'reason' => 'Plans changed',
        ]);
        $response->assertStatus(200);

        // Speculative future record must be removed completely
        $record = AttendanceRecord::where('employee_id', $this->employeeA->id)
            ->whereDate('date', $dateStr)
            ->first();
        $this->assertNull($record, 'Speculative future attendance record must be deleted upon leave cancellation');
    }

    // =========================================================================
    // 7. Authorization & Cross-User Isolation (HTTP 403)
    // =========================================================================

    public function test_cross_user_leave_cancellation_attack_rejected_with_403(): void
    {
        // Authenticate as Employee A
        Sanctum::actingAs($this->employeeUserA, ['*']);

        // Leave request belongs to Employee B
        $leaveReqB = LeaveRequest::create([
            'employee_id' => $this->employeeB->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-26',
            'total_days' => 2.0,
            'status' => 'pending',
            'reason' => 'Bob vacation',
        ]);

        // Alice attempts to cancel Bob's leave request
        $response = $this->postJson("/api/leave-requests/{$leaveReqB->id}/cancel", [
            'reason' => 'Malicious cancellation by peer',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'You cannot cancel leave requests for other employees.',
        ]);
    }

    public function test_cross_user_regularization_cancellation_attack_rejected_with_403(): void
    {
        // Authenticate as Employee A
        Sanctum::actingAs($this->employeeUserA, ['*']);

        // Regularization request belongs to Employee B
        $regReqB = RegularizationRequest::create([
            'employee_id' => $this->employeeB->id,
            'date' => '2026-10-05',
            'requested_in' => '2026-10-05 08:00:00',
            'requested_out' => '2026-10-05 17:00:00',
            'status' => 'pending',
            'reason' => 'Bob missed punch',
        ]);

        // Alice attempts to cancel Bob's regularization request
        $response = $this->postJson("/api/regularization-requests/{$regReqB->id}/cancel", [
            'reason' => 'Malicious cancellation by peer',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'You cannot cancel regularization requests for other employees.',
        ]);
    }

    // =========================================================================
    // 8. Concurrency & Interleaved Cancellation Resilience
    // =========================================================================

    public function test_concurrency_race_resilience_on_two_distinct_leave_cancellations(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Employee A has 15 days allocated
        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 15.0,
            'used' => 5.0,
            'pending' => 0.0,
            'carried_over' => 0.0,
        ]);
        $this->assertBalanceConserved($balance, 'Initial concurrent test state');

        // Two distinct approved leave requests
        $req1 = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-03',
            'total_days' => 2.0,
            'status' => 'approved',
            'reason' => 'Trip 1',
        ]);

        $req2 = LeaveRequest::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'start_date' => '2026-11-05',
            'end_date' => '2026-11-07',
            'total_days' => 3.0,
            'status' => 'approved',
            'reason' => 'Trip 2',
        ]);

        // Cancel req1
        $resp1 = $this->postJson("/api/leave-requests/{$req1->id}/cancel", ['reason' => 'Cancel 1']);
        $resp1->assertStatus(200);

        // Cancel req2
        $resp2 = $this->postJson("/api/leave-requests/{$req2->id}/cancel", ['reason' => 'Cancel 2']);
        $resp2->assertStatus(200);

        // Invariant: Balance must reflect cumulative restoration of both (5.0 days)
        $balance->refresh();
        $this->assertBalanceConserved($balance, 'After both concurrent cancellations');
        $this->assertEquals(0.0, (float) $balance->used, 'Used days must decrement from 5.0 to 0.0');
        $this->assertEquals(15.0, (float) $balance->available, 'Available must return to full 15.0');
    }

    // =========================================================================
    // 9. Visit Cancellation Invariants (Checked-Out, No-Show, Duplicate)
    // =========================================================================

    public function test_visit_cancellation_invariants_reject_invalid_states_with_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $visitor = \App\Models\Visitor::create([
            'first_name' => 'Adversarial',
            'last_name' => 'Visitor',
            'email' => 'adv.vis@example.com',
            'phone' => '+639123456789',
        ]);

        // Case A: Duplicate cancellation on expected visit
        $visitExpected = \App\Models\Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $this->employeeA->id,
            'purpose' => 'Security Audit',
            'status' => 'expected',
            'expected_check_in' => now()->addHour(),
        ]);

        $respCancel1 = $this->postJson("/api/visits/{$visitExpected->id}/cancel", ['reason' => 'Audit postponed']);
        $respCancel1->assertStatus(200);
        $this->assertEquals('cancelled', $visitExpected->fresh()->status);

        $respCancel2 = $this->postJson("/api/visits/{$visitExpected->id}/cancel", ['reason' => 'Duplicate cancel attack']);
        $respCancel2->assertStatus(422);
        $respCancel2->assertJsonFragment([
            'message' => "Cannot cancel visit with status 'cancelled'.",
        ]);

        // Case B: Cancel already checked-out visit
        $visitCheckedOut = \App\Models\Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $this->employeeA->id,
            'purpose' => 'Completed meeting',
            'status' => 'checked_out',
            'check_in_time' => now()->subHours(2),
            'check_out_time' => now()->subHour(),
        ]);

        $respCheckedOut = $this->postJson("/api/visits/{$visitCheckedOut->id}/cancel", ['reason' => 'Post-checkout cancel']);
        $respCheckedOut->assertStatus(422);
        $respCheckedOut->assertJsonFragment([
            'message' => "Cannot cancel visit with status 'checked_out'.",
        ]);

        // Case C: Cancel expired no-show visit
        $visitNoShow = \App\Models\Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $this->employeeA->id,
            'purpose' => 'Abandoned visit',
            'status' => 'no_show',
            'expected_check_in' => now()->subDay(),
        ]);

        $respNoShow = $this->postJson("/api/visits/{$visitNoShow->id}/cancel", ['reason' => 'No show cancel']);
        $respNoShow->assertStatus(422);
        $respNoShow->assertJsonFragment([
            'message' => "Cannot cancel visit with status 'no_show'.",
        ]);
    }

    // =========================================================================
    // 10. Multi-Day Leave Spanning Weekends & Public Holidays
    // =========================================================================

    public function test_multi_day_leave_cancellation_respects_weekends_and_holidays(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $service = app(LeaveService::class);

        // Date range: 2026-10-09 (Fri) to 2026-10-13 (Tue)
        // Oct 09: Fri (working)
        // Oct 10: Sat (weekend)
        // Oct 11: Sun (weekend)
        // Oct 12: Mon (Holiday)
        // Oct 13: Tue (working)
        // Expected working days: Exactly 2.0 (Friday and Tuesday)

        Holiday::create([
            'name' => 'Mid-October Holiday',
            'date' => '2026-10-12',
            'type' => 'public',
        ]);

        $balance = LeaveBalance::create([
            'employee_id' => $this->employeeA->id,
            'leave_type_id' => $this->vacationType->id,
            'year' => 2026,
            'allocated' => 10.0,
            'carried_over' => 0.0,
            'used' => 0.0,
            'pending' => 0.0,
        ]);

        // Submit leave request for Fri-Tue
        $leaveReq = $service->submitLeaveRequest(
            $this->employeeA,
            $this->vacationType->id,
            '2026-10-09',
            '2026-10-13',
            'Weekend bridge trip'
        );

        $this->assertEquals(2.0, (float) $leaveReq->total_days, 'Must calculate exactly 2 working days, excluding weekend and holiday');
        $this->assertBalanceConserved($balance, 'After weekend/holiday submit');
        $this->assertEquals(2.0, (float) $balance->fresh()->pending);
        $this->assertEquals(8.0, (float) $balance->fresh()->available);

        // Approve leave
        $service->approveLeaveRequest($leaveReq, $this->adminUser);
        $this->assertBalanceConserved($balance, 'After weekend/holiday approval');
        $this->assertEquals(2.0, (float) $balance->fresh()->used);
        $this->assertEquals(8.0, (float) $balance->fresh()->available);

        // Verify attendance records were NOT created for Saturday or Sunday
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $this->employeeA->id,
            'date' => '2026-10-10',
        ]);
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $this->employeeA->id,
            'date' => '2026-10-11',
        ]);

        // Cancel the leave
        $service->cancelLeaveRequest($leaveReq, $this->adminUser, 'Cancelled bridge trip');
        $this->assertBalanceConserved($balance, 'After weekend/holiday cancellation');
        $this->assertEquals(0.0, (float) $balance->fresh()->used);
        $this->assertEquals(10.0, (float) $balance->fresh()->available);
    }
}

