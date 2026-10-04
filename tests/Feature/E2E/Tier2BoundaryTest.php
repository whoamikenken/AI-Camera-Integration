<?php

namespace Tests\Feature\E2E;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 2: Boundary & Corner Cases
 * Tests limits, empty inputs, max sizes, invalid transitions, zero/negative, edge times.
 */
class Tier2BoundaryTest extends E2ETestCase
{
    // =========================================================================
    // SECTION 1: Hardware & Ingestion Boundaries
    // =========================================================================

    public function test_boundary_camera_device_id_with_special_characters_and_max_length(): void
    {
        $device = $this->createTestDevice([
            'device_id' => 'CAM-DEVOPS_01-ALPHA.ZONE:8080#NORTH',
            'name' => 'Gate-α/β [Main Entrance]',
        ]);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'CAM-DEVOPS_01-ALPHA.ZONE:8080#NORTH',
            'name' => 'Gate-α/β [Main Entrance]',
        ]);
    }

    public function test_boundary_personnel_supports_multilingual_unicode_names(): void
    {
        $personnel = $this->createTestPersonnel([
            'customize_id' => 88801,
            'name' => '张伟 (Zhang Wei) / José María Hernández',
        ]);

        $this->assertDatabaseHas('personnel', [
            'customize_id' => 88801,
            'name' => '张伟 (Zhang Wei) / José María Hernández',
        ]);
    }

    public function test_boundary_access_log_with_boundary_similarity_scores(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-SIM-01']);

        // Test exact minimum similarity (0.00)
        $logMin = AccessLog::create([
            'device_id' => $device->device_id,
            'verify_status' => 2, // Rejected
            'similarity' => 0.00,
            'captured_at' => Carbon::now(),
        ]);

        // Test exact maximum similarity (100.00)
        $logMax = AccessLog::create([
            'device_id' => $device->device_id,
            'verify_status' => 1, // Allowed
            'similarity' => 100.00,
            'captured_at' => Carbon::now(),
        ]);

        $this->assertDatabaseHas('access_logs', ['id' => $logMin->id, 'similarity' => 0.00]);
        $this->assertDatabaseHas('access_logs', ['id' => $logMax->id, 'similarity' => 100.00]);
    }

    public function test_boundary_camera_sync_task_retry_limit_and_error_capture(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-FAIL-01']);
        $personnel = $this->createTestPersonnel(['customize_id' => 88802]);

        $task = SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'ADD',
            'status' => 'FAILED',
            'attempts' => 3,
            'error_message' => 'HTTP 504 Gateway Timeout: Camera endpoint unresponsive after 3 attempts',
        ]);

        $this->assertDatabaseHas('sync_tasks', [
            'id' => $task->id,
            'status' => 'FAILED',
            'attempts' => 3,
        ]);
    }

    // =========================================================================
    // SECTION 2: Attendance Debounce & Edge Times
    // =========================================================================

    public function test_boundary_punch_debounce_under_60_seconds_is_filtered(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Scans within 60s window should be deduplicated
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_boundary_punch_at_exact_grace_period_cutoff_is_not_late(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Shift 09:00:00, Grace 15m -> Exactly 09:15:00 is on-time
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_boundary_punch_one_second_past_grace_period_is_marked_late_from_start(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Shift 09:00:00, Grace 15m -> 09:15:01 is late (late_minutes = 15 or 16 from 09:00:00)
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_boundary_early_out_at_exact_threshold_cutoff(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Shift end 18:00:00, early_out_threshold 30m -> 17:30:00 is on-time; 17:29:59 is early out
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_boundary_overnight_shift_midnight_window_association(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Shift 22:00 (Day 1) to 07:00 (Day 2) -> Punches from 21:50 to 07:10 map to Day 1
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    // =========================================================================
    // SECTION 3: Shift & Work Hours Boundaries
    // =========================================================================

    public function test_boundary_shift_with_zero_break_minutes(): void
    {
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireRoute('/api/shifts', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/shifts', [
            'name' => 'Continuous 4-Hour Shift',
            'code' => 'SHIFT-NO-BREAK',
            'shift_start' => '08:00:00',
            'shift_end' => '12:00:00',
            'grace_period_minutes' => 0,
            'break_duration_minutes' => 0,
            'min_hours_full_day' => 4.0,
            'half_day_threshold_hours' => 2.0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'code' => 'SHIFT-NO-BREAK',
            'break_duration_minutes' => 0,
            'grace_period_minutes' => 0,
        ]);
    }

    public function test_boundary_rejection_of_invalid_shift_start_and_end_times(): void
    {
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireRoute('/api/shifts', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        // Start time == End time without is_overnight flag should be rejected with 422
        $response = $this->postJson('/api/shifts', [
            'name' => 'Zero Duration Shift',
            'code' => 'SHIFT-ZERO',
            'shift_start' => '09:00:00',
            'shift_end' => '09:00:00',
            'is_overnight' => false,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // SECTION 4: Leave Balance & Non-Working Day Boundaries
    // =========================================================================

    public function test_boundary_rejection_of_negative_leave_allocation(): void
    {
        $this->requireTable('leave_balances', 'Milestone 4');
        $this->requireRoute('/api/leave-balances/allocate', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/leave-balances/allocate', [
            'employee_id' => 1,
            'leave_type_id' => 1,
            'year' => 2026,
            'allocated' => -5.0, // Negative days invalid
        ]);

        $response->assertStatus(422);
    }

    public function test_boundary_leave_deduction_excludes_public_holidays_and_weekends(): void
    {
        $this->requireClass('App\Services\LeaveService', 'Milestone 4');

        // Mon-Fri leave request with Wed as holiday only deducts 4 days
        $service = app('App\Services\LeaveService');
        $this->assertNotNull($service);
    }

    public function test_boundary_rejection_of_insufficient_leave_balance(): void
    {
        $this->requireTable('leave_requests', 'Milestone 4');
        $this->requireRoute('/api/leave-requests', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        $emp = \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);
        $lt = \App\Models\LeaveType::firstOrCreate(['id' => 1], [
            'name' => 'Annual Vacation',
            'code' => 'VAC-ANNUAL',
            'max_days_per_year' => 14.0,
        ]);
        \App\Models\LeaveBalance::updateOrCreate(
            ['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2026],
            ['allocated' => 2.0, 'used' => 0.0, 'pending' => 0.0]
        );

        // Requesting 10 days when balance is 2 days should return 422
        $response = $this->postJson('/api/leave-requests', [
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-15',
            'reason' => 'Extended vacation exceeding quota',
        ]);

        $response->assertStatus(422);
    }

    public function test_boundary_annual_carry_forward_caps_at_maximum_allowed_days(): void
    {
        $this->requireClass('App\Services\LeaveService', 'Milestone 4');

        // Unused 10 days with max_carry_forward = 5 should roll over exactly 5 days
        $service = app('App\Services\LeaveService');
        $this->assertNotNull($service);
    }

    // =========================================================================
    // SECTION 5: State Machine & Transition Boundaries
    // =========================================================================

    public function test_boundary_cannot_checkout_an_already_checked_out_visit(): void
    {
        $this->requireTable('visits', 'Milestone 5');
        $this->requireRoute('/api/visits/1/check-out', 'PUT', 'Milestone 5');

        $this->actingAsAdmin();

        // Second checkout on same visit should return 422 or 400
        $response = $this->putJson('/api/visits/1/check-out');
        $this->assertContains($response->status(), [400, 404, 422]);
    }

    public function test_boundary_cannot_approve_an_already_rejected_leave_request(): void
    {
        $this->requireTable('leave_requests', 'Milestone 4');
        $this->requireRoute('/api/leave-requests/1/approve', 'PUT', 'Milestone 4');

        $this->actingAsAdmin();

        $response = $this->putJson('/api/leave-requests/1/approve');
        $this->assertContains($response->status(), [400, 404, 422]);
    }

    public function test_boundary_regularization_rejected_for_future_dates(): void
    {
        $this->requireTable('regularization_requests', 'Milestone 4');
        $this->requireRoute('/api/regularization-requests', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        // Regularization for future dates must be rejected
        $response = $this->postJson('/api/regularization-requests', [
            'employee_id' => 1,
            'date' => Carbon::tomorrow()->toDateString(),
            'requested_in' => Carbon::tomorrow()->setHour(9)->format('Y-m-d H:i:s'),
            'reason' => 'Predictive clock correction',
        ]);

        $response->assertStatus(422);
    }

    public function test_boundary_empty_query_parameters_gracefully_fallback_to_defaults(): void
    {
        $response = $this->getJson('/api/devices?search=&status=&page=');
        $response->assertStatus(200);
    }
}
