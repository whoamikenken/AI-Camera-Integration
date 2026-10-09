<?php

namespace Tests\Feature;

use App\Jobs\DetectOverstayVisitorsJob;
use App\Jobs\ProcessAttendancePunchJob;
use App\Jobs\SyncPersonnelJob;
use App\Models\AccessLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\AttendanceProcessingService;
use App\Services\SettingService;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase6Milestone3Challenger2Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Department $dept;
    protected Shift $shift;
    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Adversarial Challenger Org',
            'code' => 'ADV-CHALLENGE',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering Ops',
            'code' => 'ENG-OPS',
        ]);

        $this->shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Regular Shift',
            'code' => 'REG-01',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        $this->device = Device::create([
            'device_id' => 'CAM-CHALLENGE-01',
            'name' => 'Main Gate Cam',
            'ip_address' => '192.168.1.150',
            'device_role' => 'entry',
            'is_active' => true,
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
    // TASK 6.10: Biometric customize_id Employee Mapping Cache & Invalidation
    // =========================================================================

    /**
     * CHALLENGE 6.10-A: Repeated punches with identical customize_id MUST drop queries on personnel table to ZERO.
     */
    public function test_repeated_punches_with_same_customize_id_drops_personnel_queries_to_zero(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9101,
            'name' => 'Empirical Target',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-CHALLENGE-9101',
            'first_name' => 'Empirical',
            'last_name' => 'Target',
            'employment_status' => 'active',
        ]);

        $log1 = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9101,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        // First punch: warms cache bridge emp_custom_id:9101
        $job1 = new ProcessAttendancePunchJob($log1);
        $job1->handle(app(AttendanceProcessingService::class));

        $this->assertTrue(Cache::has('emp_custom_id:9101'), 'Cache bridge emp_custom_id:9101 should exist after first punch');
        $cached = Cache::get('emp_custom_id:9101');
        $this->assertEquals($employee->id, $cached['employee_id']);
        $this->assertEquals($personnel->id, $cached['personnel_id']);

        // Second punch with identical customize_id
        $log2 = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9101,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 17:05:00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $job2 = new ProcessAttendancePunchJob($log2);
        $job2->handle(app(AttendanceProcessingService::class));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Any query containing "personnel" (regardless of where clause)
        $personnelQueries = array_filter($queries, function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'personnel');
        });

        $this->assertCount(
            0,
            $personnelQueries,
            'Repeated punch MUST NOT execute any SQL queries against the personnel table. Found: ' . json_encode(array_values($personnelQueries))
        );
    }

    /**
     * CHALLENGE 6.10-B: Modifying an Employee MUST immediately evict emp_custom_id cache.
     */
    public function test_modifying_employee_evicts_identity_bridge_cache_immediately(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9201,
            'name' => 'Alice Observer',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-CHALLENGE-9201',
            'first_name' => 'Alice',
            'last_name' => 'Observer',
            'employment_status' => 'active',
        ]);

        $log = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9201,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        // Warm the cache
        (new ProcessAttendancePunchJob($log))->handle(app(AttendanceProcessingService::class));
        $this->assertTrue(Cache::has('emp_custom_id:9201'), 'Cache must be populated after punch');

        // Mutate employee name
        $employee->update(['first_name' => 'Alice Renamed']);

        $this->assertFalse(
            Cache::has('emp_custom_id:9201'),
            'Cache emp_custom_id:9201 MUST be evicted immediately when Employee is updated'
        );

        // Next punch must reload fresh employee record without stale state
        $log2 = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9201,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 17:00:00',
        ]);

        (new ProcessAttendancePunchJob($log2))->handle(app(AttendanceProcessingService::class));

        $this->assertTrue(Cache::has('emp_custom_id:9201'), 'Cache should be re-warmed after subsequent punch');
        $cached = Cache::get('emp_custom_id:9201');
        $this->assertEquals($employee->id, $cached['employee_id']);
    }

    /**
     * CHALLENGE 6.10-C: Modifying Personnel customize_id MUST clear both old and new cache keys.
     */
    public function test_modifying_personnel_customize_id_evicts_old_and_new_cache_keys(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9301,
            'name' => 'Bob Transition',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-CHALLENGE-9301',
            'first_name' => 'Bob',
            'last_name' => 'Transition',
            'employment_status' => 'active',
        ]);

        $log = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9301,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        // Warm cache for 9301
        (new ProcessAttendancePunchJob($log))->handle(app(AttendanceProcessingService::class));
        $this->assertTrue(Cache::has('emp_custom_id:9301'));

        // Pre-populate old and new keys to verify dirty change clears both
        Cache::put('emp_custom_id:9302', ['stale' => true], 3600);

        // Update Personnel customize_id from 9301 to 9302
        $personnel->update(['customize_id' => 9302]);

        $this->assertFalse(Cache::has('emp_custom_id:9301'), 'Old customize_id 9301 MUST be evicted on update');
        $this->assertFalse(Cache::has('emp_custom_id:9302'), 'New customize_id 9302 MUST be evicted on update');

        // Punch with old customize_id 9301 must NOT resolve to Bob (no stale identity)
        $oldLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9301,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 09:00:00',
        ]);
        (new ProcessAttendancePunchJob($oldLog))->handle(app(AttendanceProcessingService::class));

        $cachedOld = Cache::get('emp_custom_id:9301');
        $this->assertNull($cachedOld['employee_id'] ?? null, 'Old customize_id must not map to any active employee');

        // Punch with new customize_id 9302 resolves Bob correctly
        $newLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9302,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 17:00:00',
        ]);
        (new ProcessAttendancePunchJob($newLog))->handle(app(AttendanceProcessingService::class));

        $cachedNew = Cache::get('emp_custom_id:9302');
        $this->assertEquals($employee->id, $cachedNew['employee_id']);
    }

    /**
     * CHALLENGE 6.10-D: Deleting Employee evicts cache and subsequent punch does not return stale identity.
     */
    public function test_deleting_employee_clears_cache_and_prevents_stale_identity_punch(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9401,
            'name' => 'Charlie Deletion',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-CHALLENGE-9401',
            'first_name' => 'Charlie',
            'last_name' => 'Deletion',
            'employment_status' => 'active',
        ]);

        $log = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9401,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        (new ProcessAttendancePunchJob($log))->handle(app(AttendanceProcessingService::class));
        $this->assertTrue(Cache::has('emp_custom_id:9401'));

        // Delete the employee
        $deletedEmpId = $employee->id;
        $employee->delete();

        $this->assertFalse(Cache::has('emp_custom_id:9401'), 'Cache emp_custom_id:9401 MUST be evicted on Employee deletion');

        // Adversarial check: Even if a stale cache value was injected manually,
        // ProcessAttendancePunchJob must detect Employee is missing, evict the stale key, and abort.
        Cache::put('emp_custom_id:9401', ['employee_id' => $deletedEmpId, 'personnel_id' => null], 3600);
        $this->assertTrue(Cache::has('emp_custom_id:9401'));

        $subsequentLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9401,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 17:00:00',
        ]);

        (new ProcessAttendancePunchJob($subsequentLog))->handle(app(AttendanceProcessingService::class));

        $this->assertFalse(
            Cache::has('emp_custom_id:9401'),
            'ProcessAttendancePunchJob must evict poisoned cache when Employee model is missing in DB'
        );
    }

    /**
     * CHALLENGE 6.10-E: Edge cases for stranger punches (null customize_id, unmapped customize_id, rejected status).
     */
    public function test_edge_cases_stranger_punches_null_and_unmapped_customize_id(): void
    {
        // 1. Null customize_id punch (stranger snapshot / unverified face)
        $nullLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => null,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new ProcessAttendancePunchJob($nullLog))->handle(app(AttendanceProcessingService::class));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $personnelQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'personnel'));
        $this->assertCount(0, $personnelQueries, 'Null customize_id must return immediately without querying personnel');

        // 2. Rejected verification (verify_status != 1)
        $rejectedLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9991,
            'verify_status' => 2, // Rejected
            'captured_at' => '2026-10-08 08:05:00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new ProcessAttendancePunchJob($rejectedLog))->handle(app(AttendanceProcessingService::class));

        $rejectedQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $rejectedQueries, 'Non-allowed verification must abort with zero database queries');

        // 3. Unmapped customize_id (stranger card / un-enrolled face)
        $unmappedLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 99999,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:10:00',
        ]);

        (new ProcessAttendancePunchJob($unmappedLog))->handle(app(AttendanceProcessingService::class));

        $cachedUnmapped = Cache::get('emp_custom_id:99999');
        $this->assertNotNull($cachedUnmapped);
        $this->assertNull($cachedUnmapped['employee_id']);

        // 4. Late enrollment: Stranger is later enrolled in personnel & employee
        $newPersonnel = Personnel::create([
            'customize_id' => 99999,
            'name' => 'Late Enrolled Subject',
            'person_type' => 0,
        ]);

        $this->assertFalse(
            Cache::has('emp_custom_id:99999'),
            'Personnel creation must invalidate negative cache for customize_id 99999'
        );

        $newEmployee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => $newPersonnel->id,
            'employee_code' => 'EMP-99999',
            'first_name' => 'Late',
            'last_name' => 'Subject',
            'employment_status' => 'active',
        ]);

        // Next punch now successfully maps to the new employee
        $subsequentLog = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 99999,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:30:00',
        ]);

        (new ProcessAttendancePunchJob($subsequentLog))->handle(app(AttendanceProcessingService::class));

        $resolvedCached = Cache::get('emp_custom_id:99999');
        $this->assertEquals($newEmployee->id, $resolvedCached['employee_id']);
    }

    /**
     * CHALLENGE 6.10-F: Fallback to employee_code when personnel record is absent, and verify invalidation.
     */
    public function test_employee_code_fallback_resolution_and_invalidation(): void
    {
        // Employee with numeric employee_code matching customize_id, but NO linked personnel record
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->shift->id,
            'personnel_id' => null,
            'employee_code' => '9501',
            'first_name' => 'CodeFallback',
            'last_name' => 'User',
            'employment_status' => 'active',
        ]);

        $log = AccessLog::create([
            'device_id' => $this->device->device_id,
            'customize_id' => 9501,
            'verify_status' => 1,
            'captured_at' => '2026-10-08 08:00:00',
        ]);

        (new ProcessAttendancePunchJob($log))->handle(app(AttendanceProcessingService::class));

        $this->assertTrue(Cache::has('emp_custom_id:9501'));
        $cached = Cache::get('emp_custom_id:9501');
        $this->assertEquals($employee->id, $cached['employee_id']);

        // Update employee_code: EmployeeObserver must clear both old and new code cache keys
        $employee->update(['employee_code' => '9502']);

        $this->assertFalse(Cache::has('emp_custom_id:9501'), 'Old employee_code 9501 MUST be evicted on change');
        $this->assertFalse(Cache::has('emp_custom_id:9502'), 'New employee_code 9502 MUST be evicted on change');
    }

    // =========================================================================
    // TASK 6.11: Public Settings Caching & Alert Stats Invalidation Engine
    // =========================================================================

    /**
     * CHALLENGE 6.11-A: Public settings cache MUST execute EXACTLY ZERO SQL queries on repeated requests.
     */
    public function test_settings_public_caching_executes_zero_sql_queries_on_subsequent_requests(): void
    {
        Setting::create([
            'key' => 'system.company_name',
            'value' => 'Alpha Sentinel Security',
            'group' => 'system',
            'type' => 'string',
            'is_public' => true,
        ]);

        Setting::create([
            'key' => 'system.support_phone',
            'value' => '+1-800-555-0199',
            'group' => 'system',
            'type' => 'string',
            'is_public' => true,
        ]);

        Setting::create([
            'key' => 'system.private_key',
            'value' => 'SUPER_SECRET_PAYLOAD',
            'group' => 'system',
            'type' => 'string',
            'is_public' => false,
        ]);

        Cache::forget('settings.public');

        // Request 1: Warm the cache
        $resp1 = $this->getJson('/api/settings/public');
        $resp1->assertOk();
        $this->assertEquals('Alpha Sentinel Security', $resp1->json('data')['system.company_name']);
        $this->assertEquals('+1-800-555-0199', $resp1->json('data')['system.support_phone']);
        $this->assertArrayNotHasKey('system.private_key', $resp1->json('data'), 'Private setting must NOT be included in public settings');
        $this->assertTrue(Cache::has('settings.public'));

        // Adversarial challenge: Repeated consecutive requests MUST execute 0 SQL queries
        for ($i = 0; $i < 5; $i++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $respLoop = $this->getJson('/api/settings/public');
            $respLoop->assertOk();

            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertCount(
                0,
                $queries,
                "Request #{$i} to /api/settings/public MUST hit Redis cache and execute 0 SQL queries."
            );
            $this->assertEquals('Alpha Sentinel Security', $respLoop->json('data')['system.company_name']);
        }
    }

    /**
     * CHALLENGE 6.11-B: Mutating settings via SettingService, SettingController, and Reset MUST evict settings.public.
     */
    public function test_settings_public_cache_invalidation_across_all_mutation_vectors(): void
    {
        Setting::create([
            'key' => 'system.portal_title',
            'value' => 'Old Title',
            'group' => 'system',
            'type' => 'string',
            'is_public' => true,
        ]);

        // 1. Warm cache
        $resp = $this->getJson('/api/settings/public');
        $resp->assertOk();
        $this->assertEquals('Old Title', $resp->json('data')['system.portal_title']);
        $this->assertTrue(Cache::has('settings.public'));

        // Vector 1: SettingService::set()
        SettingService::set('system.portal_title', 'Updated Title via Service');
        $this->assertFalse(Cache::has('settings.public'), 'SettingService::set MUST evict settings.public cache');

        $respAfterSet = $this->getJson('/api/settings/public');
        $respAfterSet->assertOk();
        $this->assertEquals('Updated Title via Service', $respAfterSet->json('data')['system.portal_title']);
        $this->assertTrue(Cache::has('settings.public'));

        // Vector 2: SettingController::update() (PUT /api/settings)
        $respPut = $this->putJson('/api/settings', [
            'settings' => [
                'system.portal_title' => 'Updated Title via Controller',
            ],
        ]);
        $respPut->assertOk();
        $this->assertFalse(Cache::has('settings.public'), 'SettingController::update MUST evict settings.public cache');

        $respAfterPatch = $this->getJson('/api/settings/public');
        $respAfterPatch->assertOk();
        $this->assertEquals('Updated Title via Controller', $respAfterPatch->json('data')['system.portal_title']);
        $this->assertTrue(Cache::has('settings.public'));

        // Vector 3: SettingService::reset()
        SettingService::reset('system');
        $this->assertFalse(Cache::has('settings.public'), 'SettingService::reset MUST evict settings.public cache');
    }

    /**
     * CHALLENGE 6.11-C: Single and bulk alert status mutations MUST invalidate device_alert_stats and dashboard_telemetry_stats.
     */
    public function test_alert_status_mutations_invalidate_stats_caches_across_all_transitions(): void
    {
        $alert1 = DeviceAlert::create([
            'device_id' => $this->device->device_id,
            'alert_type' => 'TAMPER_DETECTED',
            'severity' => 'CRITICAL',
            'title' => 'Tampering 1',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $alert2 = DeviceAlert::create([
            'device_id' => $this->device->device_id,
            'alert_type' => 'AREA_INTRUSION',
            'severity' => 'WARNING',
            'title' => 'Intrusion 2',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        $alert3 = DeviceAlert::create([
            'device_id' => $this->device->device_id,
            'alert_type' => 'LINE_CROSSING',
            'severity' => 'INFO',
            'title' => 'Line Cross 3',
            'status' => 'NEW',
            'captured_at' => now(),
        ]);

        // Transition 1: Single update NEW -> ACKNOWLEDGED
        Cache::put('device_alert_stats', ['cached' => 1], 300);
        Cache::put('dashboard_telemetry_stats', ['cached' => 1], 300);

        $res1 = $this->patchJson("/api/device-alerts/{$alert1->id}/status", [
            'status' => 'ACKNOWLEDGED',
        ]);
        $res1->assertOk();

        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats MUST be evicted on status -> ACKNOWLEDGED');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats MUST be evicted on status -> ACKNOWLEDGED');

        // Transition 2: Single update ACKNOWLEDGED -> RESOLVED
        Cache::put('device_alert_stats', ['cached' => 2], 300);
        Cache::put('dashboard_telemetry_stats', ['cached' => 2], 300);

        $res2 = $this->patchJson("/api/device-alerts/{$alert1->id}/status", [
            'status' => 'RESOLVED',
        ]);
        $res2->assertOk();

        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats MUST be evicted on status -> RESOLVED');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats MUST be evicted on status -> RESOLVED');

        // Transition 3: Single update RESOLVED -> DISMISSED
        Cache::put('device_alert_stats', ['cached' => 3], 300);
        Cache::put('dashboard_telemetry_stats', ['cached' => 3], 300);

        $res3 = $this->patchJson("/api/device-alerts/{$alert1->id}/status", [
            'status' => 'DISMISSED',
        ]);
        $res3->assertOk();

        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats MUST be evicted on status -> DISMISSED');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats MUST be evicted on status -> DISMISSED');

        // Transition 4: Bulk update status for multiple alerts
        Cache::put('device_alert_stats', ['cached' => 4], 300);
        Cache::put('dashboard_telemetry_stats', ['cached' => 4], 300);

        $bulkRes = $this->postJson('/api/device-alerts/bulk-status', [
            'ids' => [$alert2->id, $alert3->id],
            'status' => 'RESOLVED',
        ]);
        $bulkRes->assertOk();

        $this->assertFalse(Cache::has('device_alert_stats'), 'device_alert_stats MUST be evicted on bulk status update');
        $this->assertFalse(Cache::has('dashboard_telemetry_stats'), 'dashboard_telemetry_stats MUST be evicted on bulk status update');

        $alert2->refresh();
        $alert3->refresh();
        $this->assertEquals('RESOLVED', $alert2->status);
        $this->assertEquals('RESOLVED', $alert3->status);
        $this->assertNotNull($alert2->resolved_at);
        $this->assertNotNull($alert3->resolved_at);
    }

    // =========================================================================
    // Legacy Visitor Regression Suite (Preserved for suite stability)
    // =========================================================================

    /**
     * CHALLENGE M3-1: Visitor Overstay Grace Boundary (14m not flagged, 16m flagged with DeviceAlert).
     */
    public function test_visitor_overstay_grace_boundary_at_14m_and_16m(): void
    {
        $visitor = Visitor::create([
            'first_name' => 'Threshold',
            'last_name' => 'Challenger',
            'company' => 'Boundary Testing Inc',
        ]);

        $visit14m = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'audit',
            'status' => 'checked_in',
            'check_in_time' => Carbon::now()->subHours(2),
            'expected_departure' => Carbon::now()->subMinutes(14),
        ]);

        $visit16m = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'inspection',
            'status' => 'checked_in',
            'check_in_time' => Carbon::now()->subHours(2),
            'expected_departure' => Carbon::now()->subMinutes(16),
        ]);

        dispatch_sync(new DetectOverstayVisitorsJob());

        $visit14m->refresh();
        $visit16m->refresh();

        $this->assertEquals('checked_in', $visit14m->status, 'Visit at now() - 14m must retain checked_in status');
        $this->assertNull($visit14m->overstay_alerted_at, 'Visit at now() - 14m must have null overstay_alerted_at');

        $this->assertEquals('overstayed', $visit16m->status, 'Visit at now() - 16m must transition to overstayed');
        $this->assertNotNull($visit16m->overstay_alerted_at, 'Visit at now() - 16m must have overstay_alerted_at timestamp');

        $this->assertDatabaseHas('device_alerts', [
            'device_id' => $this->device->device_id,
            'alert_type' => 'visitor_overstay',
            'status' => 'NEW',
        ]);

        $alert = DeviceAlert::where('alert_type', 'visitor_overstay')->latest('id')->first();
        $this->assertNotNull($alert);
        $this->assertEquals($visit16m->id, $alert->details['visit_id'] ?? null);
    }

    /**
     * CHALLENGE M3-2: Duplicate Alert Suppression under repeated job runs.
     */
    public function test_duplicate_alert_suppression_on_repeated_overstay_job_execution(): void
    {
        $visitor = Visitor::create([
            'first_name' => 'Repeated',
            'last_name' => 'Runner',
            'company' => 'De-dup Labs',
        ]);

        $visit = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'stress-test',
            'status' => 'checked_in',
            'check_in_time' => Carbon::now()->subHours(3),
            'expected_departure' => Carbon::now()->subMinutes(20),
        ]);

        dispatch_sync(new DetectOverstayVisitorsJob());

        $visit->refresh();
        $this->assertEquals('overstayed', $visit->status);
        $initialAlertedAt = $visit->overstay_alerted_at;
        $this->assertNotNull($initialAlertedAt);

        $initialAlertCount = DeviceAlert::where('alert_type', 'visitor_overstay')
            ->whereJsonContains('details->visit_id', $visit->id)
            ->count();
        $this->assertEquals(1, $initialAlertCount);

        dispatch_sync(new DetectOverstayVisitorsJob());
        dispatch_sync(new DetectOverstayVisitorsJob());

        $afterAlertCount = DeviceAlert::where('alert_type', 'visitor_overstay')
            ->whereJsonContains('details->visit_id', $visit->id)
            ->count();
        $this->assertEquals(1, $afterAlertCount, 'Repeated job executions MUST NOT create duplicate DeviceAlerts');

        $visit->refresh();
        $this->assertEquals(
            $initialAlertedAt->toIso8601String(),
            $visit->overstay_alerted_at->toIso8601String(),
            'overstay_alerted_at timestamp must remain stable and not be overwritten on repeated runs'
        );
    }

    /**
     * CHALLENGE M3-3: Visitor Cancellation Face Revocation for Checked-in and Expected Visits.
     */
    public function test_cancelling_expected_and_checked_in_visits_dispatches_face_revocation(): void
    {
        Queue::fake([SyncPersonnelJob::class]);

        $visitor = Visitor::create([
            'first_name' => 'Revoke',
            'last_name' => 'Target',
            'company' => 'Camera Face Testing',
        ]);

        $visitCheckedIn = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'hardware-access',
            'status' => 'expected',
            'expected_arrival' => Carbon::now(),
        ]);

        $syncService = app(VisitorSyncService::class);
        $syncService->checkIn($visitCheckedIn, ['badge_number' => 'BADGE-REVOKE-01']);

        $visitCheckedIn->refresh();
        $this->assertEquals('checked_in', $visitCheckedIn->status);
        $this->assertNotNull($visitCheckedIn->personnel_id);
        $personnelId = $visitCheckedIn->personnel_id;
        $expectedCustomizeId = 900000 + $visitCheckedIn->id;

        $this->assertDatabaseHas('personnel', [
            'id' => $personnelId,
            'customize_id' => $expectedCustomizeId,
        ]);

        $responseA = $this->postJson("/api/visits/{$visitCheckedIn->id}/cancel", [
            'reason' => 'Security revoked early departure',
        ]);
        $responseA->assertStatus(200);

        $visitCheckedIn->refresh();
        $this->assertEquals('cancelled', $visitCheckedIn->status);
        $this->assertEquals('Security revoked early departure', $visitCheckedIn->cancellation_reason);
        $this->assertEquals($this->adminUser->id, $visitCheckedIn->cancelled_by);
        $this->assertNotNull($visitCheckedIn->cancelled_at);
        $this->assertNull($visitCheckedIn->personnel_id);

        $this->assertDatabaseMissing('personnel', ['id' => $personnelId]);

        Queue::assertPushed(SyncPersonnelJob::class, function ($job) use ($personnelId, $expectedCustomizeId) {
            return $job->action === 'DELETE' &&
                ($job->customizeIdToDelete === $expectedCustomizeId || $job->personnelId === $personnelId);
        });

        $visitExpected = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'pre-registered meeting',
            'status' => 'expected',
            'expected_arrival' => Carbon::tomorrow(),
        ]);
        $expectedCustomizeIdB = 900000 + $visitExpected->id;

        $responseB = $this->postJson("/api/visits/{$visitExpected->id}/cancel", [
            'reason' => 'Host rescheduled meeting',
        ]);
        $responseB->assertStatus(200);

        $visitExpected->refresh();
        $this->assertEquals('cancelled', $visitExpected->status);
        $this->assertEquals('Host rescheduled meeting', $visitExpected->cancellation_reason);

        Queue::assertPushed(SyncPersonnelJob::class, function ($job) use ($expectedCustomizeIdB) {
            return $job->action === 'DELETE' && $job->customizeIdToDelete === $expectedCustomizeIdB;
        });
    }

    /**
     * CHALLENGE M3-4: Cancelling an already-cancelled or checked-out visit must fail (HTTP 422).
     */
    public function test_cancelling_already_cancelled_or_checked_out_visit_fails_with_422(): void
    {
        $visitor = Visitor::create([
            'first_name' => 'Invalid',
            'last_name' => 'Transitions',
        ]);

        $visitCancelled = Visit::create([
            'visitor_id' => $visitor->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Prior cancellation',
        ]);

        $res1 = $this->postJson("/api/visits/{$visitCancelled->id}/cancel", [
            'reason' => 'Attempting double cancellation',
        ]);
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['status']);

        $visitCheckedOut = Visit::create([
            'visitor_id' => $visitor->id,
            'status' => 'checked_out',
            'check_out_time' => Carbon::now()->subHour(),
        ]);

        $res2 = $this->postJson("/api/visits/{$visitCheckedOut->id}/cancel", [
            'reason' => 'Attempting cancel on checked_out',
        ]);
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['status']);

        $visitNoShow = Visit::create([
            'visitor_id' => $visitor->id,
            'status' => 'no_show',
        ]);

        $res3 = $this->postJson("/api/visits/{$visitNoShow->id}/cancel", [
            'reason' => 'Attempting cancel on no_show',
        ]);
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['status']);
    }

    /**
     * CHALLENGE M3-5: Route Precedence: GET /api/visits/overstayed does not hit GET /api/visits/{id}.
     */
    public function test_route_precedence_get_visits_overstayed_does_not_hit_show_binding(): void
    {
        $visitor = Visitor::create([
            'first_name' => 'Route',
            'last_name' => 'Precedence',
        ]);

        $overstayedVisit = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'contractor work',
            'status' => 'overstayed',
            'check_in_time' => Carbon::now()->subHours(4),
            'expected_departure' => Carbon::now()->subMinutes(30),
        ]);

        $overstayedUnflagged = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'vendor visit',
            'status' => 'checked_in',
            'check_in_time' => Carbon::now()->subHours(2),
            'expected_departure' => Carbon::now()->subMinutes(25),
        ]);

        $normalVisit = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'lunch meeting',
            'status' => 'checked_in',
            'check_in_time' => Carbon::now()->subHours(1),
            'expected_departure' => Carbon::now()->subMinutes(10),
        ]);

        $checkedOutVisit = Visit::create([
            'visitor_id' => $visitor->id,
            'device_id' => $this->device->device_id,
            'purpose' => 'interview',
            'status' => 'checked_out',
            'check_in_time' => Carbon::now()->subHours(3),
            'expected_departure' => Carbon::now()->subMinutes(40),
            'check_out_time' => Carbon::now()->subMinutes(35),
        ]);

        $response = $this->getJson('/api/visits/overstayed');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'current_page',
            'data',
            'total',
        ]);

        $returnedIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($overstayedVisit->id, $returnedIds, 'Overstayed visit must be present in /api/visits/overstayed');
        $this->assertContains($overstayedUnflagged->id, $returnedIds, 'Checked-in visit past grace window must be present');
        $this->assertNotContains($normalVisit->id, $returnedIds, 'Normal visit within 15m grace must not be in overstayed roster');
        $this->assertNotContains($checkedOutVisit->id, $returnedIds, 'Checked out visit must not be in overstayed roster');

        $showResponse = $this->getJson("/api/visits/{$overstayedVisit->id}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.id', $overstayedVisit->id);
    }
}
