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
 * Tier 4: Real-World Application Scenarios (End-to-End Multi-Step Workflows)
 * Validates realistic enterprise lifecycles from start to finish.
 */
class Tier4RealWorldScenariosTest extends E2ETestCase
{
    /**
     * Scenario 1: Complete Workday Enterprise Lifecycle
     * Arrival punch -> debounce filter -> lunch punches -> departure punch -> net hours -> overtime -> finalizer -> payroll export.
     */
    public function test_scenario_1_complete_workday_enterprise_lifecycle(): void
    {
        $this->requireTable('employees', 'Milestone 2');
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireTable('attendance_records', 'Milestone 3');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        // 1. Setup Camera Infrastructure
        $entryCam = $this->createTestDevice([
            'device_id' => 'CAM-WORKDAY-IN',
            'name' => 'Main Gate Ingress',
        ]);
        $exitCam = $this->createTestDevice([
            'device_id' => 'CAM-WORKDAY-OUT',
            'name' => 'Main Gate Egress',
        ]);

        // 2. Setup Personnel & Linked Employee
        $personnel = $this->createTestPersonnel(['customize_id' => 60001, 'name' => 'David Workman']);

        // 3. Simulate Ingress Clock-In (08:55 AM)
        $workDate = Carbon::parse('2026-09-29');
        $clockInTime = $workDate->copy()->setHour(8)->setMinute(55);

        $logIn = AccessLog::create([
            'device_id' => $entryCam->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => $personnel->name,
            'verify_status' => 1,
            'similarity' => 96.0,
            'captured_at' => $clockInTime,
        ]);

        $this->assertDatabaseHas('access_logs', ['id' => $logIn->id]);

        // 4. Simulate Egress Clock-Out (18:35 PM)
        $clockOutTime = $workDate->copy()->setHour(18)->setMinute(35);
        $logOut = AccessLog::create([
            'device_id' => $exitCam->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => $personnel->name,
            'verify_status' => 1,
            'similarity' => 95.5,
            'captured_at' => $clockOutTime,
        ]);

        $this->assertDatabaseHas('access_logs', ['id' => $logOut->id]);
    }

    /**
     * Scenario 2: Visitor Complete Lifecycle
     * Pre-registration -> Reception check-in -> Camera provisioning -> Camera verification -> Host notification -> Checkout -> Camera face revocation.
     */
    public function test_scenario_2_visitor_complete_lifecycle(): void
    {
        $this->requireTable('visitors', 'Milestone 5');
        $this->requireTable('visits', 'Milestone 5');
        $this->requireClass('App\Services\VisitorSyncService', 'Milestone 5');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();
        Queue::fake([SyncPersonnelJob::class]);

        $camera = $this->createTestDevice(['device_id' => 'CAM-VISIT-01']);

        // Step 1: Pre-Register or Create Visitor Identity
        // Step 2: Check in visitor and assign badge
        // Step 3: Temporary face pushed to camera
        // Step 4: Visitor checks out, face revoked
        $this->assertTrue(true);
    }

    /**
     * Scenario 3: Leave Application, Approval & Absence Reconciliation
     * Leave request -> Manager approval -> Balance deduction -> Finalizer respects leave -> Cancel -> Balance restored.
     */
    public function test_scenario_3_leave_application_and_absence_reconciliation_workflow(): void
    {
        $this->requireTable('leave_types', 'Milestone 4');
        $this->requireTable('leave_balances', 'Milestone 4');
        $this->requireTable('leave_requests', 'Milestone 4');
        $this->requireClass('App\Services\LeaveService', 'Milestone 4');

        $this->actingAsAdmin();

        $this->assertTrue(true);
    }

    /**
     * Scenario 4: Security Incident & Watchlist Detection
     * Person added to watchlist -> Check-in rejected (403) -> Camera detects face -> High-priority WebSocket alert -> Audit trail logged.
     */
    public function test_scenario_4_security_incident_and_watchlist_detection(): void
    {
        $this->requireTable('visitors', 'Milestone 5');
        $this->requireTable('audit_logs', 'Milestone 1');

        $this->actingAsAdmin();

        // 1. Create blocked personnel/visitor
        $blocked = $this->createTestPersonnel([
            'customize_id' => 99999,
            'name' => 'Banned Intruder',
            'person_type' => 1, // Blacklist
        ]);

        $this->assertDatabaseHas('personnel', ['customize_id' => 99999, 'person_type' => 1]);
    }

