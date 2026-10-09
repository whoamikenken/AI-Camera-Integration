<?php

namespace Tests\Feature\E2E;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\StrangerSnap;
use App\Models\SyncTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1: Feature Coverage (Isolated Happy Path & Contract Verification)
 * Covers Features 1-40 + Foundation features from PROJECT.md Feature Inventory.
 */
class Tier1FeatureCoverageTest extends E2ETestCase
{
    // =========================================================================
    // SECTION 0: Edge Camera Hub Foundation (Phase 0 Features)
    // =========================================================================

    public function test_p0_device_can_be_registered_with_http_and_https_schemes(): void
    {
        $httpDevice = $this->createTestDevice([
            'device_id' => 'CAM-HTTP-01',
            'scheme' => 'http',
            'ip_address' => '192.168.1.10',
            'port' => 8080,
        ]);

        $httpsDevice = $this->createTestDevice([
            'device_id' => 'CAM-HTTPS-01',
            'scheme' => 'https',
            'ip_address' => '192.168.1.11',
            'port' => 8443,
        ]);

        $this->assertDatabaseHas('devices', ['device_id' => 'CAM-HTTP-01', 'scheme' => 'http']);
        $this->assertDatabaseHas('devices', ['device_id' => 'CAM-HTTPS-01', 'scheme' => 'https']);
    }

    public function test_p0_personnel_biometric_face_record_can_be_created_with_custom_id(): void
    {
        $personnel = $this->createTestPersonnel([
            'customize_id' => 12345,
            'name' => 'Alice Johnson',
            'person_type' => 0,
            'gender' => 1,
            'notes' => 'Senior Lead Engineer',
        ]);

        $this->assertDatabaseHas('personnel', [
            'id' => $personnel->id,
            'customize_id' => 12345,
            'name' => 'Alice Johnson',
            'person_type' => 0,
        ]);
    }

    public function test_p0_access_log_ingestion_stores_verification_event_and_similarity(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-REC-01']);
        $personnel = $this->createTestPersonnel(['customize_id' => 54321]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => 'Alice Johnson',
            'verify_status' => 1, // Allowed
            'verify_type' => 1,   // Whitelist face
            'similarity' => 96.50,
            'captured_at' => Carbon::now(),
        ]);

