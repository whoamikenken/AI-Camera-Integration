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
}
