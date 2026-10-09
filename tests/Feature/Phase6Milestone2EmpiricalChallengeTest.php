<?php

namespace Tests\Feature;

use App\Events\DeviceAlertUpdated;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SyncTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase6Milestone2EmpiricalChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Adversarial M2 Org',
            'code' => 'ADV-M2',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Security Ops',
            'code' => 'SEC-OPS',
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'organization_id' => $this->org->id, 'is_active' => true]
        );
        $this->adminUser->roles()->sync([$adminRole->id]);

        Sanctum::actingAs($this->adminUser, ['*']);
    }

    // =========================================================================
    // TASK 6.5 EMPIRICAL STRESS TESTS: Employee::attendanceSummary & isRestDay
    // =========================================================================

    /**
     * EMPIRICAL TEST 1: Verify shift_assignments query count across a 31-day month is EXACTLY 1.
     */
    public function test_task6_5_shift_assignments_query_count_across_31_days_is_exactly_one(): void
    {
        $person = Personnel::create([
            'customize_id' => 7001,
            'name' => 'Agent ThirtyOne',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-P6-7001',
            'first_name' => 'Agent',
            'last_name' => 'ThirtyOne',
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
        EmployeeShiftAssignment::create([
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

        // CRITICAL ASSERTION: exactly 1 query to shift_assignments across 31 days
        $this->assertCount(1, $shiftAssignmentQueries, 'Expected exactly 1 query on shift_assignments for a 31-day date window, but found ' . count($shiftAssignmentQueries));

        // In October 2026: 31 days total.
        // Oct 1 is Thursday, Oct 2 is Friday.
        // Weekends: Oct 3-4, 10-11, 17-18, 24-25, 31 (9 weekend days).
        // Working days: 31 - 9 = 22 working days.
        $this->assertEquals(22, $response->json('total_working_days'), 'October 2026 Mon-Fri schedule should calculate exactly 22 working days.');
    }

    /**
     * EMPIRICAL TEST 2: Stress-test attendanceSummary across a 90-day multi-month span with shift change mid-period.
     */
    public function test_task6_5_shift_assignments_query_count_across_90_days_with_mid_period_shift_transition(): void
    {
        $person = Personnel::create([
            'customize_id' => 7002,
            'name' => 'Agent NinetyDays',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-P6-7002',
            'first_name' => 'Agent',
            'last_name' => 'NinetyDays',
            'employment_status' => 'active',
        ]);

        $shift1 = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekday Shift',
            'code' => 'WEEKDAY',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        $shift2 = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekend Shift',
            'code' => 'WEEKEND',
            'shift_start' => '10:00:00',
            'shift_end' => '19:00:00',
            'is_active' => true,
        ]);

        // Assignment 1: 2026-08-01 to 2026-08-31 (Mon-Fri working)
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift1->id,
            'effective_from' => '2026-08-01',
            'effective_to' => '2026-08-31',
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        // Assignment 2: 2026-09-01 onwards (Sat-Sun working, days 6, 7)
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift2->id,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'assigned_days' => [6, 7],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/employees/{$employee->id}/attendance-summary?from=2026-08-01&to=2026-10-31");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $shiftAssignmentQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'shift_assignments');
        });

        // Even across 92 days with multiple distinct shift assignments, query count must remain 1
        $this->assertCount(1, $shiftAssignmentQueries, 'Query count for shift_assignments must remain 1 even across a 92-day span with multiple shifts');

        // Verify calculation correctness across the shift transition:
        // August 2026 (Mon-Fri): 21 working days.
        // September 2026 (Sat-Sun): 8 weekend days.
        // October 2026 (Sat-Sun): 9 weekend days.
        // Total expected working days = 21 + 8 + 9 = 38.
        $this->assertEquals(38, $response->json('total_working_days'));
    }

    /**
     * EMPIRICAL TEST 3: Direct unit stress test of Employee::isRestDay backwards compatibility and preloading.
     */
    public function test_task6_5_is_rest_day_handles_both_preloaded_and_database_fallback(): void
    {
        $person = Personnel::create([
            'customize_id' => 7003,
            'name' => 'Agent DirectRestDay',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-P6-7003',
            'first_name' => 'Agent',
            'last_name' => 'DirectRestDay',
            'employment_status' => 'active',
        ]);

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Tue-Sat Shift',
            'code' => 'TUE-SAT',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
            'assigned_days' => ['tue', 'wed', 'thu', 'fri', 'sat'],
        ]);

        // 1. Without preloaded assignments (falls back to DB query)
        DB::flushQueryLog();
        DB::enableQueryLog();
        // Sunday Oct 4, 2026 -> Rest day
        $this->assertTrue($employee->isRestDay('2026-10-04'));
        // Monday Oct 5, 2026 -> Rest day (Tue-Sat worker)
        $this->assertTrue($employee->isRestDay('2026-10-05'));
        // Tuesday Oct 6, 2026 -> Work day
        $this->assertFalse($employee->isRestDay('2026-10-06'));
        $queriesWithoutPreload = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, array_filter($queriesWithoutPreload, fn($q) => str_contains(strtolower($q['query']), 'shift_assignments')));

        // 2. With preloaded collection (0 DB queries)
        $preloaded = collect([$assignment]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($employee->isRestDay('2026-10-04', $preloaded));
        $this->assertTrue($employee->isRestDay('2026-10-05', $preloaded));
        $this->assertFalse($employee->isRestDay('2026-10-06', $preloaded));
        $queriesWithPreload = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, array_filter($queriesWithPreload, fn($q) => str_contains(strtolower($q['query']), 'shift_assignments')));
    }

    // =========================================================================
    // TASK 6.6 EMPIRICAL STRESS TESTS: DeviceController::audit O(1) & MAX(id)
    // =========================================================================

    /**
     * EMPIRICAL TEST 4: Verify DeviceController::audit uses MAX(id) to select latest sync task
     * when multiple historical sync tasks exist for personnel.
     */
    public function test_task6_6_device_audit_uses_max_id_subquery_for_sync_tasks(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-AUDIT-MAX-01',
            'name' => 'Audit Subquery Cam',
            'ip_address' => '192.168.1.199',
            'is_active' => true,
        ]);

        $person1 = Personnel::create([
            'customize_id' => 8001,
            'name' => 'Personnel MultiTask',
            'person_type' => 0,
        ]);

        $person2 = Personnel::create([
            'customize_id' => 8002,
            'name' => 'Personnel SingleTask',
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
        $latestTaskP1 = SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person1->id,
            'action' => 'EDIT',
            'status' => 'COMPLETED',
            'created_at' => now()->subHour(),
        ]);

        // Single task for person2: PENDING
        $taskP2 = SyncTask::create([
            'device_id' => $device->device_id,
            'personnel_id' => $person2->id,
            'action' => 'ADD',
            'status' => 'PENDING',
            'created_at' => now()->subMinutes(10),
        ]);

        // Hardware edge roster mock via Redis cache (device_edge_roster:CAM-AUDIT-MAX-01)
        Cache::put("camera_edge_roster:{$device->device_id}", ['8001', '8002', '9999'], 60);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/devices/{$device->id}/audit");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Inspect queries for MAX(id) subquery
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

        // Person 8001: Verified on Camera (since in edge cache and latest task is COMPLETED)
        $auditP1 = collect($userRoster)->firstWhere('customize_id', 8001);
        $this->assertNotNull($auditP1);
        $this->assertEquals('SYNCED', $auditP1['status']);
        $this->assertEquals('COMPLETED', $auditP1['sync_task_status']);

        // Person 8002: Verified on Camera (since on edge)
        $auditP2 = collect($userRoster)->firstWhere('customize_id', 8002);
        $this->assertNotNull($auditP2);
        $this->assertEquals('SYNCED', $auditP2['status']);

        // Edge ID 9999: Untracked on Camera (exists on edge but not in local DB)
        $untracked = collect($userRoster)->firstWhere('customize_id', 9999);
        $this->assertNotNull($untracked, 'Hardware-only person 9999 must be reported as UNTRACKED.');
        $this->assertEquals('UNTRACKED', $untracked['status']);
        $this->assertTrue($untracked['on_camera']);
    }

    /**
     * EMPIRICAL TEST 5: Verify DeviceController::audit reconciles 100+ local personnel
     * and edge roster using O(1) hash map lookups without quadratic degradation.
     */
    public function test_task6_6_device_audit_handles_large_roster_with_hash_map(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-AUDIT-LARGE-01',
            'name' => 'High Capacity Turnstile',
            'ip_address' => '192.168.1.200',
            'is_active' => true,
        ]);

        $personnelBatch = [];
        $edgeRoster = [];

        for ($i = 1; $i <= 50; $i++) {
            $cId = 8100 + $i;
            $personnelBatch[] = [
                'customize_id' => $cId,
                'person_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => "Batch Subject {$cId}",
                'person_type' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // Even-numbered IDs exist on edge
            if ($i % 2 === 0) {
                $edgeRoster[] = (string) $cId;
            }
        }
        // Add 10 foreign untracked IDs to edge roster
        for ($j = 1; $j <= 10; $j++) {
            $edgeRoster[] = (string) (9000 + $j);
        }

        Personnel::insert($personnelBatch);
        Cache::put("camera_edge_roster:{$device->device_id}", $edgeRoster, 60);

        $startTime = microtime(true);
        $response = $this->getJson("/api/devices/{$device->id}/audit");
        $duration = microtime(true) - $startTime;

        $response->assertOk();
        $this->assertLessThan(1.0, $duration, 'Audit for 50 local personnel and 35 edge records should execute in under 1.0 second.');

        $userRoster = $response->json('face_audit.user_roster');
        // Total rows: 50 local + 10 untracked = 60
        $this->assertCount(60, $userRoster);

        $untrackedCount = collect($userRoster)->where('status', 'UNTRACKED')->count();
        $this->assertEquals(10, $untrackedCount);

        $syncedCount = collect($userRoster)->where('status', 'SYNCED')->count();
        $this->assertEquals(25, $syncedCount); // 25 even-numbered matched on edge

        $missingCount = collect($userRoster)->where('status', 'MISSING')->count();
        $this->assertEquals(25, $missingCount); // 25 odd-numbered not on edge and no sync task
    }

    // =========================================================================
    // TASK 6.7 EMPIRICAL STRESS TESTS: DeviceAlertController bulkUpdateStatus
    // =========================================================================

    /**
     * EMPIRICAL TEST 6: Verify DeviceAlertController::bulkUpdateStatus issues exactly 1 SQL UPDATE,
     * broadcasts DeviceAlertUpdated events, and evicts cache keys.
     */
    public function test_task6_7_bulk_update_status_issues_single_sql_update_and_evicts_cache(): void
    {
        Event::fake([DeviceAlertUpdated::class]);

        $device = Device::create([
            'device_id' => 'CAM-ALERT-BULK-01',
            'name' => 'Perimeter Alert Camera',
            'ip_address' => '192.168.1.205',
            'is_active' => true,
        ]);

        $alerts = [];
        for ($i = 1; $i <= 5; $i++) {
            $alerts[] = DeviceAlert::create([
                'device_id' => $device->device_id,
                'alert_type' => 'STRANGER_LOITERING',
                'severity' => 'HIGH',
                'title' => "Suspicious Activity #{$i}",
                'status' => 'NEW',
                'captured_at' => now()->subMinutes($i * 5),
            ]);
        }

        $alertIds = collect($alerts)->pluck('id')->all();

        // Populate caches
        Cache::put('device_alert_stats', ['total' => 10, 'new' => 5], 3600);
        Cache::put('dashboard_telemetry_stats', ['telemetry' => 'active'], 3600);

        $this->assertTrue(Cache::has('device_alert_stats'));
        $this->assertTrue(Cache::has('dashboard_telemetry_stats'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson('/api/device-alerts/bulk-status', [
            'ids' => $alertIds,
            'status' => 'RESOLVED',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '5 alerts updated to RESOLVED');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Filter all UPDATE queries on device_alerts
        $updateQueries = array_filter($queries, function ($q) {
            $sql = strtolower($q['query']);
            return str_starts_with($sql, 'update') && str_contains($sql, 'device_alerts');
        });

        // CRITICAL ASSERTION: exactly 1 atomic bulk UPDATE query regardless of 5 alerts
        $this->assertCount(1, $updateQueries, 'bulkUpdateStatus must execute exactly 1 atomic SQL UPDATE statement, but issued ' . count($updateQueries));

        // Verify database state: all 5 alerts must have status = RESOLVED and resolved_at set
        $this->assertEquals(5, DeviceAlert::whereIn('id', $alertIds)->where('status', 'RESOLVED')->count());
        $this->assertEquals(5, DeviceAlert::whereIn('id', $alertIds)->whereNotNull('resolved_at')->count());
        $this->assertEquals(5, DeviceAlert::whereIn('id', $alertIds)->where('resolved_by', $this->adminUser->id)->count());

        // CRITICAL ASSERTION: Cache keys device_alert_stats and dashboard_telemetry_stats MUST be evicted
        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats cache must be evicted on bulk status update.');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats cache must be evicted on bulk status update.');

        // Verify broadcast events: exactly 5 events dispatched
        Event::assertDispatched(DeviceAlertUpdated::class, 5);
    }

    /**
     * EMPIRICAL TEST 7: Verify DeviceAlertController::updateStatus single record endpoint
     * also evicts device_alert_stats and dashboard_telemetry_stats.
     */
    public function test_task6_7_single_update_status_evicts_cache_and_broadcasts(): void
    {
        Event::fake([DeviceAlertUpdated::class]);

        $device = Device::create([
            'device_id' => 'CAM-ALERT-SINGLE-01',
            'name' => 'Gate Alert Camera',
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

        Cache::put('device_alert_stats', ['cached' => true], 3600);
        Cache::put('dashboard_telemetry_stats', ['cached' => true], 3600);

        $response = $this->patchJson("/api/device-alerts/{$alert->id}/status", [
            'status' => 'ACKNOWLEDGED',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ACKNOWLEDGED');

        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats must be evicted on single alert status update.');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats must be evicted on single alert status update.');

        Event::assertDispatched(DeviceAlertUpdated::class, 1);
    }

    /**
     * ADVERSARIAL TEST 8: Employee without any shift assignments across 30+ days.
     * Verifies query count remains 1 and standard business week rest days (Sat/Sun) apply cleanly.
     */
    public function test_adversarial_task6_5_employee_without_shift_assignment_defaults_to_weekend_rest_days(): void
    {
        $person = Personnel::create([
            'customize_id' => 7099,
            'name' => 'Agent NoShift',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'personnel_id' => $person->id,
            'employee_code' => 'EMP-P6-NOSHIFT',
            'first_name' => 'Agent',
            'last_name' => 'NoShift',
            'employment_status' => 'active',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        // November 2026: 30 days. Nov 1 is Sunday.
        // Sundays: Nov 1, 8, 15, 22, 29 (5)
        // Saturdays: Nov 7, 14, 21, 28 (4)
        // Total weekends = 9. Total working days = 30 - 9 = 21.
        $response = $this->getJson("/api/employees/{$employee->id}/attendance-summary?from=2026-11-01&to=2026-11-30");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $shiftAssignmentQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'shift_assignments'));
        $this->assertCount(1, $shiftAssignmentQueries, 'Even without assignments, query count must be 1.');
        $this->assertEquals(21, $response->json('total_working_days'));
    }

    /**
     * ADVERSARIAL TEST 9: Device audit with outbox tasks only and empty edge hardware response.
     */
    public function test_adversarial_task6_6_device_audit_empty_edge_hardware_relies_on_outbox(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncPersonnelJob::class]);

        $device = Device::create([
            'device_id' => 'CAM-AUDIT-OFFLINE-01',
            'name' => 'Offline Turnstile',
            'ip_address' => '192.168.1.210',
            'is_active' => true,
        ]);

        $p1 = Personnel::create(['customize_id' => 8801, 'name' => 'Subject Completed', 'person_type' => 0]);
        $p2 = Personnel::create(['customize_id' => 8802, 'name' => 'Subject Pending', 'person_type' => 0]);
        $p3 = Personnel::create(['customize_id' => 8803, 'name' => 'Subject Untracked', 'person_type' => 0]);

        SyncTask::create(['device_id' => $device->device_id, 'personnel_id' => $p1->id, 'action' => 'ADD', 'status' => 'COMPLETED']);
        SyncTask::create(['device_id' => $device->device_id, 'personnel_id' => $p2->id, 'action' => 'ADD', 'status' => 'PENDING']);
        // p3 has no sync task

        // No edge roster in cache (hardware unreachable)
        Cache::forget("camera_edge_roster:{$device->device_id}");
        Cache::forget("camera_face_list:{$device->device_id}");

        $response = $this->getJson("/api/devices/{$device->id}/audit");
        $response->assertOk();

        $userRoster = $response->json('face_audit.user_roster');
        $this->assertCount(3, $userRoster);

        $row1 = collect($userRoster)->firstWhere('customize_id', 8801);
        $this->assertEquals('SYNCED', $row1['status']);
        $this->assertEquals('Synced (Outbox Confirmed)', $row1['status_label']);

        $row2 = collect($userRoster)->firstWhere('customize_id', 8802);
        $this->assertEquals('PENDING', $row2['status']);
        $this->assertEquals('Sync Pending', $row2['status_label']);

        $row3 = collect($userRoster)->firstWhere('customize_id', 8803);
        $this->assertEquals('MISSING', $row3['status']);
        $this->assertEquals('Missing on Camera', $row3['status_label']);
    }

    /**
     * ADVERSARIAL TEST 10: Bulk alert update rejects non-existent IDs with 422 and preserves cache.
     */
    public function test_adversarial_task6_7_bulk_update_rejects_non_existent_ids_without_evicting_cache(): void
    {
        Cache::put('device_alert_stats', ['healthy' => true], 3600);
        Cache::put('dashboard_telemetry_stats', ['healthy' => true], 3600);

        $response = $this->postJson('/api/device-alerts/bulk-status', [
            'ids' => [99999999], // non-existent
            'status' => 'RESOLVED',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ids.0']);

        // Cache must remain untouched since update failed
        $this->assertTrue(Cache::has('device_alert_stats'));
        $this->assertTrue(Cache::has('dashboard_telemetry_stats'));
    }
}