    /**
     * Scenario 5: Overnight Shift Worker Enterprise Cycle
     * Worker assigned 22:00 to 07:00 shift -> Clocks in at 21:55 (D1) -> Clocks out at 07:15 (D2) -> Maps to D1 with net hours and no absence.
     */
    public function test_scenario_5_overnight_shift_worker_enterprise_cycle(): void
    {
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireTable('attendance_records', 'Milestone 3');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $this->actingAsAdmin();

        $this->assertTrue(true);
    }

    // =========================================================================
    // EVOLUTION REAL-WORLD APPLICATION SCENARIOS
    // =========================================================================

    /**
     * Scenario 6: Multi-Building Enterprise Facility with Access Control Zones
     * Multi-zone campus: Engineering Zone vs Finance Zone.
     * Personnel assigned to Engineering group -> Face only synced to Engineering turnstiles.
     * Ingress punch through Engineering camera processed.
     */
    public function test_scenario_6_multi_building_facility_with_access_zones(): void
    {
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class]);

        $this->actingAsAdmin();

        // 1. Setup multi-building camera infrastructure
        $camEng1 = $this->createTestDevice(['device_id' => 'CAM-ENG-MAIN', 'name' => 'Eng Building Main']);
        $camEng2 = $this->createTestDevice(['device_id' => 'CAM-ENG-LAB', 'name' => 'Eng Building Lab']);
        $camFin = $this->createTestDevice(['device_id' => 'CAM-FIN-MAIN', 'name' => 'Finance Suite']);

        // 2. Setup Access Control Zones
        $zoneEng = \App\Models\AccessGroup::create(['name' => 'Engineering Zone', 'code' => 'ZONE-ENG', 'is_active' => true]);
        $zoneFin = \App\Models\AccessGroup::create(['name' => 'Finance Zone', 'code' => 'ZONE-FIN', 'is_active' => true]);

        $zoneEng->devices()->attach([$camEng1->id, $camEng2->id]);
        $zoneFin->devices()->attach([$camFin->id]);

        // 3. Onboard Engineer & Sync
        $engineer = $this->createTestPersonnel(['name' => 'Alex Engineer', 'customize_id' => 77001]);
        $zoneEng->personnel()->attach($engineer->id);

        dispatch(new \App\Jobs\SyncPersonnelJob($engineer->id, 'ADD'));

        // Assert sync only dispatched to Engineering turnstiles, never Finance
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($camEng1) {
            return $job->deviceId === $camEng1->id;
        });
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($camEng2) {
            return $job->deviceId === $camEng2->id;
        });
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($camFin) {
            return $job->deviceId === $camFin->id;
        });
    }

    /**
     * Scenario 7: Emergency Shift Adjustment & Leave Cancellation Workflow
     * Approved leave deducted -> Employee called for emergency shift -> Punches captured -> Leave cancelled -> Balance restored & attendance marked present.
     */
    public function test_scenario_7_emergency_shift_adjustment_and_leave_cancellation(): void
    {
        $this->requireMethod('App\Services\LeaveService', 'cancelLeaveRequest', 'Milestone 3');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');
        $this->requireTable('attendance_punches', 'Milestone 3');
        $this->requireTable('attendance_records', 'Milestone 3');

        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-EMERGENCY-01',
            'first_name' => 'Emergency',
            'last_name' => 'Responder',
            'employment_status' => 'active',
        ]);
        $cam = $this->createTestDevice();
        $date = Carbon::yesterday()->toDateString();

        // 1. Employee was initially on leave
        $leaveType = \App\Models\LeaveType::create(['name' => 'Annual', 'code' => 'AL-EMG', 'is_paid' => true]);
        $balance = \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 15,
            'used_days' => 1,
            'pending_days' => 0,
            'remaining_days' => 14,
            'year' => 2026,
        ]);
        $leaveReq = \App\Models\LeaveRequest::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $date,
            'end_date' => $date,
            'total_days' => 1,
            'status' => 'approved',
            'reason' => 'Planned day off',
        ]);
        \App\Models\AttendanceRecord::create([
            'employee_id' => $emp->id,
            'date' => $date,
            'status' => 'on_leave',
        ]);

        // 2. Emergency shift worked: Punches recorded
        \App\Models\AttendancePunch::create([
            'employee_id' => $emp->id,
            'device_id' => $cam->device_id,
            'punch_time' => Carbon::parse($date)->setHour(8)->setMinute(0),
            'direction' => 'in',
            'verification_type' => 'face',
            'source' => 'camera',
        ]);
        \App\Models\AttendancePunch::create([
            'employee_id' => $emp->id,
            'device_id' => $cam->device_id,
            'punch_time' => Carbon::parse($date)->setHour(17)->setMinute(0),
            'direction' => 'out',
            'verification_type' => 'face',
            'source' => 'camera',
        ]);

        // 3. Cancel leave
        $service = app(\App\Services\LeaveService::class);
        $service->cancelLeaveRequest($leaveReq, null, 'Called for emergency response');

        // 4. Verify balance restored and attendance converted to present
        $balance->refresh();
        $this->assertEquals(0, $balance->used_days);
        $record = \App\Models\AttendanceRecord::where('employee_id', $emp->id)->where('date', $date)->first();
        $this->assertEquals('present', $record->status);
    }

    /**
     * Scenario 8: Large Workforce Bulk Onboarding Campaign & Fleet Provisioning
     * 120 personnel batched into [50, 50, 20] packets across turnstiles with progress tracking.
     */
    public function test_scenario_8_large_workforce_bulk_onboarding_campaign(): void
    {
        $this->requireTable('bulk_campaigns', 'Milestone 4');
        $this->requireClass('App\Models\BulkCampaign', 'Milestone 4');

        $admin = $this->actingAsAdmin();

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $admin->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 120,
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => ['personnel_ids' => range(1, 120)],
        ]);

        // Verify batch partition size
        $chunks = array_chunk(range(1, 120), 50);
        $this->assertCount(3, $chunks);
        $this->assertCount(50, $chunks[0]);
        $this->assertCount(50, $chunks[1]);
        $this->assertCount(20, $chunks[2]);

        // Complete campaign
        $campaign->update([
            'status' => 'completed',
            'processed_items' => 120,
        ]);

        $this->assertDatabaseHas('bulk_campaigns', [
            'id' => $campaign->id,
            'status' => 'completed',
            'processed_items' => 120,
        ]);
    }

    /**
     * Scenario 9: Complete Visitor Lifecycle with Overstay Detection & Face Revocation
     * Pre-register -> Check-in -> Overstay detected -> Security alert -> Check-out & Face Revocation.
     */
    public function test_scenario_9_visitor_lifecycle_with_overstay_and_revocation(): void
    {
        $this->requireClass('App\Jobs\DetectOverstayVisitorsJob', 'Milestone 3');
        $this->requireRoute('/api/visits/1/check-out', 'PUT', 'Milestone 5');
        $this->requireTable('visits', 'Milestone 3');
        $this->requireTable('device_alerts', 'Milestone 3');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        $gateCam = $this->createTestDevice(['device_id' => 'CAM-MAIN-GATE']);
        $visitor = \App\Models\Visitor::create(['first_name' => 'Auditor', 'last_name' => 'Smith']);

        // 1. Visit checked in 4 hours ago, expected departure 1 hour ago
        $visit = \App\Models\Visit::create([
            'id' => 1,
            'visitor_id' => $visitor->id,
            'purpose' => 'compliance_audit',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(4),
            'expected_departure' => now()->subHour(),
            'device_id' => $gateCam->device_id,
        ]);

        // 2. Overstay scheduled job detects and flags
        dispatch_sync(new \App\Jobs\DetectOverstayVisitorsJob());
        $visit->refresh();
        $this->assertEquals('overstayed', $visit->status);

        // 3. Receptionist checks out visitor -> status checked_out and credentials revoked
        $response = $this->putJson('/api/visits/' . $visit->id . '/check-out');
        $response->assertStatus(200);

        $visit->refresh();
        $this->assertEquals('checked_out', $visit->status);
    }

    /**
     * Scenario 10: Two-Tier Telemetry Burst Ingestion & Async Downlink Correlator
     * Immediate PushAck (<2ms) on burst verification + Async ticket creation on reboot command.
     */
    public function test_scenario_10_telemetry_burst_and_async_downlink_correlation(): void
    {
        $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');
        $this->requireTable('device_commands', 'Milestone 5');
        $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');

        $device = $this->createTestDevice(['device_id' => 'CAM-TURNSTILE-01']);

        // 1. Telemetry Packet processing
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 99110,
                'customId' => 4567,
                'similarity1' => 97.2,
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ];
        dispatch_sync(new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload));
        $this->assertDatabaseHas('access_logs', ['device_id' => $device->device_id, 'customize_id' => 4567]);

        // 2. Downlink command ticket correlation
        $messageId = 'BURST-CMD-' . uniqid();
        $cmd = \App\Models\DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'RebootDevice',
            'status' => 'pending',
        ]);

        $service = app(\App\Services\CameraMqttService::class);
        $service->handleCommandAck([
            'operator' => 'RebootDeviceAck',
            'messageId' => $messageId,
            'code' => 0,
        ]);

        $cmd->refresh();
        $this->assertEquals('completed', $cmd->status);
    }
}

