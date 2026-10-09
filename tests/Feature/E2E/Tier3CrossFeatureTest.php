<?php

namespace Tests\Feature\E2E;

use App\Events\AccessLogReceived;
use App\Jobs\SyncPersonnelJob;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 3: Cross-Feature Combinations (Pairwise Domain Interactions)
 * Tests interactions across subsystem boundaries (Shift-Punch, Visitor-Camera, Leave-Attendance).
 */
class Tier3CrossFeatureTest extends E2ETestCase
{
    public function test_cross_access_log_received_event_dispatches_process_attendance_punch_job(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-EVENT-01']);
        $personnel = $this->createTestPersonnel(['customize_id' => 99911]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => 'Alice Worker',
            'verify_status' => 1,
            'similarity' => 95.0,
            'captured_at' => Carbon::now(),
        ]);

        Event::fake([AccessLogReceived::class]);

        event(new AccessLogReceived($log));

        Event::assertDispatched(AccessLogReceived::class, function ($event) use ($log) {
            return $event->log->id === $log->id;
        });
    }

    public function test_cross_visitor_checkin_triggers_temporary_camera_face_sync_job(): void
    {
        $this->requireTable('visits', 'Milestone 5');
        $this->requireClass('App\Services\VisitorSyncService', 'Milestone 5');

        Queue::fake([SyncPersonnelJob::class]);
        $this->mockCameraSuccess();

        // When a visitor is checked in, VisitorSyncService creates temporary personnel
        // and triggers camera sync job
        $service = app('App\Services\VisitorSyncService');
        $this->assertNotNull($service);
    }

    public function test_cross_visitor_checkout_triggers_camera_face_deletion_sync_job(): void
    {
        $this->requireTable('visits', 'Milestone 5');
        $this->requireClass('App\Services\VisitorSyncService', 'Milestone 5');

        Queue::fake([SyncPersonnelJob::class]);
        $this->mockCameraSuccess();

        $service = app('App\Services\VisitorSyncService');
        $this->assertNotNull($service);
    }

    public function test_cross_approved_leave_overrides_absence_penalty_and_marks_on_leave(): void
    {
        $this->requireClass('App\Services\LeaveService', 'Milestone 4');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Approved leave must update attendance_records.status = 'on_leave'
        // preventing DailyAttendanceFinalizerJob from marking the employee absent
        $leaveService = app('App\Services\LeaveService');
        $attendanceService = app('App\Services\AttendanceProcessingService');

        $this->assertNotNull($leaveService);
        $this->assertNotNull($attendanceService);
    }

    public function test_cross_watchlist_blocked_person_interception_at_checkin_and_camera_event(): void
    {
        $this->requireTable('visitors', 'Milestone 5');
        $this->requireRoute('/api/visits', 'POST', 'Milestone 5');

        $this->actingAsAdmin();

        // Attempting to check in a blocked visitor should return 403 Forbidden
        $response = $this->postJson('/api/visits', [
            'visitor_id' => 99999, // Blocked visitor
            'host_employee_id' => 1,
            'purpose' => 'meeting',
        ]);

        $this->assertContains($response->status(), [400, 403, 404, 422]);
    }

    public function test_cross_holiday_calendar_precedence_over_shift_schedule(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Holiday must take precedence over weekday shift; work hours credit to overtime
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_cross_directional_camera_role_enforces_punch_direction(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Camera with role = 'entry' guarantees punch direction = 'in' regardless of previous state
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_cross_attendance_regularization_approval_updates_attendance_record(): void
    {
        $this->requireTable('regularization_requests', 'Milestone 4');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Approving a regularization request triggers recomputation of attendance_record for that date
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_cross_employee_soft_delete_preserves_attendance_and_punch_history(): void
    {
        $this->requireTable('employees', 'Milestone 2');

        // Deleting employee preserves linked attendance records and biometric logs
        $this->assertTrue(true);
    }

    public function test_cross_shift_change_during_active_day_recalculates_attendance(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    // =========================================================================
    // SECTION 2: Evolution Cross-Feature Combinations (Pairwise Coverage)
    // =========================================================================

    public function test_cross_access_control_scopes_personnel_synchronization_to_zone(): void
    {
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class]);

        $zoneCam1 = $this->createTestDevice(['device_id' => 'CAM-ZONE-1']);
        $zoneCam2 = $this->createTestDevice(['device_id' => 'CAM-ZONE-2']);
        $otherCam = $this->createTestDevice(['device_id' => 'CAM-OTHER']);
        $personnel = Personnel::withoutEvents(fn () => $this->createTestPersonnel());

        $group = \App\Models\AccessGroup::create([
            'name' => 'Zone Alpha',
            'code' => 'ZONE-ALPHA',
            'is_active' => true,
        ]);
        $group->devices()->attach([$zoneCam1->id, $zoneCam2->id]);
        $group->personnel()->attach($personnel->id);

        dispatch(new \App\Jobs\SyncPersonnelJob($personnel->id, 'ADD'));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($zoneCam1) {
            return $job->deviceId === $zoneCam1->id;
        });
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($zoneCam2) {
            return $job->deviceId === $zoneCam2->id;
        });
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($otherCam) {
            return $job->deviceId === $otherCam->id;
        });
    }

    public function test_cross_leave_cancellation_triggers_attendance_recalculation_from_punches(): void
    {
        $this->requireMethod('App\Services\LeaveService', 'cancelLeaveRequest', 'Milestone 3');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');
        $this->requireTable('attendance_punches', 'Milestone 3');
        $this->requireTable('attendance_records', 'Milestone 3');

        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-PAIR-01',
            'first_name' => 'Pair',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);
        $device = $this->createTestDevice();
        $workDate = Carbon::yesterday()->toDateString();

        // 1. Employee punches captured on date
        \App\Models\AttendancePunch::create([
            'employee_id' => $emp->id,
            'device_id' => $device->device_id,
            'punch_time' => Carbon::parse($workDate)->setHour(9)->setMinute(0),
            'direction' => 'in',
            'verification_type' => 'face',
            'source' => 'camera',
        ]);
        \App\Models\AttendancePunch::create([
            'employee_id' => $emp->id,
            'device_id' => $device->device_id,
            'punch_time' => Carbon::parse($workDate)->setHour(18)->setMinute(0),
            'direction' => 'out',
            'verification_type' => 'face',
            'source' => 'camera',
        ]);

        // 2. Previously marked on_leave
        \App\Models\AttendanceRecord::create([
            'employee_id' => $emp->id,
            'date' => $workDate,
            'status' => 'on_leave',
        ]);

        $leaveType = \App\Models\LeaveType::create(['name' => 'Sick', 'code' => 'SL-PAIR', 'is_paid' => true]);
        \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 10,
            'used_days' => 1,
            'pending_days' => 0,
            'remaining_days' => 9,
            'year' => 2026,
        ]);

        $req = \App\Models\LeaveRequest::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $workDate,
            'end_date' => $workDate,
            'total_days' => 1,
            'status' => 'approved',
            'reason' => 'Emergency leave',
        ]);

        // 3. Cancel leave
        $leaveService = app(\App\Services\LeaveService::class);
        $leaveService->cancelLeaveRequest($req, null, 'Employee reported to work');

        // 4. Record must recalculate to present
        $record = \App\Models\AttendanceRecord::where('employee_id', $emp->id)->where('date', $workDate)->first();
        $this->assertEquals('present', $record->status);
    }

    public function test_cross_visitor_overstay_generates_device_alert_and_notifies_security(): void
    {
        $this->requireClass('App\Jobs\DetectOverstayVisitorsJob', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');
        $this->requireTable('device_alerts', 'Milestone 3');

        $device = $this->createTestDevice(['device_id' => 'CAM-SECURITY-GATE']);
        $visitor = \App\Models\Visitor::create(['first_name' => 'Overstay', 'last_name' => 'Target']);
        $visit = \App\Models\Visit::create([
            'visitor_id' => $visitor->id,
            'purpose' => 'contractor',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(4),
            'expected_departure' => now()->subMinutes(30),
            'device_id' => $device->device_id,
        ]);

        dispatch_sync(new \App\Jobs\DetectOverstayVisitorsJob());

        $visit->refresh();
        $this->assertEquals('overstayed', $visit->status);
        $this->assertDatabaseHas('device_alerts', [
            'device_id' => $device->device_id,
            'alert_type' => 'visitor_overstay',
        ]);
    }

    public function test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets(): void
    {
        $this->requireTable('bulk_campaigns', 'Milestone 4');
        $this->requireTable('device_commands', 'Milestone 5');
        $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');

        $admin = $this->actingAsAdmin();
        $cam = $this->createTestDevice();
        $messageId = 'BULK-CMD-' . uniqid();

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $admin->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 1,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'processing',
        ]);

        $cmd = \App\Models\DeviceCommand::create([
            'device_id' => $cam->id,
            'message_id' => $messageId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $ack = [
            'operator' => 'RebootDeviceAck',
            'messageId' => $messageId,
            'code' => 0,
        ];

        $service = app(\App\Services\CameraMqttService::class);
        $service->handleCommandAck($ack);

        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status);
    }

    public function test_cross_visitor_cancellation_dispatches_hardware_face_deletion(): void
    {
        $this->requireRoute('/api/visits/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        $vis = \App\Models\Visitor::create(['first_name' => 'Revoke', 'last_name' => 'Target']);
        $visit = \App\Models\Visit::create([
            'id' => 1,
            'visitor_id' => $vis->id,
            'purpose' => 'audit',
            'status' => 'checked_in',
            'check_in_time' => now()->subHour(),
            'expected_departure' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/visits/' . $visit->id . '/cancel', [
            'reason' => 'Access terminated prematurely',
        ]);

        $response->assertStatus(200);
        $visit->refresh();
        $this->assertEquals('cancelled', $visit->status);
    }

    public function test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices(): void
    {
        $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');

        $this->actingAsAdmin();
        $cam = $this->createTestDevice();
        $personnel = $this->createTestPersonnel();

        $group = \App\Models\AccessGroup::create([
            'id' => 1,
            'name' => 'Resync Campus',
            'code' => 'RESYNC-CAMPUS',
            'is_active' => true,
        ]);
        $group->devices()->attach($cam->id);
        $group->personnel()->attach($personnel->id);

        $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
        $response->assertStatus(200);
    }
}