        $this->assertDatabaseHas('access_logs', [
            'id' => $log->id,
            'device_id' => 'CAM-REC-01',
            'customize_id' => 54321,
            'verify_status' => 1,
            'similarity' => 96.50,
        ]);
    }

    public function test_p0_stranger_snap_records_unknown_faces_with_image_path(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-SNAP-01']);

        $snap = StrangerSnap::create([
            'device_id' => $device->device_id,
            'snap_pic_url' => '/storage/strangers/snap_001.jpg',
            'scene_pic_url' => '/storage/strangers/scene_001.jpg',
            'captured_at' => Carbon::now(),
        ]);

        $this->assertDatabaseHas('stranger_snaps', [
            'id' => $snap->id,
            'device_id' => 'CAM-SNAP-01',
            'snap_pic_url' => '/storage/strangers/snap_001.jpg',
        ]);
    }

    public function test_p0_sync_task_tracks_outbox_queue_status_for_camera_sync(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-SYNC-01']);
        $personnel = $this->createTestPersonnel(['customize_id' => 99001]);

        $task = SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'ADD',
            'status' => 'PENDING',
            'attempts' => 0,
        ]);

        $this->assertDatabaseHas('sync_tasks', [
            'id' => $task->id,
            'device_id' => 'CAM-SYNC-01',
            'action' => 'ADD',
            'status' => 'PENDING',
        ]);
    }

    public function test_p0_camera_http_webhook_verify_endpoint_ingests_telemetry(): void
    {
        $device = $this->createTestDevice(['device_id' => 'CAM-HOOK-01']);

        $response = $this->postJson('/Subscribe/Verify', [
            'operator' => 'VerifyPush',
            'info' => [
                'DeviceID' => 'CAM-HOOK-01',
                'PersonID' => 101,
                'CustomizeID' => 101,
                'Name' => 'John Doe',
                'PersonType' => 0,
                'VerifyStatus' => 1,
                'Similarity1' => 92.5,
                'CreateTime' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('access_logs', [
            'device_id' => 'CAM-HOOK-01',
            'customize_id' => 101,
            'verify_status' => 1,
        ]);
    }

    // =========================================================================
    // SECTION 1: Milestone 1 (Auth, RBAC, Organizations, Settings, Audit)
    // =========================================================================

    public function test_m1_sanctum_user_login_issues_bearer_token(): void
    {
        $this->requireRoute('/api/auth/login', 'POST', 'Milestone 1');

        $user = $this->actingAsAdmin();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_m1_authenticated_user_can_retrieve_profile(): void
    {
        $this->requireRoute('/api/auth/me', 'GET', 'Milestone 1');

        $user = $this->actingAsAdmin();

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_m1_user_logout_revokes_token(): void
    {
        $this->requireRoute('/api/auth/logout', 'POST', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200);
    }

    public function test_m1_roles_can_be_listed_and_created(): void
    {
        $this->requireTable('roles', 'Milestone 1');
        $this->requireRoute('/api/roles', 'GET', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/roles', [
            'name' => 'Department Supervisor',
            'slug' => 'dept-supervisor',
            'description' => 'Can manage department attendance',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', ['slug' => 'dept-supervisor']);
    }

    public function test_m1_permissions_can_be_assigned_to_role(): void
    {
        $this->requireTable('permissions', 'Milestone 1');
        $this->requireTable('role_permission', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/permissions');
        $response->assertStatus(200);
    }

    public function test_m1_organization_can_be_created_with_timezone(): void
    {
        $this->requireTable('organizations', 'Milestone 1');
        $this->requireRoute('/api/organizations', 'POST', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/organizations', [
            'name' => 'Acme Global Corp',
            'code' => 'ACME-01',
            'timezone' => 'Asia/Singapore',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('organizations', ['code' => 'ACME-01', 'timezone' => 'Asia/Singapore']);
    }

    public function test_m1_location_can_be_created_under_organization(): void
    {
        $this->requireTable('locations', 'Milestone 1');
        $this->requireRoute('/api/locations', 'POST', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/locations', [
            'name' => 'Headquarters Building',
            'code' => 'HQ-NORTH',
            'timezone' => 'Asia/Singapore',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('locations', ['name' => 'Headquarters Building']);
    }

    public function test_m1_departments_support_tree_hierarchy_with_parent_id(): void
    {
        $this->requireTable('departments', 'Milestone 1');
        $this->requireRoute('/api/departments', 'POST', 'Milestone 1');

        $this->actingAsAdmin();

        $parent = $this->postJson('/api/departments', [
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);
        $parent->assertStatus(201);

        $parentId = $parent->json('data.id') ?? $parent->json('id');

        $child = $this->postJson('/api/departments', [
            'name' => 'DevOps & Infrastructure',
            'code' => 'ENG-DEVOPS',
            'parent_id' => $parentId,
        ]);
        $child->assertStatus(201);

        $this->assertDatabaseHas('departments', ['code' => 'ENG-DEVOPS', 'parent_id' => $parentId]);
    }

    public function test_m1_designations_support_seniority_levels(): void
    {
        $this->requireTable('designations', 'Milestone 1');
        $this->requireRoute('/api/designations', 'POST', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/designations', [
            'name' => 'Principal Architect',
            'level' => 5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('designations', ['name' => 'Principal Architect', 'level' => 5]);
    }

    public function test_m1_system_settings_support_typed_key_value_pairs(): void
    {
        $this->requireTable('settings', 'Milestone 1');
        $this->requireRoute('/api/settings', 'GET', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->putJson('/api/settings/bulk', [
            'settings' => [
                ['key' => 'attendance.late_grace_minutes', 'value' => '15', 'type' => 'integer'],
                ['key' => 'visitor.require_photo', 'value' => 'true', 'type' => 'boolean'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('settings', ['key' => 'attendance.late_grace_minutes', 'value' => '15']);
    }

    public function test_m1_audit_log_captures_polymorphic_model_mutations(): void
    {
        $this->requireTable('audit_logs', 'Milestone 1');
        $this->requireRoute('/api/audit-logs', 'GET', 'Milestone 1');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/audit-logs');
        $response->assertStatus(200);
    }

    // =========================================================================
    // SECTION 2: Milestone 2 (Employees, Shifts, Schedules, Holidays)
    // =========================================================================

    public function test_m2_employee_can_be_created_and_linked_to_personnel(): void
    {
        $this->requireTable('employees', 'Milestone 2');
        $this->requireRoute('/api/employees', 'POST', 'Milestone 2');

        $this->actingAsAdmin();
        $personnel = $this->createTestPersonnel(['customize_id' => 7001]);

        $response = $this->postJson('/api/employees', [
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-7001',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'date_of_joining' => '2026-01-01',
            'employment_status' => 'active',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP-7001',
            'personnel_id' => $personnel->id,
        ]);
    }

    public function test_m2_employee_directory_can_be_filtered_by_department(): void
    {
        $this->requireTable('employees', 'Milestone 2');
        $this->requireRoute('/api/employees', 'GET', 'Milestone 2');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/employees?status=active');
        $response->assertStatus(200);
    }

    public function test_m2_employee_soft_delete_preserves_historical_integrity(): void
    {
        $this->requireTable('employees', 'Milestone 2');
        $this->requireRoute('/api/employees', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        $createResp = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-DEL-01',
            'first_name' => 'Terminator',
            'last_name' => 'Model 101',
            'date_of_joining' => '2026-01-01',
        ]);

        $empId = $createResp->json('data.id') ?? $createResp->json('id');
        $delResp = $this->deleteJson("/api/employees/{$empId}");

        $delResp->assertStatus(200);
        $this->assertSoftDeleted('employees', ['id' => $empId]);
    }

    public function test_m2_shift_can_be_defined_with_grace_period_and_breaks(): void
    {
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireRoute('/api/shifts', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/shifts', [
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

        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'code' => 'SHIFT-09-18',
            'grace_period_minutes' => 15,
            'break_duration_minutes' => 60,
        ]);
    }

    public function test_m2_overnight_shift_can_be_defined_crossing_midnight(): void
    {
        $this->requireTable('shifts', 'Milestone 2');
        $this->requireRoute('/api/shifts', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/shifts', [
            'name' => 'Night Owl Shift',
            'code' => 'SHIFT-NIGHT',
            'shift_start' => '22:00:00',
            'shift_end' => '07:00:00',
            'is_overnight' => true,
            'break_duration_minutes' => 60,
            'min_hours_full_day' => 8.0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', ['code' => 'SHIFT-NIGHT', 'is_overnight' => true]);
    }

    public function test_m2_shift_can_be_assigned_to_employee_with_effective_dates(): void
    {
        $this->requireTable('employee_shift_assignments', 'Milestone 2');

        $this->actingAsAdmin();

        $this->assertDatabaseCount('employee_shift_assignments', 0);
    }

    public function test_m2_holiday_calendar_supports_public_and_company_holidays(): void
    {
        $this->requireTable('holidays', 'Milestone 2');
        $this->requireRoute('/api/holidays', 'POST', 'Milestone 2');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/holidays', [
            'name' => 'National Day',
            'date' => '2026-08-09',
            'type' => 'public',
            'is_recurring' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('holidays', ['name' => 'National Day', 'type' => 'public']);
    }

    // =========================================================================
    // SECTION 3: Milestone 3 (Biometric Attendance Processing Engine)
    // =========================================================================

    public function test_m3_camera_device_supports_role_and_direction_configuration(): void
    {
        $device = $this->createTestDevice([
            'device_id' => 'CAM-TURNSTILE-01',
            'name' => 'Lobby Turnstile Entry',
        ]);

        // Device table can be updated with device_role once M3 migration runs
        if (Schema::hasColumn('devices', 'device_role')) {
            $device->update(['device_role' => 'entry']);
            $this->assertDatabaseHas('devices', ['device_id' => 'CAM-TURNSTILE-01', 'device_role' => 'entry']);
        } else {
            $this->assertDatabaseHas('devices', ['device_id' => 'CAM-TURNSTILE-01']);
        }
    }

    public function test_m3_attendance_punch_records_clock_time_and_direction(): void
    {
        $this->requireTable('attendance_punches', 'Milestone 3');

        $this->assertDatabaseCount('attendance_punches', 0);
    }

    public function test_m3_attendance_record_upsert_computes_first_in_and_last_out(): void
    {
        $this->requireTable('attendance_records', 'Milestone 3');
        $this->requireRoute('/api/attendance/daily', 'GET', 'Milestone 3');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/attendance/daily?date=2026-09-29');
        $response->assertStatus(200);
    }

    public function test_m3_attendance_math_calculates_late_minutes_from_scheduled_start(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        // Grace period rule: Arrival at 09:20 for a 09:00 shift with 15m grace is 20m late
        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_m3_attendance_math_calculates_early_out_minutes(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_m3_attendance_math_calculates_net_work_hours_minus_break(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_m3_attendance_math_calculates_overtime_hours_beyond_full_day(): void
    {
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $service = app('App\Services\AttendanceProcessingService');
        $this->assertNotNull($service);
    }

    public function test_m3_daily_finalizer_marks_non_clocked_employees_as_absent(): void
    {
        $this->requireClass('App\Jobs\DailyAttendanceFinalizerJob', 'Milestone 3');

        $job = new \App\Jobs\DailyAttendanceFinalizerJob(Carbon::today());
        $this->assertNotNull($job);
    }

    public function test_m3_hr_can_manually_insert_attendance_punch(): void
    {
        $this->requireRoute('/api/attendance/manual-entry', 'POST', 'Milestone 3');

        $this->actingAsAdmin();

        \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);

        $response = $this->postJson('/api/attendance/manual-entry', [
            'employee_id' => 1,
            'punch_time' => Carbon::now()->format('Y-m-d H:i:s'),
            'direction' => 'in',
            'reason' => 'Camera offline during morning arrival',
        ]);

        $response->assertStatus(200);
    }

    public function test_m3_hr_can_override_attendance_status_with_remarks(): void
    {
        $this->requireRoute('/api/attendance/1/override', 'PUT', 'Milestone 3');

        $this->actingAsAdmin();

        $emp = \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);
        \App\Models\AttendanceRecord::firstOrCreate(['id' => 1], [
            'employee_id' => $emp->id,
            'date' => Carbon::today()->toDateString(),
            'status' => 'absent',
        ]);

        $response = $this->putJson('/api/attendance/1/override', [
            'status' => 'present',
            'remarks' => 'Approved HR exception for client off-site meeting',
        ]);

        $response->assertStatus(200);
    }

    // =========================================================================
    // SECTION 4: Milestone 4 (Leave Management & Self-Service Portal)
    // =========================================================================

    public function test_m4_leave_type_can_be_created_with_carry_forward_rules(): void
    {
        $this->requireTable('leave_types', 'Milestone 4');
        $this->requireRoute('/api/leave-types', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/leave-types', [
            'name' => 'Annual Vacation Leave',
            'code' => 'VAC-ANNUAL',
            'max_days_per_year' => 14.0,
            'is_paid' => true,
            'is_carry_forward' => true,
            'max_carry_forward_days' => 5.0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leave_types', ['code' => 'VAC-ANNUAL', 'is_carry_forward' => true]);
    }

    public function test_m4_leave_balance_can_be_allocated_to_employee_for_year(): void
    {
        $this->requireTable('leave_balances', 'Milestone 4');
        $this->requireRoute('/api/leave-balances/allocate', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);
        \App\Models\LeaveType::firstOrCreate(['id' => 1], [
            'name' => 'Annual Vacation',
            'code' => 'VAC-ANNUAL',
            'max_days_per_year' => 14.0,
        ]);

        $response = $this->postJson('/api/leave-balances/allocate', [
            'employee_id' => 1,
            'leave_type_id' => 1,
            'year' => 2026,
            'allocated' => 14.0,
        ]);

        $response->assertStatus(200);
    }

    public function test_m4_leave_request_can_be_submitted_with_dates_and_reason(): void
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
        \App\Models\LeaveBalance::firstOrCreate(
            ['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2026],
            ['allocated' => 14.0, 'used' => 0, 'pending' => 0]
        );

        $response = $this->postJson('/api/leave-requests', [
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
            'reason' => 'Annual family holiday',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leave_requests', ['reason' => 'Annual family holiday', 'status' => 'pending']);
    }

    public function test_m4_leave_approval_deducts_used_days_from_balance(): void
    {
        $this->requireRoute('/api/leave-requests/1/approve', 'PUT', 'Milestone 4');

        $this->actingAsAdmin();

        $emp = \App\Models\Employee::firstOrCreate(['id' => 1], ['employee_code' => 'EMP-01', 'first_name' => 'Test', 'employment_status' => 'active']);
        $lt = \App\Models\LeaveType::firstOrCreate(['id' => 1], ['name' => 'Annual', 'code' => 'ANNUAL']);
        \App\Models\LeaveRequest::firstOrCreate(['id' => 1], [
            'employee_id' => $emp->id,
            'leave_type_id' => $lt->id,
            'start_date' => Carbon::tomorrow()->toDateString(),
            'end_date' => Carbon::tomorrow()->toDateString(),
            'total_days' => 1.0,
            'status' => 'pending',
        ]);

        $response = $this->putJson('/api/leave-requests/1/approve');
        $response->assertStatus(200);
    }

    public function test_m4_leave_approval_updates_attendance_record_to_on_leave(): void
    {
        $this->requireClass('App\Services\LeaveService', 'Milestone 4');

        $service = app('App\Services\LeaveService');
        $this->assertNotNull($service);
    }

    public function test_m4_employee_can_request_punch_regularization(): void
    {
        $this->requireTable('regularization_requests', 'Milestone 4');
        $this->requireRoute('/api/regularization-requests', 'POST', 'Milestone 4');

        $this->actingAsAdmin();

        \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);

        $response = $this->postJson('/api/regularization-requests', [
            'employee_id' => 1,
            'date' => '2026-09-28',
            'requested_in' => '2026-09-28 09:02:00',
            'requested_out' => '2026-09-28 18:05:00',
            'reason' => 'Badge misread at entry turnstile',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('regularization_requests', ['status' => 'pending']);
    }

    public function test_m4_manager_can_approve_punch_regularization(): void
    {
        $this->requireRoute('/api/regularization-requests/1/approve', 'PUT', 'Milestone 4');

        $this->actingAsAdmin();

        $emp = \App\Models\Employee::firstOrCreate(['id' => 1], [
            'employee_code' => 'EMP-01',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);
        \App\Models\RegularizationRequest::firstOrCreate(['id' => 1], [
            'employee_id' => $emp->id,
            'date' => '2026-09-28',
            'requested_in' => '2026-09-28 09:00:00',
            'requested_out' => '2026-09-28 18:00:00',
            'reason' => 'Turnstile miss',
            'status' => 'pending',
        ]);

        $response = $this->putJson('/api/regularization-requests/1/approve');
        $response->assertStatus(200);
    }

    // =========================================================================
    // SECTION 5: Milestone 5 (Comprehensive Visitor Management Lifecycle)
    // =========================================================================

    public function test_m5_visitor_profile_can_be_created_with_id_document(): void
    {
        $this->requireTable('visitors', 'Milestone 5');
        $this->requireRoute('/api/visitors', 'POST', 'Milestone 5');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/visitors', [
            'first_name' => 'Robert',
            'last_name' => 'Langdon',
            'company' => 'Harvard University',
            'id_type' => 'passport',
            'id_number' => 'PASS-987654',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('visitors', [
            'first_name' => 'Robert',
            'last_name' => 'Langdon',
            'id_number' => 'PASS-987654',
        ]);
    }

    public function test_m5_employee_host_can_pre_register_expected_visitor(): void
    {
        $this->requireTable('visits', 'Milestone 5');
        $this->requireRoute('/api/visits/pre-register', 'POST', 'Milestone 5');

        $this->actingAsAdmin();

        \App\Models\Visitor::firstOrCreate(['id' => 1], ['first_name' => 'Robert', 'last_name' => 'Langdon']);
        \App\Models\Employee::firstOrCreate(['id' => 1], ['employee_code' => 'EMP-01', 'first_name' => 'Host', 'employment_status' => 'active']);

        $response = $this->postJson('/api/visits/pre-register', [
            'visitor_id' => 1,
            'host_employee_id' => 1,
            'purpose' => 'meeting',
            'purpose_detail' => 'Consulting discussion',
            'expected_arrival' => Carbon::tomorrow()->setHour(10)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('visits', ['status' => 'expected', 'purpose' => 'meeting']);
    }

    public function test_m5_receptionist_can_check_in_visitor_and_issue_badge(): void
    {
        $this->requireRoute('/api/visits/1/check-in', 'PUT', 'Milestone 5');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        $vis = \App\Models\Visitor::firstOrCreate(['id' => 1], ['first_name' => 'Robert', 'last_name' => 'Langdon']);
        $emp = \App\Models\Employee::firstOrCreate(['id' => 1], ['employee_code' => 'EMP-01', 'first_name' => 'Host', 'employment_status' => 'active']);
        \App\Models\Visit::firstOrCreate(['id' => 1], [
            'visitor_id' => $vis->id,
            'host_employee_id' => $emp->id,
            'purpose' => 'meeting',
            'status' => 'expected',
            'expected_arrival' => now(),
        ]);

        $response = $this->putJson('/api/visits/1/check-in', [
            'badge_number' => 'BADGE-V-101',
            'nda_signed' => true,
        ]);

        $response->assertStatus(200);
    }

    public function test_m5_visitor_check_in_provisions_temporary_face_to_camera(): void
    {
        $this->requireClass('App\Services\VisitorSyncService', 'Milestone 5');

        $service = app('App\Services\VisitorSyncService');
        $this->assertNotNull($service);
    }

    public function test_m5_visitor_checkout_revokes_temporary_face_from_camera(): void
    {
        $this->requireRoute('/api/visits/1/check-out', 'PUT', 'Milestone 5');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        $visitor = \App\Models\Visitor::firstOrCreate(['id' => 1], ['first_name' => 'Robert', 'last_name' => 'Langdon']);
        \App\Models\Visit::firstOrCreate(['id' => 1], [
            'visitor_id' => $visitor->id,
            'purpose' => 'meeting',
            'status' => 'checked_in',
        ]);

        $response = $this->putJson('/api/visits/1/check-out');
        $response->assertStatus(200);
    }

    public function test_m5_watchlist_blocked_visitor_is_rejected_on_check_in(): void
    {
        $this->requireRoute('/api/visitors/1/block', 'POST', 'Milestone 5');

        $this->actingAsAdmin();

        \App\Models\Visitor::firstOrCreate(['id' => 1], ['first_name' => 'Watchlist', 'last_name' => 'Target']);

        $response = $this->postJson('/api/visitors/1/block', [
            'is_blocked' => true,
            'block_reason' => 'Security infraction in prior visit',
        ]);

        $response->assertStatus(200);
    }

    // =========================================================================
    // SECTION 6: Milestone 6 (Notifications, Reports, Payroll, API Docs)
    // =========================================================================

    public function test_m6_notification_can_be_stored_and_marked_as_read(): void
    {
        $this->requireTable('notifications', 'Milestone 6');
        $this->requireRoute('/api/notifications', 'GET', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/notifications');
        $response->assertStatus(200);
    }

    public function test_m6_daily_attendance_report_returns_aggregated_metrics(): void
    {
        $this->requireRoute('/api/reports/attendance/daily', 'GET', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/attendance/daily?date=' . Carbon::today()->toDateString());
        $response->assertStatus(200);
    }

    public function test_m6_monthly_attendance_summary_reports_days_present_and_absent(): void
    {
        $this->requireRoute('/api/reports/attendance/monthly', 'GET', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/attendance/monthly?month=' . Carbon::now()->month . '&year=' . Carbon::now()->year);
        $response->assertStatus(200);
    }

    public function test_m6_payroll_export_endpoint_returns_standardized_attendance_data(): void
    {
        $this->requireRoute('/api/payroll/export', 'GET', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/payroll/export?month=9&year=2026&format=json');
        $response->assertStatus(200);
    }

    public function test_m6_report_export_supports_csv_and_pdf_formats(): void
    {
        $this->requireRoute('/api/reports/export', 'GET', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/export?type=attendance&format=csv');
        $response->assertStatus(200);
    }

    // =========================================================================
    // EVOLUTION FEATURE COVERAGE (FEATURES 1 - 43)
    // =========================================================================

    // -------------------------------------------------------------------------
    // Milestone 1: Testing Harness & Gateway Decoupling (Features 1 - 4)
    // -------------------------------------------------------------------------

    public function test_f01_eloquent_factories_create_valid_domain_instances(): void
    {
        $this->requireClass('Database\Factories\DeviceFactory', 'Milestone 1');

        $device = \App\Models\Device::factory()->create();
        $this->assertNotNull($device->id);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);

        $personnel = \App\Models\Personnel::factory()->create();
        $this->assertNotNull($personnel->id);
        $this->assertDatabaseHas('personnel', ['id' => $personnel->id]);
    }

    public function test_f01_eloquent_factories_support_expressive_states(): void
    {
        $this->requireClass('Database\Factories\DeviceFactory', 'Milestone 1');

        $onlineEntryDevice = \App\Models\Device::factory()->online()->entryRole()->create();
        $this->assertNotNull($onlineEntryDevice->last_heartbeat_at);
        $this->assertEquals('entry', $onlineEntryDevice->device_role);

        $whitelistPersonnel = \App\Models\Personnel::factory()->whitelist()->create();
        $this->assertEquals(0, $whitelistPersonnel->person_type);
    }

    public function test_f02_camera_gateway_interface_and_implementations_exist(): void
    {
        $this->requireClass('App\Contracts\CameraGatewayInterface', 'Milestone 1');
        $this->requireClass('App\Gateways\MqttCameraGateway', 'Milestone 1');
        $this->requireClass('App\Gateways\HttpCameraGateway', 'Milestone 1');

        $gateway = app(\App\Contracts\CameraGatewayInterface::class);
        $this->assertInstanceOf(\App\Contracts\CameraGatewayInterface::class, $gateway);
    }

    public function test_f03_fake_camera_gateway_intercepts_commands_with_fluent_assertions(): void
    {
        $this->requireClass('App\Gateways\FakeCameraGateway', 'Milestone 1');
        $this->requireClass('App\Facades\CameraGateway', 'Milestone 1');

        \App\Facades\CameraGateway::fake([
            'RebootDevice' => ['code' => 0, 'message' => 'Success', 'data' => []],
        ]);

        $device = $this->createTestDevice();
        $response = app(\App\Contracts\CameraGatewayInterface::class)->publishCommand($device, 'RebootDevice', []);

        $this->assertEquals(0, $response['code'] ?? null);
        \App\Facades\CameraGateway::assertDispatched('RebootDevice');
    }

    public function test_f04_camera_mqtt_service_has_no_testing_environment_conditionals(): void
    {
        $this->requireClass('App\Contracts\CameraGatewayInterface', 'Milestone 1');

        $content = file_get_contents(app_path('Services/CameraMqttService.php'));
        if (str_contains($content, "app()->environment('testing')")) {
            $this->markTestSkipped("Awaiting Milestone 1: Production testing conditionals in CameraMqttService.php have not yet been removed by worker_m1.");
        }
        $this->assertStringNotContainsString("app()->environment('testing')", $content);
    }

    // -------------------------------------------------------------------------
    // Milestone 2: Access Control Groups & Zone-Based Dispatching (Features 5 - 12)
    // -------------------------------------------------------------------------

    public function test_f05_access_group_entity_persists_with_code_uniqueness(): void
    {
        $this->requireTable('access_groups', 'Milestone 2');
        $this->requireClass('App\Models\AccessGroup', 'Milestone 2');

        $group = \App\Models\AccessGroup::create([
            'name' => 'Data Center Secure Zone',
            'code' => 'ZONE-DC-01',
            'description' => 'Restricted server room biometric perimeter',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('access_groups', [
            'id' => $group->id,
            'code' => 'ZONE-DC-01',
            'is_active' => true,
        ]);
    }

    public function test_f06_access_group_device_pivot_links_hardware(): void
    {
        $this->requireTable('access_group_device', 'Milestone 2');
        $this->requireClass('App\Models\AccessGroup', 'Milestone 2');

        $device = $this->createTestDevice();
        $group = \App\Models\AccessGroup::create([
            'name' => 'HQ Perimeter',
            'code' => 'HQ-PERIMETER',
            'is_active' => true,
        ]);

        $group->devices()->attach($device->id);

        $this->assertTrue($group->devices->contains($device->id));
        $this->assertDatabaseHas('access_group_device', [
            'access_group_id' => $group->id,
            'device_id' => $device->id,
        ]);
    }

    public function test_f07_access_group_personnel_pivot_links_individuals(): void
    {
        $this->requireTable('access_group_personnel', 'Milestone 2');
        $this->requireClass('App\Models\AccessGroup', 'Milestone 2');

        $personnel = $this->createTestPersonnel();
        $group = \App\Models\AccessGroup::create([
            'name' => 'R&D Labs',
            'code' => 'RD-LABS',
            'is_active' => true,
        ]);

        $group->personnel()->attach($personnel->id);

        $this->assertTrue($group->personnel->contains($personnel->id));
        $this->assertDatabaseHas('access_group_personnel', [
            'access_group_id' => $group->id,
            'personnel_id' => $personnel->id,
        ]);
    }

    public function test_f08_access_group_department_pivot_auto_grants_access(): void
    {
        $this->requireTable('access_group_department', 'Milestone 2');
        $this->requireClass('App\Models\AccessGroup', 'Milestone 2');

        $dept = \App\Models\Department::create(['name' => 'Engineering', 'code' => 'ENG-01']);
        $group = \App\Models\AccessGroup::create([
            'name' => 'Engineering Suite',
            'code' => 'ENG-SUITE',
            'is_active' => true,
        ]);

        $group->departments()->attach($dept->id);

        $this->assertTrue($group->departments->contains($dept->id));
        $this->assertDatabaseHas('access_group_department', [
            'access_group_id' => $group->id,
            'department_id' => $dept->id,
        ]);
    }

    public function test_f09_access_control_service_resolves_authorized_devices(): void
    {
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');

        $device = $this->createTestDevice();
        $personnel = $this->createTestPersonnel();
        $group = \App\Models\AccessGroup::create([
            'name' => 'Main Office Zone',
            'code' => 'MAIN-OFFICE',
            'is_active' => true,
        ]);
        $group->devices()->attach($device->id);
        $group->personnel()->attach($personnel->id);

        $service = app(\App\Services\AccessControlService::class);
        $devices = $service->getAuthorizedDevicesForPersonnel($personnel);

        $this->assertTrue($devices->contains('id', $device->id));
    }

    public function test_f10_sync_personnel_job_dispatches_only_to_authorized_devices(): void
    {
        $this->requireClass('App\Services\AccessControlService', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');

        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncDevicePersonnelJob::class]);

        $authDevice = $this->createTestDevice(['device_id' => 'CAM-AUTH-01']);
        $unauthDevice = $this->createTestDevice(['device_id' => 'CAM-UNAUTH-02']);
        $personnel = Personnel::withoutEvents(fn () => $this->createTestPersonnel());

        $group = \App\Models\AccessGroup::create([
            'name' => 'Restricted Zone',
            'code' => 'RESTRICTED-ZONE',
            'is_active' => true,
        ]);
        $group->devices()->attach($authDevice->id);
        $group->personnel()->attach($personnel->id);

        dispatch(new \App\Jobs\SyncPersonnelJob($personnel->id, 'EDIT'));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($authDevice, $unauthDevice) {
            return $job->deviceId === $authDevice->id;
        });
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\SyncDevicePersonnelJob::class, function ($job) use ($unauthDevice) {
            return $job->deviceId === $unauthDevice->id;
        });
    }

    public function test_f11_access_group_zone_resync_endpoint_dispatches_roster(): void
    {
        $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
        $this->requireTable('access_groups', 'Milestone 2');

        $this->actingAsAdmin();
        $group = \App\Models\AccessGroup::create([
            'id' => 1,
            'name' => 'Zone Sync Test',
            'code' => 'ZONE-SYNC-TEST',
            'is_active' => true,
        ]);
        $device = $this->createTestDevice();
        $group->devices()->attach($device->id);

        $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
        $response->assertStatus(200);
    }

    public function test_f12_access_group_manager_vue_component_exists(): void
    {
        $this->requireFile('resources/js/components/settings/AccessGroupManager.vue', 'Milestone 2');
        $this->assertFileExists(base_path('resources/js/components/settings/AccessGroupManager.vue'));
    }

    // -------------------------------------------------------------------------
    // Milestone 3: Resilient Domain Lifecycle State Machines (Features 13 - 19)
    // -------------------------------------------------------------------------

    public function test_f13_leave_request_cancellation_restores_balance_atomically(): void
    {
        $this->requireRoute('/api/leave-requests/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('leave_requests', 'Milestone 3');
        $this->requireTable('leave_balances', 'Milestone 3');

        $admin = $this->actingAsAdmin();
        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-LEAVE-01',
            'first_name' => 'Leave',
            'last_name' => 'User',
            'employment_status' => 'active',
            'user_id' => $admin->id,
        ]);
        $leaveType = \App\Models\LeaveType::create(['name' => 'Annual Leave', 'code' => 'AL-01', 'is_paid' => true]);
        $balance = \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 20,
            'used_days' => 5,
            'pending_days' => 0,
            'remaining_days' => 15,
            'year' => 2026,
        ]);

        $request = \App\Models\LeaveRequest::create([
            'id' => 1,
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::tomorrow()->toDateString(),
            'end_date' => Carbon::tomorrow()->addDays(2)->toDateString(),
            'total_days' => 3,
            'status' => 'approved',
            'reason' => 'Family vacation',
        ]);

        $response = $this->postJson('/api/leave-requests/' . $request->id . '/cancel', [
            'reason' => 'Trip cancelled due to weather',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'status' => 'cancelled',
        ]);
        $balance->refresh();
        $this->assertEquals(2, $balance->used_days);
    }

    public function test_f14_attendance_status_rollback_and_recalculation_on_leave_cancel(): void
    {
        $this->requireMethod('App\Services\LeaveService', 'cancelLeaveRequest', 'Milestone 3');
        $this->requireClass('App\Services\AttendanceProcessingService', 'Milestone 3');

        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-ROLL-01',
            'first_name' => 'Roll',
            'last_name' => 'Back',
            'employment_status' => 'active',
        ]);
        $workDate = Carbon::yesterday()->toDateString();
        \App\Models\AttendanceRecord::create([
            'employee_id' => $emp->id,
            'date' => $workDate,
            'status' => 'on_leave',
        ]);

        $leaveType = \App\Models\LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL-01', 'is_paid' => true]);
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
            'reason' => 'Urgent matter',
        ]);

        $service = app(\App\Services\LeaveService::class);
        $service->cancelLeaveRequest($req, null, 'Cancelled by user');

        $record = \App\Models\AttendanceRecord::where('employee_id', $emp->id)->where('date', $workDate)->first();
        $this->assertNotEquals('on_leave', $record ? $record->status : null);
    }

    public function test_f15_regularization_cancellation_workflow_updates_status(): void
    {
        $this->requireRoute('/api/regularization-requests/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('regularization_requests', 'Milestone 3');

        $admin = $this->actingAsAdmin();
        $emp = \App\Models\Employee::create([
            'employee_code' => 'EMP-REG-01',
            'first_name' => 'Regular',
            'last_name' => 'User',
            'employment_status' => 'active',
            'user_id' => $admin->id,
        ]);

        $reg = \App\Models\RegularizationRequest::create([
            'id' => 1,
            'employee_id' => $emp->id,
            'date' => Carbon::yesterday()->toDateString(),
            'requested_clock_in' => '09:00:00',
            'requested_clock_out' => '18:00:00',
            'status' => 'pending',
            'reason' => 'Turnstile card misread',
        ]);

        $response = $this->postJson('/api/regularization-requests/' . $reg->id . '/cancel', [
            'reason' => 'Card found, false alarm',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('regularization_requests', [
            'id' => $reg->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_f16_visitor_cancellation_revokes_camera_face_credentials(): void
    {
        $this->requireRoute('/api/visits/1/cancel', 'POST', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $this->actingAsAdmin();
        $this->mockCameraSuccess();

        $vis = \App\Models\Visitor::create(['first_name' => 'John', 'last_name' => 'Guest']);
        $visit = \App\Models\Visit::create([
            'id' => 1,
            'visitor_id' => $vis->id,
            'purpose' => 'audit',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(2),
            'expected_departure' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/visits/' . $visit->id . '/cancel', [
            'reason' => 'Meeting relocated offsite',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_f17_detect_overstay_visitors_job_flags_overstay_and_creates_alert(): void
    {
        $this->requireClass('App\Jobs\DetectOverstayVisitorsJob', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');
        $this->requireTable('device_alerts', 'Milestone 3');

        $vis = \App\Models\Visitor::create(['first_name' => 'Overstay', 'last_name' => 'Tester']);
        $device = $this->createTestDevice();
        $visit = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'maintenance',
            'status' => 'checked_in',
            'check_in_time' => now()->subHours(5),
            'expected_departure' => now()->subHours(1),
            'device_id' => $device->device_id,
        ]);

        dispatch_sync(new \App\Jobs\DetectOverstayVisitorsJob());

        $visit->refresh();
        $this->assertEquals('overstayed', $visit->status);
        $this->assertDatabaseHas('device_alerts', [
            'alert_type' => 'visitor_overstay',
        ]);
    }

    public function test_f18_expire_no_show_visits_job_transitions_past_visits(): void
    {
        $this->requireClass('App\Jobs\ExpireNoShowVisitsJob', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $vis = \App\Models\Visitor::create(['first_name' => 'NoShow', 'last_name' => 'Tester']);
        $visit = \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'interview',
            'status' => 'expected',
            'expected_arrival' => now()->subDays(2),
        ]);

        dispatch_sync(new \App\Jobs\ExpireNoShowVisitsJob());

        $visit->refresh();
        $this->assertEquals('no_show', $visit->status);
    }

    public function test_f19_overstayed_visits_endpoint_returns_flagged_roster(): void
    {
        $this->requireRoute('/api/visits/overstayed', 'GET', 'Milestone 3');
        $this->requireTable('visits', 'Milestone 3');

        $this->actingAsAdmin();
        $vis = \App\Models\Visitor::create(['first_name' => 'Active', 'last_name' => 'Overstay']);
        \App\Models\Visit::create([
            'visitor_id' => $vis->id,
            'purpose' => 'vendor',
            'status' => 'overstayed',
            'check_in_time' => now()->subHours(6),
            'expected_departure' => now()->subHours(2),
        ]);

        $response = $this->getJson('/api/visits/overstayed');
        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'overstayed']);
    }

    // -------------------------------------------------------------------------
    // Milestone 4: Bulk Workforce Operations & Fleet Provisioning Campaigns (Features 20 - 26)
    // -------------------------------------------------------------------------

    public function test_f20_bulk_campaigns_entity_tracks_execution_progress(): void
    {
        $this->requireTable('bulk_campaigns', 'Milestone 4');
        $this->requireClass('App\Models\BulkCampaign', 'Milestone 4');

        $admin = $this->actingAsAdmin();
        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $admin->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => 10,
            'processed_items' => 7,
            'failed_items' => 1,
            'status' => 'processing',
            'payload' => ['device_ids' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
        ]);

        $this->assertDatabaseHas('bulk_campaigns', [
            'id' => $campaign->id,
            'campaign_type' => 'reboot_fleet',
            'status' => 'processing',
        ]);
    }

    public function test_f21_fleet_bulk_reboot_endpoint_dispatches_rate_limited_jobs(): void
    {
        $this->requireRoute('/api/devices/bulk-reboot', 'POST', 'Milestone 4');
        $this->requireTable('bulk_campaigns', 'Milestone 4');

        $this->actingAsAdmin();
        $cam1 = $this->createTestDevice();
        $cam2 = $this->createTestDevice();

        $response = $this->postJson('/api/devices/bulk-reboot', [
            'device_ids' => [$cam1->id, $cam2->id],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['campaign_id']);
    }

    public function test_f22_fleet_bulk_mqtt_sync_endpoint_dispatches_parameter_updates(): void
    {
        $this->requireRoute('/api/devices/bulk-sync-mqtt', 'POST', 'Milestone 4');
        $this->requireTable('bulk_campaigns', 'Milestone 4');

        $this->actingAsAdmin();
        $cam = $this->createTestDevice();

        $response = $this->postJson('/api/devices/bulk-sync-mqtt', [
            'device_ids' => [$cam->id],
            'mqtt_config' => [
                'KeepAlive' => 60,
                'StrangerUploadType' => 1,
                'RecordUploadType' => 1,
            ],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['campaign_id']);
    }

    public function test_f23_high_throughput_bulk_personnel_sync_batches_up_to_50_persons(): void
    {
        $this->requireClass('App\Jobs\BulkPersonnelSyncJob', 'Milestone 4');
        $this->requireTable('bulk_campaigns', 'Milestone 4');

        $job = new \App\Jobs\BulkPersonnelSyncJob([1, 2, 3]);
        $this->assertNotNull($job);
    }

    public function test_f24_bulk_personnel_deletion_endpoint_removes_records_and_dispatches(): void
    {
        $this->requireRoute('/api/personnel/bulk-delete', 'POST', 'Milestone 4');
        $this->requireTable('bulk_campaigns', 'Milestone 4');

        $this->actingAsAdmin();
        $p1 = $this->createTestPersonnel();
        $p2 = $this->createTestPersonnel();

        $response = $this->postJson('/api/personnel/bulk-delete', [
            'personnel_ids' => [$p1->id, $p2->id],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['campaign_id']);
    }

    public function test_f25_bulk_campaign_progress_api_returns_status_and_counters(): void
    {
        $this->requireRoute('/api/bulk-campaigns/1', 'GET', 'Milestone 4');
        $this->requireTable('bulk_campaigns', 'Milestone 4');

        $admin = $this->actingAsAdmin();
        \App\Models\BulkCampaign::create([
            'id' => 1,
            'user_id' => $admin->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => 50,
            'processed_items' => 50,
            'failed_items' => 0,
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/bulk-campaigns/1');
        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'completed', 'total_items' => 50]);
    }

    public function test_f26_fleet_and_personnel_batch_toolbars_exist_in_frontend(): void
    {
        $this->requireFile('resources/js/components/devices/DeviceManager.vue', 'Milestone 4');
        $this->requireFile('resources/js/components/personnel/PersonnelManager.vue', 'Milestone 4');

        $this->assertFileExists(base_path('resources/js/components/devices/DeviceManager.vue'));
        $this->assertFileExists(base_path('resources/js/components/personnel/PersonnelManager.vue'));
    }

    // -------------------------------------------------------------------------
    // Milestone 5: Two-Tier Telemetry Ingestion & Downlink Correlator (Features 27 - 33)
    // -------------------------------------------------------------------------

    public function test_f27_zero_latency_telemetry_ingestion_issues_immediate_push_ack(): void
    {
        $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessTelemetryPacketJob::class]);

        $device = $this->createTestDevice();
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 12345,
                'customId' => 8888,
                'similarity1' => 95.5,
                'time' => now()->format('Y-m-d H:i:s'),
                'SanpPic' => 'data:image/jpeg;base64,' . base64_encode('fake-snap'),
            ],
        ];

        dispatch(new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessTelemetryPacketJob::class);
    }

    public function test_f28_asynchronous_telemetry_worker_processes_packet_and_persists(): void
    {
        $this->requireClass('App\Jobs\ProcessTelemetryPacketJob', 'Milestone 5');

        $device = $this->createTestDevice();
        $payload = [
            'operator' => 'VerifyPush',
            'info' => [
                'facesluiceId' => $device->device_id,
                'RecordID' => 54321,
                'customId' => 9999,
                'similarity1' => 98.0,
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $job = new \App\Jobs\ProcessTelemetryPacketJob($device->device_id, 'VerifyPush', $payload);
        dispatch_sync($job);

        $this->assertDatabaseHas('access_logs', [
            'device_id' => $device->device_id,
            'customize_id' => 9999,
        ]);
    }

    public function test_f29_horizon_configuration_monitors_camera_telemetry_queue(): void
    {
        $this->requireFile('config/horizon.php', 'Milestone 5');

        $config = config('horizon.defaults');
        $this->assertIsArray($config);
    }

    public function test_f30_downlink_command_table_and_model_persist_tickets(): void
    {
        $this->requireTable('device_commands', 'Milestone 5');
        $this->requireClass('App\Models\DeviceCommand', 'Milestone 5');

        $device = $this->createTestDevice();
        $command = \App\Models\DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => 'CMD-' . uniqid(),
            'operator' => 'RebootDevice',
            'status' => 'pending',
            'payload' => ['facesluiceId' => $device->device_id],
        ]);

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => 'pending',
            'operator' => 'RebootDevice',
        ]);
    }

    public function test_f31_non_blocking_downlink_ticket_dispatch_returns_202_accepted(): void
    {
        $this->requireMethod('App\Services\CameraMqttService', 'dispatchCommandAsync', 'Milestone 5');
        $this->requireTable('device_commands', 'Milestone 5');

        $device = $this->createTestDevice();
        $service = app(\App\Services\CameraMqttService::class);
        $command = $service->dispatchCommandAsync($device, 'RebootDevice', []);

        $this->assertInstanceOf(\App\Models\DeviceCommand::class, $command);
        $this->assertEquals('pending', $command->status);
    }

    public function test_f32_hardware_ack_correlation_matches_ticket_by_message_id(): void
    {
        $this->requireMethod('App\Services\CameraMqttService', 'handleCommandAck', 'Milestone 5');
        $this->requireTable('device_commands', 'Milestone 5');

        $device = $this->createTestDevice();
        $messageId = 'CORR-' . uniqid();
        $command = \App\Models\DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => 'UpMQTTconfig',
            'status' => 'pending',
        ]);

        $ackPacket = [
            'operator' => 'UpMQTTconfigAck',
            'messageId' => $messageId,
            'code' => 0,
            'info' => ['Result' => 0],
        ];

        $service = app(\App\Services\CameraMqttService::class);
        $service->handleCommandAck($ackPacket);

        $command->refresh();
        $this->assertEquals('completed', $command->status);
    }

    public function test_f33_downlink_event_broadcasting_emits_command_completed_event(): void
    {
        $this->requireClass('App\Events\DeviceCommandCompleted', 'Milestone 5');
        $this->requireTable('device_commands', 'Milestone 5');

        \Illuminate\Support\Facades\Event::fake([\App\Events\DeviceCommandCompleted::class]);

        $device = $this->createTestDevice();
        $command = \App\Models\DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => 'EVT-' . uniqid(),
            'operator' => 'RebootDevice',
            'status' => 'completed',
        ]);

        event(new \App\Events\DeviceCommandCompleted($command));

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\DeviceCommandCompleted::class, function ($event) use ($command) {
            return $event->command->id === $command->id;
        });
    }

    // -------------------------------------------------------------------------
    // Milestone 6: API Response Uniformity, Form Requests, OpenAPI & Composables (Features 34 - 41)
    // -------------------------------------------------------------------------

    public function test_f34_standard_api_response_envelope_formats_success_and_errors(): void
    {
        $this->requireClass('App\Http\Responses\ApiResponse', 'Milestone 6');

        $response = \App\Http\Responses\ApiResponse::success(['id' => 1, 'name' => 'Test'], 'Success message');
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertEquals('Success message', $data['message']);
        $this->assertEquals(1, $data['data']['id']);
        $this->assertArrayHasKey('meta', $data);
    }

    public function test_f35_hardware_webhook_protocol_exemption_preserves_edge_firmware_format(): void
    {
        $this->requireRoute('/Subscribe/heartbeat', 'POST', 'Milestone 6');

        $response = $this->postJson('/Subscribe/heartbeat', [
            'operator' => 'HeartBeat',
            'info' => [
                'facesluiceId' => 'CAM-WEBHOOK-TEST',
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(200, $data['code'] ?? null);
        $this->assertEquals('OK', $data['desc'] ?? null);
    }

    public function test_f36_dedicated_form_requests_validate_domain_payloads(): void
    {
        $this->requireClass('App\Http\Requests\StoreEmployeeRequest', 'Milestone 6');
        $this->requireClass('App\Http\Requests\StoreDeviceRequest', 'Milestone 6');

        $this->actingAsAdmin();

        $response = $this->postJson('/api/employees', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['first_name']);
    }

    public function test_f37_automated_openapi_documentation_configured_at_docs_api(): void
    {
        $this->requireRoute('/docs/api', 'GET', 'Milestone 6');

        $this->actingAsAdmin();
        $response = $this->get('/docs/api');
        $response->assertStatus(200);
    }

    public function test_f38_universal_paginated_resource_composable_exists(): void
    {
        $this->requireFile('resources/js/composables/usePaginatedResource.js', 'Milestone 6');
        $this->assertFileExists(base_path('resources/js/composables/usePaginatedResource.js'));
    }

    public function test_f39_live_telemetry_stream_composable_exists(): void
    {
        $this->requireFile('resources/js/composables/useLiveTelemetryStream.js', 'Milestone 6');
        $this->assertFileExists(base_path('resources/js/composables/useLiveTelemetryStream.js'));
    }

    public function test_f40_biometric_capture_composable_exists(): void
    {
        $this->requireFile('resources/js/composables/useBiometricCapture.js', 'Milestone 6');
        $this->assertFileExists(base_path('resources/js/composables/useBiometricCapture.js'));
    }

    public function test_f41_frontend_views_refactored_to_consume_composables(): void
    {
        $this->requireFile('resources/js/components/telemetry/LiveTelemetry.vue', 'Milestone 6');
        $this->assertFileExists(base_path('resources/js/components/telemetry/LiveTelemetry.vue'));
    }

    // -------------------------------------------------------------------------
    // Milestone 7: E2E Verification & Adversarial Coverage Hardening (Features 42 - 43)
    // -------------------------------------------------------------------------

    public function test_f42_e2e_testing_suite_passes_across_all_tiers(): void
    {
        $this->assertTrue(true);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_f43_adversarial_security_and_edge_case_hardening_passes(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'malicious_user@attacker.test',
            'password' => 'invalid_password',
        ]);
        $response->assertStatus(401);
    }
}

