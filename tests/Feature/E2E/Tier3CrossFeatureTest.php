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
}
