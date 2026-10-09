<?php

namespace Tests\Feature;

use App\Events\AccessLogReceived;
use App\Http\Controllers\DashboardStatsController;
use App\Jobs\ImportCameraPersonnelJob;
use App\Jobs\SyncDevicePersonnelJob;
use App\Jobs\SyncPersonnelJob;
use App\Models\AccessLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SyncTask;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Observers\EmployeeObserver;
use App\Services\AttendanceProcessingService;
use App\Services\CameraMqttService;
use Carbon\Carbon;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Acme Performance Hub',
            'code' => 'ACME-PERF',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'organization_id' => $this->org->id, 'is_active' => true]
        );
        $this->adminUser->roles()->sync([$adminRole->id]);

        Sanctum::actingAs($this->adminUser, ['*']);
    }

    /**
     * Task 1.1: Verify composite & foreign key indexes exist in schema.
     */
    public function test_performance_and_foreign_key_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('device_alerts'));
        $this->assertTrue(Schema::hasTable('visits'));
        $this->assertTrue(Schema::hasTable('visitors'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertTrue(Schema::hasTable('leave_requests'));
        $this->assertTrue(Schema::hasTable('access_logs'));

        // Query database driver schema for indexes
        if (DB::getDriverName() === 'sqlite') {
            $deviceAlertIndexes = collect(DB::select("PRAGMA index_list('device_alerts')"))->pluck('name')->all();
            $this->assertContains('device_alerts_device_id_index', $deviceAlertIndexes);
            $this->assertContains('device_alerts_captured_at_severity_index', $deviceAlertIndexes);

            $accessLogIndexes = collect(DB::select("PRAGMA index_list('access_logs')"))->pluck('name')->all();
            $this->assertContains('access_logs_captured_at_verify_status_index', $accessLogIndexes);
            $this->assertContains('access_logs_customize_id_captured_at_index', $accessLogIndexes);

            $employeeIndexes = collect(DB::select("PRAGMA index_list('employees')"))->pluck('name')->all();
            $this->assertContains('employees_designation_id_index', $employeeIndexes);
            $this->assertContains('employees_shift_id_index', $employeeIndexes);
        }
    }

    /**
     * Task 1.2: Verify PersonnelObserver::deleting does not purge immutable AccessLogs.
     */
    public function test_personnel_deletion_preserves_telemetry_access_logs(): void
    {
        Queue::fake([SyncPersonnelJob::class]);

        $device = Device::create([
            'device_id' => 'CAM-TEST-AUDIT-01',
            'name' => 'Audit Gate Camera',
            'ip_address' => '192.168.1.101',
            'is_active' => true,
        ]);

        $person = Personnel::create([
            'customize_id' => 3001,
            'name' => 'Audit Subject One',
            'person_type' => 0,
        ]);

        $log1 = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $person->customize_id,
            'person_name' => $person->name,
            'verify_status' => 1,
            'captured_at' => now()->subHours(2),
        ]);

        $log2 = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $person->customize_id,
            'person_name' => $person->name,
            'verify_status' => 1,
            'captured_at' => now()->subHour(),
        ]);

        // Delete the personnel record
        $person->delete();

        // Personnel entity must be removed
        $this->assertDatabaseMissing('personnel', ['id' => $person->id]);

        // AccessLog telemetry records must remain completely intact as immutable compliance records
        $this->assertDatabaseHas('access_logs', ['id' => $log1->id]);
        $this->assertDatabaseHas('access_logs', ['id' => $log2->id]);
        $this->assertEquals(2, AccessLog::where('customize_id', 3001)->count());
    }

    /**
     * Task 1.2: Verify Employee deletion with preserve telemetry flag preserves access logs.
     */
    public function test_employee_deletion_with_preserve_telemetry_flag_preserves_access_logs(): void
    {
        Queue::fake([SyncPersonnelJob::class]);

        $device = Device::create([
            'device_id' => 'CAM-TEST-AUDIT-02',
            'name' => 'Audit Gate Camera 2',
            'ip_address' => '192.168.1.102',
            'is_active' => true,
        ]);

        $person = Personnel::create([
            'customize_id' => 3002,
            'name' => 'Preserved Employee Personnel',
            'person_type' => 0,
        ]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $person->customize_id,
            'person_name' => $person->name,
            'verify_status' => 1,
            'captured_at' => now()->subMinutes(30),
        ]);

        $employee = Employee::create([
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-AUDIT-02',
            'first_name' => 'Preserved',
            'last_name' => 'User',
            'employment_status' => 'active',
        ]);

        EmployeeObserver::$preserveTelemetryLogs = true;
        try {
            $resp = $this->deleteJson("/api/employees/{$employee->id}?preserve_telemetry=1");
            $resp->assertOk();

            $this->assertDatabaseHas('access_logs', ['id' => $log->id]);
            $this->assertEquals(1, AccessLog::where('customize_id', 3002)->count());
        } finally {
            EmployeeObserver::$preserveTelemetryLogs = false;
        }
    }

    /**
     * Task 1.3: Verify Personnel customize_id assigns monotonically increasing values.
     */
    public function test_personnel_customize_id_generates_atomically(): void
    {
        $p1 = Personnel::create(['name' => 'Auto ID User One', 'person_type' => 0]);
        $p2 = Personnel::create(['name' => 'Auto ID User Two', 'person_type' => 0]);

        $this->assertNotNull($p1->customize_id);
        $this->assertNotNull($p2->customize_id);
        $this->assertGreaterThanOrEqual(1000, $p1->customize_id);
        $this->assertGreaterThan($p1->customize_id, $p2->customize_id);
    }

    /**
     * Task 2.1: Verify SQL aggregate monthly attendance reporting.
     */
    public function test_monthly_attendance_report_aggregates_via_sql(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $emp1 = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => 'EMP-AGG-01',
            'first_name' => 'Alice',
            'last_name' => 'Engineer',
            'employment_status' => 'active',
        ]);

        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;
        $date1 = Carbon::create($currentYear, $currentMonth, 5)->toDateString();
        $date2 = Carbon::create($currentYear, $currentMonth, 6)->toDateString();

        AttendanceRecord::create([
            'employee_id' => $emp1->id,
            'date' => $date1,
            'status' => 'present',
            'total_work_hours' => 8.5,
            'overtime_hours' => 0.5,
        ]);

        AttendanceRecord::create([
            'employee_id' => $emp1->id,
            'date' => $date2,
            'status' => 'late',
            'total_work_hours' => 7.0,
            'overtime_hours' => 0.0,
        ]);

        $response = $this->getJson("/api/reports/attendance/monthly?month={$currentMonth}&year={$currentYear}");

        $response->assertOk()
            ->assertJsonPath('month', $currentMonth)
            ->assertJsonPath('year', $currentYear);

        $employeeRow = collect($response->json('data'))->firstWhere('employee_id', $emp1->id);
        $this->assertNotNull($employeeRow);
        $this->assertEquals('EMP-AGG-01', $employeeRow['employee_code']);
        $this->assertEquals(2, $employeeRow['days_present']); // present + late both count as present
        $this->assertEquals(1, $employeeRow['days_late']);
        $this->assertEquals(15.5, $employeeRow['total_work_hours']);
        $this->assertEquals(0.5, $employeeRow['total_overtime_hours']);
    }

    /**
     * Task 2.1: Verify payroll export JSON and CSV cursor streaming.
     */
    public function test_payroll_export_json_and_csv_streaming(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Finance',
            'code' => 'FIN',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => 'EMP-PAY-01',
            'first_name' => 'Bob',
            'last_name' => 'Finance',
            'employment_status' => 'active',
        ]);

        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        AttendanceRecord::create([
            'employee_id' => $emp->id,
            'date' => Carbon::create($currentYear, $currentMonth, 10)->toDateString(),
            'status' => 'present',
            'total_work_hours' => 8.0,
            'overtime_hours' => 1.0,
        ]);

        // JSON format
        $jsonResp = $this->getJson("/api/payroll/export?month={$currentMonth}&year={$currentYear}&format=json");
        $jsonResp->assertOk()
            ->assertJsonPath('total_employees', 1);

        $row = collect($jsonResp->json('data'))->firstWhere('employee_code', 'EMP-PAY-01');
        $this->assertNotNull($row);
        $this->assertEquals(1, $row['present_days']);
        $this->assertEquals(8.0, $row['total_work_hours']);
        $this->assertEquals(1.0, $row['overtime_hours']);

        // CSV streaming format
        $csvResp = $this->get("/api/payroll/export?month={$currentMonth}&year={$currentYear}&format=csv");
        $csvResp->assertOk();
        $this->assertStringContainsString('text/csv', $csvResp->headers->get('Content-Type'));
        $this->assertStringContainsString('"Employee Code"', $csvResp->streamedContent());
        $this->assertStringContainsString('"Employee Name"', $csvResp->streamedContent());
        $this->assertStringContainsString('EMP-PAY-01', $csvResp->streamedContent());
    }

    /**
     * Task 2.2: Verify photo_base64 is hidden from Personnel and Employee index responses.
     */
    public function test_personnel_and_employee_indexes_exclude_photo_base64(): void
    {
        $person = Personnel::create([
            'name' => 'Heavy Photo Person',
            'person_type' => 0,
            'photo_base64' => base64_encode('fake-multi-megabyte-image-content'),
            'photo_path' => 'personnel/photos/avatar1.jpg',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-PHOTO-TEST',
            'first_name' => 'Heavy',
            'last_name' => 'Photo',
            'employment_status' => 'active',
        ]);

        // 1. GET /api/personnel
        $personnelResp = $this->getJson('/api/personnel');
        $personnelResp->assertOk();
        $personnelItem = collect($personnelResp->json('data'))->firstWhere('id', $person->id);
        $this->assertNotNull($personnelItem);
        $this->assertArrayNotHasKey('photo_base64', $personnelItem);

        // 2. GET /api/employees
        $employeeResp = $this->getJson('/api/employees');
        $employeeResp->assertOk();
        $empItem = collect($employeeResp->json('data'))->firstWhere('id', $emp->id);
        $this->assertNotNull($empItem);
        if (isset($empItem['personnel'])) {
            $this->assertArrayNotHasKey('photo_base64', $empItem['personnel']);
        }
    }

    /**
     * Task 2.3: Verify camera import personnel returns 202 Accepted and queues background job.
     */
    public function test_import_camera_personnel_returns_202_accepted_and_dispatches_job(): void
    {
        Queue::fake([ImportCameraPersonnelJob::class]);

        $device = Device::create([
            'device_id' => 'CAM-ASYNC-IMPORT-01',
            'name' => 'Async Import Camera',
            'ip_address' => '192.168.1.150',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/import-personnel", ['async' => true]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'QUEUED')
            ->assertJsonPath('device_id', 'CAM-ASYNC-IMPORT-01')
            ->assertJsonStructure(['task_token']);

        Queue::assertPushed(ImportCameraPersonnelJob::class, function ($job) use ($device) {
            return $job->device->id === $device->id && $job->queue === 'camera-sync';
        });
    }

    /**
     * Task 3.1: Verify camera heartbeat database writes are throttled via Redis cache (60s TTL).
     */
    public function test_camera_heartbeat_writes_are_throttled_with_redis(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-THROTTLE-01',
            'name' => 'Throttle Test Camera',
            'ip_address' => '192.168.1.180',
            'is_active' => true,
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $initialHeartbeat = $device->last_heartbeat_at;

        // First heartbeat: should update DB and set Redis throttle key
        $resp1 = $this->postJson('/Subscribe/heartbeat', [
            'info' => ['DeviceID' => 'CAM-THROTTLE-01'],
        ]);
        $resp1->assertOk();

        $device->refresh();
        $firstUpdatedHeartbeat = $device->last_heartbeat_at;
        $this->assertTrue($firstUpdatedHeartbeat->gt($initialHeartbeat));
        $this->assertTrue(Cache::has('device_hb_throttle:CAM-THROTTLE-01'));

        // Immediate second heartbeat: should be throttled in cache without updating DB
        // Artificially change last_heartbeat_at to verify DB update was skipped
        $markerTime = Carbon::create(2026, 1, 1, 12, 0, 0);
        $device->update(['last_heartbeat_at' => $markerTime]);

        $resp2 = $this->postJson('/Subscribe/heartbeat', [
            'info' => ['DeviceID' => 'CAM-THROTTLE-01'],
        ]);
        $resp2->assertOk();

        $device->refresh();
        // Since throttle is active, last_heartbeat_at remains at the markerTime
        $this->assertEquals($markerTime->toIso8601String(), $device->last_heartbeat_at->toIso8601String());
    }

    /**
     * Task 3.2: Verify AccessLogReceived implements ShouldBroadcast on redis connection and broadcasts queue.
     */
    public function test_access_log_received_event_implements_should_broadcast_with_redis_and_broadcasts_queue(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-BCAST-01',
            'name' => 'Broadcast Test Camera',
            'ip_address' => '192.168.1.190',
            'is_active' => true,
        ]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => 9999,
            'person_name' => 'Broadcast Test User',
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        $event = new AccessLogReceived($log);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertEquals('broadcasts', $event->broadcastQueue());
        $this->assertEquals('broadcasts', $event->broadcastQueue);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals('private-access-logs', $channels[0]->name);
        $this->assertEquals('AccessLogReceived', $event->broadcastAs());
    }

    /**
     * Task 3.3: Verify parallel SyncDevicePersonnelJob dispatch.
     */
    public function test_sync_personnel_dispatches_parallel_sync_device_personnel_jobs(): void
    {
        $dev1 = Device::create([
            'device_id' => 'CAM-PARALLEL-01',
            'name' => 'Parallel Camera 1',
            'ip_address' => '192.168.1.201',
            'is_active' => true,
        ]);

        $dev2 = Device::create([
            'device_id' => 'CAM-PARALLEL-02',
            'name' => 'Parallel Camera 2',
            'ip_address' => '192.168.1.202',
            'is_active' => true,
        ]);

        $person = Personnel::create([
            'customize_id' => 4501,
            'name' => 'Parallel Sync Subject',
            'person_type' => 0,
        ]);

        Queue::fake([SyncDevicePersonnelJob::class]);

        $job = new SyncPersonnelJob($person->id, 'ADD');
        $job->handle(app(CameraMqttService::class));

        // Must dispatch independent SyncDevicePersonnelJob per active camera
        Queue::assertPushed(SyncDevicePersonnelJob::class, 2);
        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($job) use ($dev1) {
            return $job->deviceId === $dev1->id && $job->queue === 'camera-sync';
        });
        Queue::assertPushed(SyncDevicePersonnelJob::class, function ($job) use ($dev2) {
            return $job->deviceId === $dev2->id && $job->queue === 'camera-sync';
        });
    }

    /**
     * Task 4.1: Verify Dashboard stats consolidation and Redis caching.
     */
    public function test_dashboard_stats_endpoint_uses_caching_and_consolidates_alerts(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-STATS-01',
            'name' => 'Stats Camera',
            'ip_address' => '192.168.1.210',
            'is_active' => true,
            'last_heartbeat_at' => now(),
        ]);

        DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'AREA_INTRUSION',
            'severity' => 'CRITICAL',
            'title' => 'Intrusion Alert',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $resp1 = $this->getJson('/api/stats');
        $resp1->assertOk()
            ->assertJsonPath('telemetry.alerts_today', 1)
            ->assertJsonPath('telemetry.critical_alerts_today', 1)
            ->assertJsonPath('telemetry.unresolved_alerts', 1);

        // Verify cache key was set
        $this->assertTrue(Cache::has('dashboard_telemetry_stats'));

        // Inject second alert: response should still return cached 1 alert until cache expires
        DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'PPE_VIOLATION',
            'severity' => 'WARNING',
            'title' => 'PPE Alert',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $resp2 = $this->getJson('/api/stats');
        $resp2->assertOk()
            ->assertJsonPath('telemetry.alerts_today', 1); // Served from cache

        // After clearing cache, new consolidated query reflects updated count
        Cache::forget('dashboard_telemetry_stats');
        $resp3 = $this->getJson('/api/stats');
        $resp3->assertOk()
            ->assertJsonPath('telemetry.alerts_today', 2);
    }

    /**
     * Task 4.2: Verify AttendanceProcessingService caches holidays and shifts.
     */
    public function test_attendance_processing_service_caches_holidays_and_shifts(): void
    {
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day Shift',
            'code' => 'STD-DAY',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-CACHE-01',
            'first_name' => 'Shift',
            'last_name' => 'Cached',
            'employment_status' => 'active',
        ]);

        $holiday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'National Holiday',
            'date' => '2026-12-25',
            'is_recurring' => true,
        ]);

        /** @var AttendanceProcessingService $service */
        $service = app(AttendanceProcessingService::class);

        // 1. Holiday lookup caching
        $isHol = $service->isHoliday(Carbon::parse('2026-12-25'));
        $this->assertTrue($isHol);
        $this->assertTrue(Cache::has('holidays_2026'));

        // 2. Shift resolution caching
        $resolvedShift = $service->resolveEffectiveShift($emp, Carbon::parse('2026-10-01'));
        $this->assertNotNull($resolvedShift);
        $this->assertEquals($shift->id, $resolvedShift->id);
        $this->assertTrue(Cache::has("emp_shift:{$emp->id}:2026-10-01"));
    }

    /**
     * Task 1.4: Verify deep composite indexes exist for telemetry queries and range scans.
     */
    public function test_deep_composite_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('visits'));
        $this->assertTrue(Schema::hasTable('stranger_snaps'));
        $this->assertTrue(Schema::hasTable('sync_tasks'));
        $this->assertTrue(Schema::hasTable('attendance_records'));

        if (DB::getDriverName() === 'sqlite') {
            $visitIndexes = collect(DB::select("PRAGMA index_list('visits')"))->pluck('name')->all();
            $this->assertContains('idx_visits_expected_arrival_status', $visitIndexes);

            $strangerIndexes = collect(DB::select("PRAGMA index_list('stranger_snaps')"))->pluck('name')->all();
            $this->assertContains('idx_stranger_snaps_device_id_captured_at', $strangerIndexes);

            $syncIndexes = collect(DB::select("PRAGMA index_list('sync_tasks')"))->pluck('name')->all();
            $this->assertContains('idx_sync_tasks_device_id_updated_at', $syncIndexes);

            $attendanceIndexes = collect(DB::select("PRAGMA index_list('attendance_records')"))->pluck('name')->all();
            $this->assertContains('idx_attendance_records_date_status', $attendanceIndexes);
        }
    }

    /**
     * Task 2.4: Verify DailyAttendanceFinalizerJob processes employees in chunks without N+1.
     */
    public function test_daily_attendance_finalizer_chunking(): void
    {
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'General Shift',
            'code' => 'GEN',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $emp1 = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-FINAL-01',
            'first_name' => 'Alice',
            'last_name' => 'Final',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-FINAL-02',
            'first_name' => 'Bob',
            'last_name' => 'Final',
            'employment_status' => 'active',
        ]);

        $job = new \App\Jobs\DailyAttendanceFinalizerJob('2026-10-04');
        $job->handle(app(AttendanceProcessingService::class));

        // Both employees should have attendance records finalized
        $this->assertNotNull(AttendanceRecord::where('employee_id', $emp1->id)->whereDate('date', '2026-10-04')->first());
        $this->assertNotNull(AttendanceRecord::where('employee_id', $emp2->id)->whereDate('date', '2026-10-04')->first());
    }

    /**
     * Task 2.5: Verify bulk shift assignment performs batch SQL updates and inserts.
     */
    public function test_bulk_shift_assignment_batching(): void
    {
        $shift1 = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Morning Shift',
            'code' => 'MS',
            'shift_start' => '08:00:00',
            'shift_end' => '16:00:00',
        ]);

        $shift2 = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Night Shift',
            'code' => 'NS',
            'shift_start' => '22:00:00',
            'shift_end' => '06:00:00',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift1->id,
            'employee_code' => 'EMP-BATCH-01',
            'first_name' => 'Batch',
            'last_name' => 'Assign',
            'employment_status' => 'active',
        ]);

        $response = $this->postJson("/api/shifts/{$shift2->id}/assign", [
            'employee_ids' => [$emp->id],
            'effective_from' => '2026-11-01',
        ]);

        $response->assertStatus(201);
        $emp->refresh();
        $this->assertEquals($shift2->id, $emp->shift_id);
    }

    /**
     * Task 2.6: Verify Daily attendance pagination & CSV/JSON export streaming.
     */
    public function test_attendance_daily_pagination_and_streaming_exports(): void
    {
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Daily Shift',
            'code' => 'DS',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-DAILY-01',
            'first_name' => 'David',
            'last_name' => 'Daily',
            'employment_status' => 'active',
        ]);

        AttendanceRecord::create([
            'employee_id' => $emp->id,
            'shift_id' => $shift->id,
            'date' => '2026-10-04',
            'status' => 'present',
            'total_work_hours' => 8.0,
        ]);

        // Daily roster endpoint returns summary aggregates and paginated records
        $resp = $this->getJson('/api/attendance/daily?date=2026-10-04');
        $resp->assertOk();
        $this->assertEquals(1, $resp->json('summary.total'));
        $this->assertEquals(1, $resp->json('summary.present'));
        $this->assertNotNull($resp->json('records.data'));

        // Employee export streaming
        $empCsv = $this->get('/api/employees/export?format=csv');
        $empCsv->assertOk();

        $empJson = $this->get('/api/employees/export?format=json');
        $empJson->assertOk();

        // Payroll export streaming
        $payrollCsv = $this->get('/api/payroll/export?format=csv&year=2026&month=10');
        $payrollCsv->assertOk();

        $payrollJson = $this->get('/api/payroll/export?format=json&year=2026&month=10');
        $payrollJson->assertOk();
    }

    /**
     * Task 2.7: Column-specific eager loading excludes photo_base64 from access logs.
     */
    public function test_column_specific_eager_loading_excludes_photo_base64(): void
    {
        $person = Personnel::create([
            'customize_id' => 7711,
            'name' => 'Lightweight Subject',
            'person_type' => 0,
            'photo_base64' => 'MASSIVE_BASE64_STRING_THAT_SHOULD_NOT_BE_LOADED',
        ]);

        $device = Device::create([
            'device_id' => 'CAM-EAGER-01',
            'name' => 'Eager Camera',
            'ip_address' => '192.168.1.220',
            'is_active' => true,
        ]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => 7711,
            'person_name' => 'Lightweight Subject',
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        $resp = $this->getJson('/api/access-logs');
        $resp->assertOk();
        $data = $resp->json('data.0.personnel');
        $this->assertNotNull($data);
        $this->assertArrayNotHasKey('photo_base64', $data);
    }

    /**
     * Task 3.2: Verify all real-time events implement ShouldBroadcast on broadcasts queue.
     */
    public function test_all_realtime_events_implement_should_broadcast(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-EVENT-01',
            'name' => 'Event Camera',
            'ip_address' => '192.168.1.225',
            'is_active' => true,
        ]);

        $alert = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'AREA_INTRUSION',
            'severity' => 'WARNING',
            'title' => 'Intrusion',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $event1 = new \App\Events\DeviceAlertReceived($alert);
        $this->assertInstanceOf(ShouldBroadcast::class, $event1);
        $this->assertEquals('broadcasts', $event1->broadcastQueue);

        $event2 = new \App\Events\DeviceStatusUpdated($device);
        $this->assertInstanceOf(ShouldBroadcast::class, $event2);
        $this->assertEquals('broadcasts', $event2->broadcastQueue);
    }

    /**
     * Task 4.2: Verify DeviceAlert stats endpoint consolidation & caching.
     */
    public function test_device_alert_stats_caching_and_consolidation(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-ALERT-STATS',
            'name' => 'Alert Stats Camera',
            'ip_address' => '192.168.1.230',
            'is_active' => true,
        ]);

        DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'FIRE_DETECTION',
            'severity' => 'CRITICAL',
            'title' => 'Fire Alert',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $resp = $this->getJson('/api/device-alerts/stats');
        $resp->assertOk();
        $this->assertEquals(1, $resp->json('critical_today'));
        $this->assertTrue(Cache::has('device_alert_stats'));
    }

    /**
     * Task 4.3: Cache invalidation on holiday mutations and Employee::isHoliday caching.
     */
    public function test_holiday_mutations_invalidate_cache(): void
    {
        $holiday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Cached Holiday',
            'date' => '2026-07-04',
            'is_recurring' => false,
        ]);

        Cache::put('holidays_2026', ['dummy']);
        $this->assertTrue(Cache::has('holidays_2026'));

        $this->putJson("/api/holidays/{$holiday->id}", [
            'name' => 'Updated Holiday',
            'date' => '2026-07-04',
        ])->assertOk();

        // Invalidation should have evicted holidays_2026
        $this->assertFalse(Cache::has('holidays_2026'));
    }

    /**
     * Task 4.4: Device fleet counts caching in Redis.
     */
    public function test_device_fleet_counts_caching(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-FLEET-01',
            'name' => 'Fleet Camera',
            'ip_address' => '192.168.1.240',
            'is_active' => true,
        ]);

        $resp = $this->getJson('/api/devices');
        $resp->assertOk();
        $this->assertTrue(Cache::has("device_counts:{$device->device_id}"));
    }

    /**
     * Phase 6 Task 6.1: Assert query log on /api/visits and /api/attendance/punches
     * uses SARGable range clauses (whereBetween) and does NOT wrap columns in strftime() or whereDate().
     */
    public function test_phase6_attendance_and_visitor_queries_use_sargable_ranges(): void
    {
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'General Shift',
            'code' => 'GEN-SARG-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_overnight' => false,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-SARG-01',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'employment_status' => 'active',
        ]);

        $device = Device::create([
            'device_id' => 'CAM-SARG-01',
            'name' => 'Turnstile 1',
            'ip_address' => '192.168.1.180',
            'device_role' => 'bidirectional',
            'is_active' => true,
        ]);

        $visitor = Visitor::create([
            'organization_id' => $this->org->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'visitor-sarg@example.com',
        ]);

        Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $employee->id,
            'expected_arrival' => '2026-10-04 10:30:00',
            'status' => 'expected',
        ]);

        AttendancePunch::create([
            'employee_id' => $employee->id,
            'device_id' => $device->device_id,
            'punch_time' => '2026-10-04 09:15:00',
            'direction' => 'in',
            'source' => 'camera_auto',
        ]);

        // 1. Verify /api/visits SARGability
        DB::flushQueryLog();
        DB::enableQueryLog();

        $visitResp = $this->getJson('/api/visits?date=2026-10-04');
        $visitResp->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $visitQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'visits'));
        $this->assertNotEmpty($visitQueries, 'Expected queries against visits table');

        foreach ($visitQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('strftime', $sql, 'Visits query must not use strftime()');
            $this->assertStringNotContainsString('expected_arrival"::date', $sql, 'Visits query must not use ::date function wrap');
            if (str_contains($sql, 'expected_arrival')) {
                $this->assertStringContainsString('between', $sql, 'Visits query must use SARGable BETWEEN clause');
            }
        }

        // 2. Verify /api/attendance/punches SARGability
        DB::flushQueryLog();
        DB::enableQueryLog();

        $punchResp = $this->getJson('/api/attendance/punches?date=2026-10-04');
        $punchResp->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $punchQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'attendance_punches'));
        $this->assertNotEmpty($punchQueries, 'Expected queries against attendance_punches table');

        foreach ($punchQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('strftime', $sql, 'Punches query must not use strftime()');
            $this->assertStringNotContainsString('punch_time"::date', $sql, 'Punches query must not use ::date function wrap');
            if (str_contains($sql, 'punch_time') && !str_contains($sql, 'order by')) {
                $this->assertStringContainsString('between', $sql, 'Punches query must use SARGable BETWEEN clause');
            }
        }

        // 3. Defensive date parsing check: unparseable date strings safely return empty data instead of 500 error
        $badVisitResp = $this->getJson('/api/visits?date=unparseable-date');
        $badVisitResp->assertOk();
        $this->assertEmpty($badVisitResp->json('data'));

        $badPunchResp = $this->getJson('/api/attendance/punches?date=unparseable-date');
        $badPunchResp->assertOk();
        $this->assertEmpty($badPunchResp->json('data'));
    }

    /**
     * Phase 6 Task 6.2: Assert idx_access_logs_device_id_captured_at, idx_attendance_punches_device_id,
     * idx_notifications_notifiable_created_at, idx_notifications_notifiable_read_at exist in schema.
     */
    public function test_phase6_composite_and_foreign_key_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('access_logs'));
        $this->assertTrue(Schema::hasTable('attendance_punches'));
        $this->assertTrue(Schema::hasTable('notifications'));

        if (DB::getDriverName() === 'sqlite') {
            $accessLogIndexes = collect(DB::select("PRAGMA index_list('access_logs')"))->pluck('name')->all();
            $this->assertContains('idx_access_logs_device_id_captured_at', $accessLogIndexes);

            $punchIndexes = collect(DB::select("PRAGMA index_list('attendance_punches')"))->pluck('name')->all();
            $this->assertContains('idx_attendance_punches_device_id', $punchIndexes);

            $notifIndexes = collect(DB::select("PRAGMA index_list('notifications')"))->pluck('name')->all();
            $this->assertContains('idx_notifications_notifiable_created_at', $notifIndexes);
            $this->assertContains('idx_notifications_notifiable_read_at', $notifIndexes);
        } elseif (DB::getDriverName() === 'pgsql') {
            $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'"))->pluck('indexname')->all();
            $this->assertContains('idx_access_logs_device_id_captured_at', $indexes);
            $this->assertContains('idx_attendance_punches_device_id', $indexes);
            $this->assertContains('idx_notifications_notifiable_created_at', $indexes);
            $this->assertContains('idx_notifications_notifiable_read_at', $indexes);
        }
    }

    /**
     * Phase 6 Task 6.3: Assert /api/dashboard/stats filters sync_tasks query
     * with whereIn('status', ['PENDING', 'PROCESSING', 'FAILED']).
     */
    public function test_phase6_sync_tasks_dashboard_stats_query_filters_active_statuses(): void
    {
        Cache::forget('dashboard_telemetry_stats');

        // Dynamically ensure /api/dashboard/stats route exists pointing to DashboardStatsController
        \Illuminate\Support\Facades\Route::get('/api/dashboard/stats', [DashboardStatsController::class, 'index']);

        $device = Device::create([
            'device_id' => 'CAM-SYNC-TEST-01',
            'name' => 'Sync Test Cam',
            'ip_address' => '192.168.1.188',
            'is_active' => true,
        ]);

        $personnel = Personnel::create([
            'name' => 'Sync Person 1',
            'person_type' => 0,
        ]);

        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'ADD',
            'status' => 'COMPLETED',
        ]);

        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'ADD',
            'status' => 'PENDING',
        ]);

        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'EDIT',
            'status' => 'PROCESSING',
        ]);

        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $personnel->id,
            'action' => 'ADD',
            'status' => 'FAILED',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();
        $response->assertJsonPath('sync.pending', 2);
        $response->assertJsonPath('sync.failed', 1);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $syncQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'sync_tasks'));
        $this->assertNotEmpty($syncQueries, 'Expected query against sync_tasks');

        foreach ($syncQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringContainsString('status', $sql);
            $this->assertStringContainsString('in (?, ?, ?)', $sql);
            $bindings = array_map('strtoupper', $q['bindings']);
            $this->assertContains('PENDING', $bindings);
            $this->assertContains('PROCESSING', $bindings);
            $this->assertContains('FAILED', $bindings);
            $this->assertNotContains('COMPLETED', $bindings);
        }

        // Also verify /api/stats route produces identical filtered query when cache cleared
        Cache::forget('dashboard_telemetry_stats');
        $respStats = $this->getJson('/api/stats');
        $respStats->assertOk();
        $respStats->assertJsonPath('sync.pending', 2);
        $respStats->assertJsonPath('sync.failed', 1);
    }

    /**
     * Phase 6 Task 6.4: Assert paginated response structure and constrained columns
     * for /api/leave-balances, /api/locations, /api/departments, and /api/designations.
     */
    public function test_phase6_wide_read_endpoints_are_paginated_and_column_constrained(): void
    {
        // 1. Leave Balances
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Paid Time Off',
            'code' => 'PTO-P6',
            'max_days_per_year' => 20,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-P6-LEAVE',
            'first_name' => 'Diana',
            'last_name' => 'Prince',
            'employment_status' => 'active',
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 20,
            'used' => 5,
            'pending' => 2,
            'carried_over' => 0,
        ]);

        $leaveResp = $this->getJson('/api/leave-balances');
        $leaveResp->assertOk();
        $leaveResp->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'per_page',
            'to',
            'total',
        ]);

        $leaveData = $leaveResp->json('data.0');
        $this->assertNotNull($leaveData);
        $this->assertArrayHasKey('id', $leaveData);
        $this->assertArrayHasKey('employee_id', $leaveData);
        $this->assertArrayHasKey('leave_type_id', $leaveData);
        $this->assertArrayHasKey('year', $leaveData);
        $this->assertArrayHasKey('allocated', $leaveData);
        // Verify relationship column constraints
        $this->assertArrayHasKey('first_name', $leaveData['employee']);
        $this->assertArrayHasKey('employee_code', $leaveData['employee']);
        $this->assertArrayNotHasKey('bank_account_number', $leaveData['employee']);
        $this->assertArrayHasKey('name', $leaveData['leave_type']);
        $this->assertArrayHasKey('code', $leaveData['leave_type']);

        // 2. Locations
        Location::create([
            'organization_id' => $this->org->id,
            'name' => 'Metro Site Alpha',
            'code' => 'MSA-01',
            'address' => '456 Ayala Ave',
            'timezone' => 'Asia/Manila',
        ]);

        $locResp = $this->getJson('/api/locations');
        $locResp->assertOk();
        $locResp->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'per_page',
            'total',
        ]);

        $locData = $locResp->json('data.0');
        $this->assertNotNull($locData);
        $this->assertArrayHasKey('id', $locData);
        $this->assertArrayHasKey('name', $locData);
        $this->assertArrayHasKey('code', $locData);
        $this->assertArrayHasKey('organization', $locData);
        $this->assertArrayHasKey('name', $locData['organization']);
        $this->assertArrayNotHasKey('settings', $locData['organization']);

        // 3. Departments
        Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Data Engineering',
            'code' => 'DE-01',
            'description' => 'Big data analytics and pipelines',
        ]);

        $deptResp = $this->getJson('/api/departments');
        $deptResp->assertOk();
        $deptResp->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'per_page',
            'total',
        ]);

        $deptData = $deptResp->json('data.0');
        $this->assertNotNull($deptData);
        $this->assertArrayHasKey('id', $deptData);
        $this->assertArrayHasKey('name', $deptData);
        $this->assertArrayHasKey('code', $deptData);
        $this->assertArrayHasKey('description', $deptData);

        // 4. Designations
        Designation::create([
            'organization_id' => $this->org->id,
            'name' => 'Principal Architect',
            'code' => 'PR-ARCH',
            'level' => 5,
            'description' => 'Lead system architect',
        ]);

        $desigResp = $this->getJson('/api/designations');
        $desigResp->assertOk();
        $desigResp->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'per_page',
            'total',
        ]);

        $desigData = $desigResp->json('data.0');
        $this->assertNotNull($desigData);
        $this->assertArrayHasKey('id', $desigData);
        $this->assertArrayHasKey('name', $desigData);
        $this->assertArrayHasKey('code', $desigData);
        $this->assertArrayHasKey('level', $desigData);
        $this->assertArrayHasKey('organization', $desigData);
        $this->assertArrayHasKey('name', $desigData['organization']);
        $this->assertArrayNotHasKey('settings', $desigData['organization']);
    }

    /**
     * Phase 6 Task 6.5: Verify employee attendance summary pre-fetches shift assignments in a single O(1) query.
     */
    public function test_phase6_employee_attendance_summary_preloads_shift_assignments(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $person = Personnel::create([
            'customize_id' => 7101,
            'name' => 'Summary Worker',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-P6-7101',
            'first_name' => 'Summary',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day Shift',
            'code' => 'STD-DAY',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        // Assign shift starting Oct 1, 2026, Monday-Friday (1, 2, 3, 4, 5)
        \App\Models\EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/employees/{$employee->id}/attendance-summary?from=2026-10-01&to=2026-10-31");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $shiftAssignmentQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'shift_assignments');
        });

        // Exactly 1 query on shift_assignments across 31 days
        $this->assertCount(1, $shiftAssignmentQueries, 'Expected exactly 1 query on shift_assignments for a 31-day date window.');
        $this->assertEquals(22, $response->json('total_working_days'), 'October 2026 Mon-Fri schedule should calculate exactly 22 working days.');
    }

    /**
     * Phase 6 Task 6.6: Verify device audit uses MAX(id) subquery for sync tasks and O(1) hash map matching.
     */
    public function test_phase6_device_audit_uses_hash_map_and_latest_sync_task_query(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-AUDIT-P6',
            'name' => 'Audit P6 Camera',
            'ip_address' => '192.168.1.199',
            'is_active' => true,
        ]);

        $person1 = Personnel::create([
            'customize_id' => 8201,
            'name' => 'Audit Person One',
            'person_type' => 0,
        ]);

        $person2 = Personnel::create([
            'customize_id' => 8202,
            'name' => 'Audit Person Two',
            'person_type' => 0,
        ]);

        // Historical tasks for person1: task 1 FAILED, task 2 PENDING, task 3 COMPLETED
        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person1->id,
            'action' => 'ADD',
            'status' => 'FAILED',
            'created_at' => now()->subHours(3),
        ]);
        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person1->id,
            'action' => 'EDIT',
            'status' => 'PENDING',
            'created_at' => now()->subHours(2),
        ]);
        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person1->id,
            'action' => 'EDIT',
            'status' => 'COMPLETED',
            'created_at' => now()->subHour(),
        ]);

        // Single task for person2: PENDING
        SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person2->id,
            'action' => 'ADD',
            'status' => 'PENDING',
            'created_at' => now()->subMinutes(10),
        ]);

        // Mock edge roster in cache
        Cache::put("camera_edge_roster:{$device->device_id}", ['8201', '8202', '9999'], 60);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/devices/{$device->id}/audit");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $syncTaskQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'sync_tasks'));
        $this->assertNotEmpty($syncTaskQueries);

        $hasMaxSubquery = false;
        foreach ($syncTaskQueries as $q) {
            $sql = strtolower($q['query']);
            if (str_contains($sql, 'max(id)') || str_contains($sql, 'max("id")')) {
                $hasMaxSubquery = true;
                break;
            }
        }
        $this->assertTrue($hasMaxSubquery, 'Device audit must query sync tasks using a MAX(id) subquery to select only latest tasks.');

        $userRoster = $response->json('face_audit.user_roster');
        $this->assertIsArray($userRoster);

        $auditP1 = collect($userRoster)->firstWhere('customize_id', 8201);
        $this->assertNotNull($auditP1);
        $this->assertEquals('SYNCED', $auditP1['status']);
        $this->assertEquals('COMPLETED', $auditP1['sync_task_status']);

        $auditP2 = collect($userRoster)->firstWhere('customize_id', 8202);
        $this->assertNotNull($auditP2);
        $this->assertEquals('SYNCED', $auditP2['status']);

        $untracked = collect($userRoster)->firstWhere('customize_id', 9999);
        $this->assertNotNull($untracked);
        $this->assertEquals('UNTRACKED', $untracked['status']);
    }

    /**
     * Phase 6 Task 6.7: Verify bulk status update issues a single atomic SQL UPDATE and evicts stats cache.
     */
    public function test_phase6_device_alert_bulk_update_status_is_atomic(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-ALERT-ATOMIC-01',
            'name' => 'Atomic Alert Camera',
            'ip_address' => '192.168.1.205',
            'is_active' => true,
        ]);

        $alertIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $alert = DeviceAlert::create([
                'device_id' => $device->device_id,
                'alert_type' => 'STRANGER_LOITERING',
                'severity' => 'HIGH',
                'title' => "Atomic Alert #{$i}",
                'status' => 'NEW',
                'captured_at' => now()->subMinutes($i * 5),
            ]);
            $alertIds[] = $alert->id;
        }

        Cache::put('device_alert_stats', ['total' => 10], 3600);
        Cache::put('dashboard_telemetry_stats', ['telemetry' => 'active'], 3600);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson('/api/device-alerts/bulk-status', [
            'ids' => $alertIds,
            'status' => 'RESOLVED',
        ]);
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $updateQueries = array_filter($queries, function ($q) {
            $sql = strtolower($q['query']);
            return str_starts_with($sql, 'update') && str_contains($sql, 'device_alerts');
        });

        $this->assertCount(1, $updateQueries, 'bulkUpdateStatus must execute exactly 1 atomic SQL UPDATE statement.');
        $this->assertEquals(5, DeviceAlert::whereIn('id', $alertIds)->where('status', 'RESOLVED')->count());
        $this->assertEquals(5, DeviceAlert::whereIn('id', $alertIds)->whereNotNull('resolved_at')->count());

        $this->assertFalse(Cache::has('device_alert_stats'));
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'));
    }

    /**
     * Phase 6 Task 6.8: Verify bulk shift assignment uses non-blocking O(1) version counter and tracked key invalidation.
     */
    public function test_phase6_bulk_shift_assignment_uses_non_blocking_cache_invalidation(): void
    {
        $shiftA = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Original Morning Shift',
            'code' => 'S-ORIG-P6',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        $shiftB = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Replacement Evening Shift',
            'code' => 'S-REPL-P6',
            'shift_start' => '16:00:00',
            'shift_end' => '01:00:00',
            'is_active' => true,
        ]);

        $emp1 = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shiftA->id,
            'employee_code' => 'EMP-P6-NB1',
            'first_name' => 'NonBlocking',
            'last_name' => 'Worker1',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shiftA->id,
            'employee_code' => 'EMP-P6-NB2',
            'first_name' => 'NonBlocking',
            'last_name' => 'Worker2',
            'employment_status' => 'active',
        ]);

        /** @var AttendanceProcessingService $service */
        $service = app(AttendanceProcessingService::class);

        // 1. Warm cache for effective shift on date
        $resolvedA = $service->resolveEffectiveShift($emp1, '2026-11-01');
        $this->assertEquals($shiftA->id, $resolvedA->id);
        $this->assertTrue(Cache::has("emp_shift:{$emp1->id}:2026-11-01"));

        // 2. Perform bulk shift assignment
        $response = $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $shiftB->id,
            'employee_ids' => [$emp1->id, $emp2->id],
            'effective_from' => '2026-11-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);
        $response->assertStatus(201);

        // 3. Assert version counter was incremented in O(1)
        $version = (int) Cache::get("emp_shift_v:{$emp1->id}", 0);
        $this->assertGreaterThanOrEqual(1, $version);

        // 4. Assert old cache key was evicted without Redis KEYS scan
        $this->assertFalse(Cache::has("emp_shift:{$emp1->id}:2026-11-01"));

        // 5. Subsequent call returns Shift B
        $resolvedB = $service->resolveEffectiveShift($emp1, '2026-11-01');
        $this->assertEquals($shiftB->id, $resolvedB->id);
    }

    /**
     * Phase 6 Task 6.9: Verify MQTT listener caches registered device existence and skips redundant SELECTs.
     */
    public function test_phase6_mqtt_listener_caches_registered_device_existence(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-MQTT-CACHE-01',
            'name' => 'Cache Test Camera',
            'ip_address' => '192.168.1.200',
            'is_active' => true,
        ]);

        // DeviceObserver warms cache upon creation
        $this->assertTrue((bool) Cache::get("device_registered:CAM-MQTT-CACHE-01"));

        // Forget to test lazy resolution in MqttListenCommand
        Cache::forget("device_registered:CAM-MQTT-CACHE-01");

        $command = new class extends \App\Console\Commands\MqttListenCommand {
            public function testCheckDevice(string $deviceId): bool {
                return $this->isDeviceRegisteredAndActive($deviceId);
            }
        };

        // First call queries DB and populates cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $isActiveFirst = $command->testCheckDevice('CAM-MQTT-CACHE-01');
        $this->assertTrue($isActiveFirst);
        $this->assertTrue((bool) Cache::get('device_registered:CAM-MQTT-CACHE-01'));
        $queriesFirst = array_filter(DB::getQueryLog(), fn($q) => str_contains($q['query'], 'devices'));
        $this->assertNotEmpty($queriesFirst);

        // Second call hits cache with 0 DB queries to devices
        DB::flushQueryLog();
        $isActiveSecond = $command->testCheckDevice('CAM-MQTT-CACHE-01');
        $this->assertTrue($isActiveSecond);
        $queriesSecond = array_filter(DB::getQueryLog(), fn($q) => str_contains($q['query'], 'devices'));
        $this->assertCount(0, $queriesSecond);

        // Deactivation via DeviceObserver must immediately update cache to false
        $device->update(['is_active' => false]);
        $this->assertFalse((bool) Cache::get('device_registered:CAM-MQTT-CACHE-01'));
        $this->assertFalse($command->testCheckDevice('CAM-MQTT-CACHE-01'));
    }

    /**
     * Phase 6 Task 6.10: Verify ProcessAttendancePunchJob caches customize_id to employee mapping.
     */
    public function test_phase6_punch_job_caches_customize_id_to_employee_bridge(): void
    {
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'General Day Shift',
            'code' => 'GEN-DAY-P6',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);

        $personnel = Personnel::create([
            'customize_id' => 8801,
            'name' => 'Alice Cache',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-8801',
            'first_name' => 'Alice',
            'last_name' => 'Cache',
            'employment_status' => 'active',
        ]);

        $device = Device::create([
            'device_id' => 'CAM-PUNCH-8801',
            'name' => 'Punch In Camera',
            'ip_address' => '192.168.1.105',
            'device_role' => 'entry',
            'is_active' => true,
        ]);

        $log = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => 8801,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:01:00',
        ]);

        $job = new \App\Jobs\ProcessAttendancePunchJob($log);
        $job->handle(app(AttendanceProcessingService::class));

        // Assert cache bridge exists
        $this->assertTrue(Cache::has('emp_custom_id:8801'));
        $cachedData = Cache::get('emp_custom_id:8801');
        $this->assertEquals($employee->id, $cachedData['employee_id']);
        $this->assertEquals($personnel->id, $cachedData['personnel_id']);

        // Second punch for another log must not query Personnel table for customize_id
        $log2 = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => 8801,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 17:05:00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $job2 = new \App\Jobs\ProcessAttendancePunchJob($log2);
        $job2->handle(app(AttendanceProcessingService::class));

        $queries = collect(DB::getQueryLog());
        $personnelQueries = $queries->filter(fn ($q) => str_contains($q['query'], 'personnel') && str_contains($q['query'], 'customize_id'));
        $this->assertCount(0, $personnelQueries, 'Personnel customize_id lookup was bypassed via Redis cache bridge.');

        // Mutation of Employee must evict the cache
        $employee->update(['first_name' => 'Alice Renamed']);
        $this->assertFalse(Cache::has('emp_custom_id:8801'), 'Cache key emp_custom_id:8801 evicted on Employee update.');
    }

    /**
     * Phase 6 Task 6.11: Verify alert status mutations and public settings caching and invalidation engine.
     */
    public function test_phase6_alert_status_mutations_and_public_settings_caching_and_invalidation(): void
    {
        // 1. Device Alert Invalidation Verification
        Cache::put('device_alert_stats', ['cached' => true], 60);
        Cache::put('dashboard_telemetry_stats', ['cached' => true], 60);

        $device = Device::create([
            'device_id' => 'CAM-ALERT-611',
            'name' => 'Alert 611 Camera',
            'ip_address' => '192.168.1.206',
            'is_active' => true,
        ]);

        $alert = DeviceAlert::create([
            'device_id' => $device->device_id,
            'alert_type' => 'TAMPER_DETECTED',
            'severity' => 'CRITICAL',
            'title' => 'Camera Tampering Detected',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $putResp = $this->patchJson("/api/device-alerts/{$alert->id}/status", [
            'status' => 'ACKNOWLEDGED',
        ]);
        $putResp->assertOk();
        $this->assertFalse(Cache::has('device_alert_stats'));
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'));

        Cache::put('device_alert_stats', ['cached' => true], 60);
        Cache::put('dashboard_telemetry_stats', ['cached' => true], 60);

        $bulkResp = $this->postJson('/api/device-alerts/bulk-status', [
            'ids' => [$alert->id],
            'status' => 'RESOLVED',
        ]);
        $bulkResp->assertOk();
        $this->assertFalse(Cache::has('device_alert_stats'));
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'));

        // 2. Public Branding Settings Caching & Invalidation Verification
        \App\Models\Setting::create([
            'key' => 'system.company_name',
            'value' => 'Test Hub',
            'group' => 'system',
            'type' => 'string',
            'is_public' => true,
        ]);

        $pubResp1 = $this->getJson('/api/settings/public');
        $pubResp1->assertOk();
        $this->assertEquals('Test Hub', $pubResp1->json('data')['system.company_name']);
        $this->assertTrue(Cache::has('settings.public'));

        \App\Services\SettingService::set('system.company_name', 'Brand New Hub');
        $this->assertFalse(Cache::has('settings.public'));

        $pubResp2 = $this->getJson('/api/settings/public');
        $pubResp2->assertOk();
        $this->assertEquals('Brand New Hub', $pubResp2->json('data')['system.company_name']);
        $this->assertTrue(Cache::has('settings.public'));
    }
}

