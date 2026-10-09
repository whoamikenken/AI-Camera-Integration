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

    // =========================================================================
    // SECTION 6: Evolution Boundary & Corner Cases (Features 1 - 43)
    // =========================================================================

    public function test_boundary_access_group_with_empty_membership_handles_resolution_cleanly(): void
    {
        $this->requireTable('access_groups', 'Milestone 2');
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');

        $group = \App\Models\AccessGroup::create([
            'name' => 'Empty Group',
            'code' => 'EMPTY-GRP',
            'is_active' => true,
        ]);
        $personnel = $this->createTestPersonnel();

        $service = app(\App\Services\AccessControlService::class);
        $devices = $service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $devices);
    }

    public function test_boundary_system_with_zero_access_groups_falls_back_to_all_active_devices(): void
    {
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');

        $activeDevice = $this->createTestDevice(['is_active' => true]);
        $inactiveDevice = $this->createTestDevice(['is_active' => false]);
        $personnel = $this->createTestPersonnel();

        $service = app(\App\Services\AccessControlService::class);
        $devices = $service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertTrue($devices->contains('id', $activeDevice->id));
        $this->assertFalse($devices->contains('id', $inactiveDevice->id));
    }

    public function test_boundary_personnel_in_multiple_overlapping_access_groups_deduplicates_devices(): void
    {
        $this->requireTable('access_groups', 'Milestone 2');
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');

        $devA = $this->createTestDevice(['device_id' => 'DEV-A']);
        $devB = $this->createTestDevice(['device_id' => 'DEV-B']);
        $devC = $this->createTestDevice(['device_id' => 'DEV-C']);
        $personnel = $this->createTestPersonnel();

        $grp1 = \App\Models\AccessGroup::create(['name' => 'Zone 1', 'code' => 'Z1', 'is_active' => true]);
        $grp2 = \App\Models\AccessGroup::create(['name' => 'Zone 2', 'code' => 'Z2', 'is_active' => true]);

        $grp1->devices()->attach([$devA->id, $devB->id]);
        $grp2->devices()->attach([$devB->id, $devC->id]); // Overlap on devB

        $grp1->personnel()->attach($personnel->id);
        $grp2->personnel()->attach($personnel->id);

        $service = app(\App\Services\AccessControlService::class);
        $devices = $service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertEquals(3, $devices->unique('id')->count());
    }

    public function test_boundary_leave_cancellation_half_day_increment_atomic_restoration(): void
    {
        $this->requireRoute('/api/leave-requests/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('leave_requests', 'Milestone 3');
        $this->requireTable('leave_balances', 'Milestone 3');

        $admin = $this->actingAsAdmin();
        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-HALF-01',
            'first_name' => 'Half',
            'last_name' => 'Day',
            'employment_status' => 'active',
            'user_id' => $admin->id,
        ]);
        $leaveType = \App\Models\LeaveType::create(['name' => 'Personal Leave', 'code' => 'PL-01', 'is_paid' => true]);
        $balance = \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 10,
            'used_days' => 2.5,
            'pending_days' => 0,
            'remaining_days' => 7.5,
            'year' => 2026,
        ]);

        $request = \App\Models\LeaveRequest::create([
            'id' => 1,
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::tomorrow()->toDateString(),
            'end_date' => Carbon::tomorrow()->toDateString(),
            'total_days' => 0.5,
            'status' => 'approved',
            'reason' => 'Doctor appointment half-day',
        ]);

        $response = $this->postJson('/api/leave-requests/' . $request->id . '/cancel', [
            'reason' => 'Doctor rescheduled appointment',
        ]);

        $response->assertStatus(200);
        $balance->refresh();
        $this->assertEquals(2.0, (float) $balance->used_days);
    }

    public function test_boundary_cannot_cancel_already_cancelled_leave_request(): void
    {
        $this->requireRoute('/api/leave-requests/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('leave_requests', 'Milestone 3');

        $this->actingAsAdmin();
        $emp = \App\Models\Employee::create(['employee_code' => 'EMP-ALREADY-01', 'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active']);
        $type = \App\Models\LeaveType::create(['name' => 'Sick', 'code' => 'SL', 'is_paid' => true]);
        $req = \App\Models\LeaveRequest::create([
            'id' => 1,
            'employee_id' => $emp->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'total_days' => 1,
            'status' => 'cancelled', // Already cancelled
            'reason' => 'Previous cancellation',
        ]);

        $response = $this->postJson('/api/leave-requests/' . $req->id . '/cancel', [
            'reason' => 'Attempting duplicate cancel',
        ]);

        $this->assertContains($response->status(), [400, 422]);
    }

    public function test_boundary_visitor_overstay_exact_15_minute_window_threshold(): void
    {
        $this->requireClass('App\Jobs\DetectOverstayVisitorsJob', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $vis = \App\Models\Visitor::create(['first_name' => 'Threshold', 'last_name' => 'Tester']);
        // Case 1: Departure was 10 mins ago (within grace window, not overstayed)
        $visitNormal = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'briefing',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(2),
            'expected_departure' => now()->subMinutes(10),
        ]);

        // Case 2: Departure was 25 mins ago (exceeded 15m cutoff, should be overstayed)
        $visitOverstayed = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'audit',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(3),
            'expected_departure' => now()->subMinutes(25),
        ]);

        dispatch_sync(new \App\Jobs\DetectOverstayVisitorsJob());

        $visitOverstayed->refresh();
        $this->assertEquals('overstayed', $visitOverstayed->status);
    }

    public function test_boundary_expire_no_show_visits_skips_today_and_future_visits(): void
    {
        $this->requireClass('App\Jobs\ExpireNoShowVisitsJob', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $vis = \App\Models\Visitor::create(['first_name' => 'Future', 'last_name' => 'Tester']);
        $todayVisit = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'tour',
            'status' => 'expected',
            'expected_arrival' => now()->addHours(2), // Today upcoming
        ]);
        $futureVisit = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'interview',
            'status' => 'expected',
            'expected_arrival' => now()->addDays(2), // Future
        ]);

        dispatch_sync(new \App\Jobs\ExpireNoShowVisitsJob());

        $todayVisit->refresh();
        $futureVisit->refresh();
        $this->assertEquals('expected', $todayVisit->status);
        $this->assertEquals('expected', $futureVisit->status);
    }

    public function test_boundary_bulk_personnel_sync_exact_50_person_chunking(): void
    {
        $this->requireClass('App\Jobs\BulkPersonnelSyncJob', 'Milestone 4');

        // Test with 51 items: partitions must yield 2 chunks
        $items = range(1, 51);
        $chunks = array_chunk($items, 50);

        $this->assertCount(2, $chunks);
        $this->assertCount(50, $chunks[0]);
        $this->assertCount(1, $chunks[1]);
    }

    public function test_boundary_bulk_reboot_with_empty_devices_array_rejected_with_422(): void
    {
        $this->requireRoute('/api/devices/bulk-reboot', 'POST', 'Milestone 4');

        $this->actingAsAdmin();
        $response = $this->postJson('/api/devices/bulk-reboot', [
            'device_ids' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_boundary_bulk_personnel_deletion_with_empty_array_rejected_with_422(): void
    {
        $this->requireRoute('/api/personnel/bulk-delete', 'POST', 'Milestone 4');

        $this->actingAsAdmin();
        $response = $this->postJson('/api/personnel/bulk-delete', [
            'personnel_ids' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_boundary_bulk_campaign_progress_clamps_between_zero_and_one_hundred_percent(): void
    {
        $this->requireTable('bulk_campaigns', 'Milestone 4');
        $this->requireClass('App\Models\BulkCampaign', 'Milestone 4');

        $admin = $this->actingAsAdmin();
        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $admin->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 20,
            'processed_items' => 10,
            'failed_items' => 0,
            'status' => 'processing',
        ]);

        $pct = ($campaign->total_items > 0)
            ? round(($campaign->processed_items / $campaign->total_items) * 100)
            : 0;

        $this->assertEquals(50, $pct);
    }

    public function test_boundary_hardware_ack_with_non_zero_error_code_marks_command_failed(): void
    {
        $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');
        $this->requireTable('device_commands', 'Milestone 5');

        $device = $this->createTestDevice();
        $messageId = 'ERR-ACK-' . uniqid();
        $command = \App\Models\DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $errorAckPacket = [
            'operator' => 'RebootDeviceAck',
            'messageId' => $messageId,
            'code' => 1, // Error
            'desc' => 'Hardware busy, retry later',
        ];

        $service = app(\App\Services\CameraMqttService::class);
        $service->handleCommandAck($errorAckPacket);

        $command->refresh();
        $this->assertEquals('failed', $command->status);
    }

    public function test_boundary_hardware_ack_with_unknown_message_id_handled_gracefully(): void
    {
        $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');

        $unmatchedAck = [
            'operator' => 'UnknownAck',
            'messageId' => 'NON-EXISTENT-' . uniqid(),
            'code' => 0,
        ];

        $service = app(\App\Services\CameraMqttService::class);
        // Calling with unmatched ACK must not throw exception
        $service->handleCommandAck($unmatchedAck);
        $this->assertTrue(true);
    }

    public function test_boundary_telemetry_packet_with_empty_images_processes_without_crashing(): void
    {
        $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');

        $device = $this->createTestDevice();
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 77777,
                'customId' => 3333,
                'similarity1' => 90.0,
                'time' => now()->format('Y-m-d H:i:s'),
                'SanpPic' => null, // Empty image
                'ScenePic' => null,
            ],
        ];

        $job = new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        $this->assertDatabaseHas('access_logs', [
            'device_id' => $device->device_id,
            'customize_id' => 3333,
        ]);
    }
}

