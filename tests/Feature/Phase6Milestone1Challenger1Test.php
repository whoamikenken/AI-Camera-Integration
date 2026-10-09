<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\AttendancePunch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase6Milestone1Challenger1Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Employee $employee;
    protected Visitor $visitor;
    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Adversarial Challenger Org',
            'code' => 'ADV-CHALLENGE',
            'timezone' => 'Asia/Manila',
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

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Shift',
            'code' => 'STD-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_overnight' => false,
        ]);

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-CHALLENGE-01',
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);

        $this->visitor = Visitor::create([
            'organization_id' => $this->org->id,
            'first_name' => 'John',
            'last_name' => 'Visitor',
            'email' => 'john.visitor@example.com',
        ]);

        $this->device = Device::create([
            'device_id' => 'CAM-TEST-CHALLENGE-01',
            'name' => 'Front Door Unit',
            'ip_address' => '192.168.1.199',
            'device_role' => 'bidirectional',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // 1. SARGability & Query Log Verification
    // =========================================================================

    /**
     * Verify VisitorController::listVisits does not use whereDate() / strftime().
     */
    public function test_visitor_controller_date_query_does_not_issue_where_date_or_strftime(): void
    {
        Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-04 14:00:00',
            'status' => 'expected',
        ]);

        DB::enableQueryLog();

        $response = $this->getJson('/api/visits?date=2026-10-04');
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $visitQueries = array_filter($queries, fn($q) => str_contains($q['query'], 'visits'));
        $this->assertNotEmpty($visitQueries, 'Expected at least one query against visits');

        foreach ($visitQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('strftime', $sql, 'Visitor query must not wrap indexed columns in strftime()');
            $this->assertStringNotContainsString('expected_arrival"::date', $sql, 'Visitor query must not wrap expected_arrival in ::date');
            if (str_contains($sql, 'expected_arrival')) {
                $this->assertStringContainsString('between', $sql, 'Expected SARGable BETWEEN clause for expected_arrival');
            }
        }
    }

    /**
     * Verify AttendanceProcessingService::processPunch last punch check does not use whereDate() / strftime().
     */
    public function test_attendance_processing_service_last_punch_does_not_issue_where_date_or_strftime(): void
    {
        $service = app(AttendanceProcessingService::class);

        DB::enableQueryLog();

        $service->processPunch(
            employee: $this->employee,
            punchTime: Carbon::parse('2026-10-04 12:30:00'),
            device: $this->device,
            source: 'camera_auto',
            direction: null // Force direction inference
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $punchSelectQueries = array_filter($queries, function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'attendance_punches') && str_starts_with($sql, 'select');
        });

        $this->assertNotEmpty($punchSelectQueries, 'Expected select query against attendance_punches');

        foreach ($punchSelectQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('strftime', $sql, 'Attendance punch query must not wrap indexed columns in strftime()');
            $this->assertStringNotContainsString('punch_time"::date', $sql, 'Attendance punch query must not wrap punch_time in ::date');
            if (str_contains($sql, 'punch_time')) {
                $this->assertStringContainsString('between', $sql, 'Expected SARGable BETWEEN clause for punch_time');
            }
        }
    }

    /**
     * Adversarial test: Verify whether AttendanceController::punches uses whereDate() / strftime()
     */
    public function test_attendance_controller_punches_endpoint_sargability(): void
    {
        AttendancePunch::create([
            'employee_id' => $this->employee->id,
            'device_id' => $this->device->device_id,
            'punch_time' => '2026-10-04 09:00:00',
            'direction' => 'in',
            'source' => 'camera_auto',
        ]);

        DB::enableQueryLog();

        $response = $this->getJson('/api/attendance/punches?date=2026-10-04');
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $punchQueries = array_filter($queries, fn($q) => str_contains(strtolower($q['query']), 'attendance_punches'));
        $this->assertNotEmpty($punchQueries);

        foreach ($punchQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('strftime', $sql, 'Attendance punch query must not wrap indexed columns in strftime()');
            $this->assertStringNotContainsString('punch_time"::date', $sql, 'Attendance punch query must not wrap punch_time in ::date');
            if (str_contains($sql, 'punch_time') && !str_contains($sql, 'order by')) {
                $this->assertStringContainsString('between', $sql, 'Expected SARGable BETWEEN clause for punch_time in AttendanceController::punches');
            }
        }
    }

    // =========================================================================
    // 2. Boundary Conditions: Exact startOfDay, endOfDay, microseconds
    // =========================================================================

    /**
     * Test exact startOfDay (00:00:00.000000) and endOfDay (23:59:59.000000 / 23:59:59.999999).
     */
    public function test_visit_date_filter_exact_start_and_end_of_day_boundaries(): void
    {
        $vStart = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-04 00:00:00',
            'status' => 'expected',
        ]);

        $vMid = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-04 12:00:00',
            'status' => 'expected',
        ]);

        $vEndSec = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-04 23:59:59',
            'status' => 'expected',
        ]);

        // Boundary outside: previous day 23:59:59
        $vPrev = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-03 23:59:59',
            'status' => 'expected',
        ]);

        // Boundary outside: next day 00:00:00
        $vNext = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-05 00:00:00',
            'status' => 'expected',
        ]);

        $response = $this->getJson('/api/visits?date=2026-10-04');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($vStart->id, $ids, 'Visit at exact 00:00:00 must be included');
        $this->assertContains($vMid->id, $ids, 'Visit at noon must be included');
        $this->assertContains($vEndSec->id, $ids, 'Visit at exact 23:59:59 must be included');
        $this->assertNotContains($vPrev->id, $ids, 'Visit on previous day 23:59:59 must NOT be included');
        $this->assertNotContains($vNext->id, $ids, 'Visit on next day 00:00:00 must NOT be included');
    }

    /**
     * Test AttendanceProcessingService direction inference across day boundaries.
     */
    public function test_attendance_processing_direction_inference_across_day_boundaries(): void
    {
        $service = app(AttendanceProcessingService::class);

        // Day 1: Punch at 00:00:00 should be 'in'
        $punch1 = $service->processPunch(
            employee: $this->employee,
            punchTime: Carbon::parse('2026-10-04 00:00:00'),
            device: $this->device,
            source: 'camera_auto',
            direction: null
        );
        $this->assertEquals('in', $punch1->direction);

        // Day 1: Subsequent punch at 18:00:00 should be 'out'
        $punch2 = $service->processPunch(
            employee: $this->employee,
            punchTime: Carbon::parse('2026-10-04 18:00:00'),
            device: $this->device,
            source: 'camera_auto',
            direction: null
        );
        $this->assertEquals('out', $punch2->direction);

        // Day 2: First punch at 00:00:00 on next day should be 'in' (NOT 'out', should not see Day 1)
        $punch3 = $service->processPunch(
            employee: $this->employee,
            punchTime: Carbon::parse('2026-10-05 00:00:00'),
            device: $this->device,
            source: 'camera_auto',
            direction: null
        );
        $this->assertEquals('in', $punch3->direction, 'Day 2 first punch at 00:00:00 must infer "in"');
    }

    // =========================================================================
    // 3. Leap Year & Edge Date Boundaries
    // =========================================================================

    /**
     * Test Leap Year: 2024-02-29 and 2028-02-29 queries.
     */
    public function test_leap_year_handling(): void
    {
        // Leap year 2024
        $vLeapStart = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2024-02-29 00:00:00',
            'status' => 'expected',
        ]);

        $vLeapEnd = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2024-02-29 23:59:59',
            'status' => 'expected',
        ]);

        $vNextDay = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2024-03-01 00:00:00',
            'status' => 'expected',
        ]);

        $response = $this->getJson('/api/visits?date=2024-02-29');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($vLeapStart->id, $ids);
        $this->assertContains($vLeapEnd->id, $ids);
        $this->assertNotContains($vNextDay->id, $ids);
    }

    /**
     * Test non-leap year Feb 28 to Mar 1 transition (2026).
     */
    public function test_non_leap_year_transition_boundaries(): void
    {
        $vFeb28 = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-02-28 23:59:59',
            'status' => 'expected',
        ]);

        $vMar1 = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-03-01 00:00:00',
            'status' => 'expected',
        ]);

        $respFeb = $this->getJson('/api/visits?date=2026-02-28');
        $respFeb->assertOk();
        $febIds = collect($respFeb->json('data'))->pluck('id')->all();
        $this->assertContains($vFeb28->id, $febIds);
        $this->assertNotContains($vMar1->id, $febIds);

        $respMar = $this->getJson('/api/visits?date=2026-03-01');
        $respMar->assertOk();
        $marIds = collect($respMar->json('data'))->pluck('id')->all();
        $this->assertContains($vMar1->id, $marIds);
        $this->assertNotContains($vFeb28->id, $marIds);
    }

    // =========================================================================
    // 4. Timezone Handling
    // =========================================================================

    /**
     * Test ISO 8601 strings with timezone offset vs date parameter.
     */
    public function test_iso_date_string_parameter_handling(): void
    {
        $v = Visit::create([
            'visitor_id' => $this->visitor->id,
            'host_employee_id' => $this->employee->id,
            'expected_arrival' => '2026-10-04 15:00:00',
            'status' => 'expected',
        ]);

        // Passing full ISO date string should still parse correctly and match the day
        $resp = $this->getJson('/api/visits?date=2026-10-04T00:00:00');
        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($v->id, $ids);
    }

    // =========================================================================
    // 5. Schema & Index Verification
    // =========================================================================

    /**
     * Test SQLite / DB indexes for Phase 6 Task 6.2 exist.
     */
    public function test_telemetry_and_punch_performance_indexes_exist(): void
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
        }
    }

    /**
     * Test migration rollback down() and up() reapply.
     */
    public function test_migration_down_and_up_lifecycle(): void
    {
        $migration = require database_path('migrations/2026_10_07_000001_add_telemetry_and_punch_performance_indexes.php');

        // Roll down
        $migration->down();

        if (DB::getDriverName() === 'sqlite') {
            $punchIndexes = collect(DB::select("PRAGMA index_list('attendance_punches')"))->pluck('name')->all();
            $this->assertNotContains('idx_attendance_punches_device_id', $punchIndexes);
        }

        // Reapply up
        $migration->up();

        if (DB::getDriverName() === 'sqlite') {
            $punchIndexes = collect(DB::select("PRAGMA index_list('attendance_punches')"))->pluck('name')->all();
            $this->assertContains('idx_attendance_punches_device_id', $punchIndexes);
        }
    }
}
