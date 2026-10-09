<?php

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use App\Services\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class Phase6Milestone3Challenger1Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Shift $shiftA;
    protected Shift $shiftB;
    protected AttendanceProcessingService $attendanceService;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Adversarial Challenger Org 1',
            'code' => 'ADV-CHAL-1',
            'timezone' => 'Asia/Manila',
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

        $this->shiftA = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Day Shift A',
            'code' => 'DAY-A',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        $this->shiftB = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Night Shift B',
            'code' => 'NIGHT-B',
            'shift_start' => '20:00:00',
            'shift_end' => '05:00:00',
            'is_active' => true,
        ]);

        $this->attendanceService = app(AttendanceProcessingService::class);
    }

    /**
     * Helper to instantiate a testable MqttListenCommand exposing protected/internal methods.
     */
    protected function createTestableMqttCommand(): MqttListenCommand
    {
        return new class extends MqttListenCommand {
            public function testCheckDevice(?string $deviceId): bool
            {
                return $this->isDeviceRegisteredAndActive($deviceId);
            }

            public function testHandleMessage(string $topic, string $rawMessage, $mqtt, $storageService): void
            {
                $this->handleMessage($topic, $rawMessage, $mqtt, $storageService);
            }
        };
    }

    // =========================================================================
    // TASK 6.8: Shift Cache Invalidation Without Redis KEYS
    // =========================================================================

    /**
     * CHALLENGE 6.8-A: Bulk shift assignment to 50 employees must:
     * 1. Warm cache on multiple dates for all 50 employees.
     * 2. Execute bulk assignment via API.
     * 3. Increment version counter for each employee.
     * 4. Evict stale cache keys so they cannot be retrieved.
     * 5. Make zero calls to Redis keys() / ->keys().
     * 6. Return new shift upon next resolution under new versioned key.
     */
    public function test_challenge_6_8_bulk_shift_assign_to_50_employees_increments_version_evicts_stale_and_uses_zero_redis_keys(): void
    {
        // 1. Create 50 Employees assigned to Shift A
        $employees = [];
        $employeeIds = [];
        for ($i = 1; $i <= 50; $i++) {
            $code = sprintf('EMP-P6-%03d', $i);
            $emp = Employee::create([
                'organization_id' => $this->org->id,
                'shift_id' => $this->shiftA->id,
                'employee_code' => $code,
                'first_name' => "Worker{$i}",
                'last_name' => 'BulkTest',
                'employment_status' => 'active',
            ]);
            $employees[] = $emp;
            $employeeIds[] = $emp->id;
        }

        $date1 = '2026-11-01';
        $date2 = '2026-11-02';

        // 2. Warm cache for all 50 employees on 2 distinct dates
        foreach ($employees as $emp) {
            $res1 = $this->attendanceService->resolveEffectiveShift($emp, $date1);
            $res2 = $this->attendanceService->resolveEffectiveShift($emp, $date2);

            $this->assertEquals($this->shiftA->id, $res1->id);
            $this->assertEquals($this->shiftA->id, $res2->id);

            // Assert baseline version 0 cache keys exist
            $this->assertTrue(Cache::has("emp_shift:{$emp->id}:{$date1}"), "Warmed cache key for emp {$emp->id} date 1 must exist");
            $this->assertTrue(Cache::has("emp_shift:{$emp->id}:{$date2}"), "Warmed cache key for emp {$emp->id} date 2 must exist");
            $this->assertEquals(0, (int) Cache::get("emp_shift_v:{$emp->id}", 0), "Initial version counter must be 0");
        }

        // 3. Spy on Redis facade to assert no keys() wildcard scan is called
        Redis::spy();

        // 4. Perform bulk shift assignment to Shift B for all 50 employees
        $response = $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $this->shiftB->id,
            'employee_ids' => $employeeIds,
            'effective_from' => $date1,
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(50, $response->json('count'));

        // 5. Assert that Redis::keys() was NOT called
        Redis::shouldNotHaveReceived('keys');

        // Static AST check: ensure ShiftController and AttendanceProcessingService do not call ->keys()
        $shiftControllerSource = file_get_contents(app_path('Http/Controllers/ShiftController.php'));
        $this->assertStringNotContainsString('->keys(', $shiftControllerSource, 'ShiftController must not call ->keys()');
        $this->assertStringNotContainsString('Redis::keys', $shiftControllerSource, 'ShiftController must not call Redis::keys');

        $attendanceServiceSource = file_get_contents(app_path('Services/AttendanceProcessingService.php'));
        $this->assertStringNotContainsString('Redis::keys', $attendanceServiceSource, 'AttendanceProcessingService must not call Redis::keys');

        // 6. Verify for all 50 employees: version incremented, stale keys evicted, new resolution returns Shift B
        foreach ($employees as $emp) {
            // Version counter must be incremented to >= 1
            $version = (int) Cache::get("emp_shift_v:{$emp->id}", 0);
            $this->assertGreaterThanOrEqual(1, $version, "Version counter for employee {$emp->id} must increment to >= 1");

            // Stale unversioned cache keys MUST NOT be retrievable
            $this->assertFalse(Cache::has("emp_shift:{$emp->id}:{$date1}"), "Stale cache key for date1 must be evicted for employee {$emp->id}");
            $this->assertFalse(Cache::has("emp_shift:{$emp->id}:{$date2}"), "Stale cache key for date2 must be evicted for employee {$emp->id}");

            // Fresh resolution returns Shift B
            $newRes1 = $this->attendanceService->resolveEffectiveShift($emp, $date1);
            $this->assertNotNull($newRes1);
            $this->assertEquals($this->shiftB->id, $newRes1->id, "Resolved shift after invalidation must be Shift B for emp {$emp->id}");

            // Fresh versioned key is stored under v{$version}
            $this->assertTrue(
                Cache::has("emp_shift:{$emp->id}:v{$version}:{$date1}"),
                "New versioned key emp_shift:{$emp->id}:v{$version}:{$date1} must be present in cache"
            );
        }
    }

    /**
     * CHALLENGE 6.8-B: Edge Case - Employee with no prior shift assignments.
     * When an employee has null default shift and no prior shift assignments,
     * assigning a shift must cleanly increment version counter and evict default fallback cache.
     */
    public function test_challenge_6_8_edge_case_employee_with_no_prior_shift_assignment(): void
    {
        // Employee with no default shift
        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => null,
            'employee_code' => 'EMP-NO-PRIOR-SHIFT',
            'first_name' => 'Unassigned',
            'last_name' => 'Worker',
            'employment_status' => 'active',
        ]);

        $this->assertEquals(0, EmployeeShiftAssignment::where('employee_id', $emp->id)->count());

        $testDate = '2026-11-10';

        // 1. Initial resolution falls back to default first shift
        $initialShift = $this->attendanceService->resolveEffectiveShift($emp, $testDate);
        $this->assertNotNull($initialShift);
        $this->assertEquals(0, (int) Cache::get("emp_shift_v:{$emp->id}", 0));
        $this->assertTrue(Cache::has("emp_shift:{$emp->id}:{$testDate}"));

        // 2. Assign Shift B specifically to this employee via bulk assign
        $response = $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $this->shiftB->id,
            'employee_ids' => [$emp->id],
            'effective_from' => $testDate,
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);
        $response->assertStatus(201);

        // 3. Version counter must increment
        $version = (int) Cache::get("emp_shift_v:{$emp->id}", 0);
        $this->assertGreaterThanOrEqual(1, $version, 'Version counter must increment from 0 to >= 1');

        // 4. Stale cache key must be evicted
        $this->assertFalse(Cache::has("emp_shift:{$emp->id}:{$testDate}"), 'Stale fallback shift cache key must be evicted');

        // 5. Subsequent resolution must return Shift B
        $newShift = $this->attendanceService->resolveEffectiveShift($emp, $testDate);
        $this->assertEquals($this->shiftB->id, $newShift->id);
        $this->assertTrue(Cache::has("emp_shift:{$emp->id}:v{$version}:{$testDate}"));
    }

    /**
     * CHALLENGE 6.8-C (ADVERSARIAL EDGE CASE): Eloquent 'date' cast format vs string comparison boundary.
     * When an assignment is created via Eloquent create() (e.g. EmployeeController::assignShift),
     * Eloquent writes 'YYYY-MM-DD 00:00:00' to SQLite.
     * In SQLite string comparison, '2026-11-10 00:00:00' <= '2026-11-10' evaluates to FALSE.
     * As a result, resolveEffectiveShift on the exact start date falls back to the default shift,
     * but succeeds on day 2 ('2026-11-11').
     */
    public function test_challenge_6_8_adversarial_eloquent_date_cast_truncation_boundary_condition(): void
    {
        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $this->shiftA->id,
            'employee_code' => 'EMP-DATE-BOUNDARY',
            'first_name' => 'Boundary',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);

        $startDate = '2026-11-10';

        // Assign via EmployeeController::assignShift (which uses Eloquent create)
        $response = $this->postJson("/api/employees/{$emp->id}/assign-shift", [
            'shift_id' => $this->shiftB->id,
            'effective_from' => $startDate,
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);
        $response->assertStatus(201);

        // Verify the raw record in database has time component attached by Eloquent
        $rawRecord = DB::table('employee_shift_assignments')->where('employee_id', $emp->id)->first();
        $this->assertEquals('2026-11-10 00:00:00', $rawRecord->effective_from);

        // On the day after (2026-11-11), string comparison '2026-11-10 00:00:00' <= '2026-11-11' succeeds
        $nextDayShift = $this->attendanceService->resolveEffectiveShift($emp, '2026-11-11');
        $this->assertEquals($this->shiftB->id, $nextDayShift->id);
    }

    /**
     * CHALLENGE 6.8-D: Edge Case - Multiple rapid successive reassignments.
     * Rapid back-to-back reassignments must monotonically increment the version counter
     * without race conditions, and each resolution must reflect the latest assigned shift.
     */
    public function test_challenge_6_8_edge_case_multiple_rapid_successive_shift_reassignments(): void
    {
        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $this->shiftA->id,
            'employee_code' => 'EMP-RAPID-REASSIGN',
            'first_name' => 'Rapid',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);

        $shifts = [
            $this->shiftA,
            $this->shiftB,
            Shift::create([
                'organization_id' => $this->org->id,
                'name' => 'Shift C - Midday',
                'code' => 'MID-C',
                'shift_start' => '12:00:00',
                'shift_end' => '21:00:00',
                'is_active' => true,
            ]),
            Shift::create([
                'organization_id' => $this->org->id,
                'name' => 'Shift D - Twilight',
                'code' => 'TWI-D',
                'shift_start' => '16:00:00',
                'shift_end' => '00:00:00',
                'is_active' => true,
            ]),
            Shift::create([
                'organization_id' => $this->org->id,
                'name' => 'Shift E - Dawn',
                'code' => 'DAWN-E',
                'shift_start' => '04:00:00',
                'shift_end' => '13:00:00',
                'is_active' => true,
            ]),
        ];

        $targetDate = '2026-11-15';
        $previousVersion = 0;

        // Perform 4 rapid successive reassignments: S0 -> S1 -> S2 -> S3 -> S4
        for ($step = 1; $step < count($shifts); $step++) {
            $nextShift = $shifts[$step];

            // Reassign
            $response = $this->postJson('/api/shifts/bulk-assign', [
                'shift_id' => $nextShift->id,
                'employee_ids' => [$emp->id],
                'effective_from' => $targetDate,
                'effective_to' => null,
            ]);
            $response->assertStatus(201);

            $currentVersion = (int) Cache::get("emp_shift_v:{$emp->id}", 0);
            $this->assertGreaterThan($previousVersion, $currentVersion, "Version must strictly increase at step {$step}");

            // Verify resolution immediately returns the newest shift
            $resolved = $this->attendanceService->resolveEffectiveShift($emp, $targetDate);
            $this->assertEquals($nextShift->id, $resolved->id, "Resolved shift must match assigned shift at step {$step}");

            // Verify new versioned cache key exists and previous version key is absent
            $this->assertTrue(Cache::has("emp_shift:{$emp->id}:v{$currentVersion}:{$targetDate}"));
            if ($previousVersion > 0) {
                $this->assertFalse(Cache::has("emp_shift:{$emp->id}:v{$previousVersion}:{$targetDate}"));
            }

            $previousVersion = $currentVersion;
        }
    }

    // =========================================================================
    // TASK 6.9: Device Registration Cache in MqttListenCommand
    // =========================================================================

    /**
     * CHALLENGE 6.9-A: Rapid simulated telemetry bursts (100 packets) for active device:
     * 1. First packet queries DB and stages registration in cache.
     * 2. Packets 2..100 hit cache and execute ZERO queries to devices table.
     */
    public function test_challenge_6_9_device_registration_cache_rapid_telemetry_burst_drops_queries_to_zero(): void
    {
        $deviceId = 'CAM-BURST-ACTIVE-01';

        $device = Device::create([
            'device_id' => $deviceId,
            'name' => 'Active Burst Camera',
            'ip_address' => '192.168.1.180',
            'is_active' => true,
        ]);

        // Explicitly clear cache keys to test cold-start resolution in MqttListenCommand
        Cache::forget("device_registered:{$deviceId}");
        Cache::forget("device_hb_throttle:{$deviceId}");

        $command = $this->createTestableMqttCommand();

        // 1. Initial cold-start packet: queries DB and populates cache
        DB::flushQueryLog();
        DB::enableQueryLog();

        $isActiveCold = $command->testCheckDevice($deviceId);
        $this->assertTrue($isActiveCold);
        $this->assertTrue((bool) Cache::get("device_registered:{$deviceId}"));

        $coldQueries = array_filter(DB::getQueryLog(), fn($q) => str_contains(strtolower($q['query']), 'devices'));
        $this->assertNotEmpty($coldQueries, 'Initial lookup must query devices table to establish cache');

        // 2. Rapid simulated burst: 100 consecutive checks
        DB::flushQueryLog();

        for ($i = 1; $i <= 100; $i++) {
            $isActiveBurst = $command->testCheckDevice($deviceId);
            $this->assertTrue($isActiveBurst);
        }

        $burstQueries = array_filter(DB::getQueryLog(), fn($q) => str_contains(strtolower($q['query']), 'devices'));
        $this->assertCount(
            0,
            $burstQueries,
            'Subsequent 100 packets must result in 0 queries to devices table. Found: ' . count($burstQueries)
        );
    }

    /**
     * CHALLENGE 6.9-B: Negative Caching - Simulated telemetry for non-existent device:
     * 1. Cold packet stages inactive device in DB and caches `false` under device_registered:{$id}.
     * 2. Rapid burst of 100 packets for the unknown device executes ZERO queries to devices table.
     * 3. Database retains exactly 1 staged inactive record without duplicate inserts.
     */
    public function test_challenge_6_9_negative_caching_for_non_existent_device_stages_inactive_and_zero_repeated_queries(): void
    {
        $rogueDeviceId = 'CAM-ROGUE-UNENROLLED-999';

        $this->assertDatabaseMissing('devices', ['device_id' => $rogueDeviceId]);
        Cache::forget("device_registered:{$rogueDeviceId}");

        $command = $this->createTestableMqttCommand();

        // 1. Cold-start rogue packet: stages device in DB with is_active = false
        DB::flushQueryLog();
        DB::enableQueryLog();

        $isActiveCold = $command->testCheckDevice($rogueDeviceId);
        $this->assertFalse($isActiveCold, 'Unenrolled rogue device must evaluate to false');

        // Verify device was staged into DB with is_active = false
        $this->assertDatabaseHas('devices', [
            'device_id' => $rogueDeviceId,
            'is_active' => false,
        ]);

        // Verify negative cache entry is present and false
        $cachedValue = Cache::get("device_registered:{$rogueDeviceId}");
        $this->assertNotNull($cachedValue);
        $this->assertFalse((bool) $cachedValue, 'device_registered cache must store negative cache (false)');

        // 2. Rapid burst of 100 packets from rogue device
        DB::flushQueryLog();

        for ($i = 1; $i <= 100; $i++) {
            $isActiveBurst = $command->testCheckDevice($rogueDeviceId);
            $this->assertFalse($isActiveBurst);
        }

        $burstQueries = array_filter(DB::getQueryLog(), fn($q) => str_contains(strtolower($q['query']), 'devices'));
        $this->assertCount(
            0,
            $burstQueries,
            'Negative cache must reject 100 rogue packets with ZERO queries to devices table. Found: ' . count($burstQueries)
        );

        // Assert exactly 1 record exists in devices table (no duplicate inserts during burst)
        $this->assertEquals(1, Device::where('device_id', $rogueDeviceId)->count());
    }

    /**
     * CHALLENGE 6.9-C: Cache invalidation when device is updated or deleted via DeviceObserver:
     * 1. Active device cached as true.
     * 2. Deactivation via model update immediately invalidates/updates cache to false.
     * 3. Reactivation immediately updates cache to true.
     * 4. Device deletion removes cache key entirely.
     */
    public function test_challenge_6_9_cache_invalidation_on_device_mutation_and_deletion_via_device_observer(): void
    {
        $deviceId = 'CAM-OBSERVER-MUTATE-01';

        $device = Device::create([
            'device_id' => $deviceId,
            'name' => 'Observer Mutate Camera',
            'ip_address' => '192.168.1.185',
            'is_active' => true,
        ]);

        $command = $this->createTestableMqttCommand();

        // 1. Active: cached as true
        $this->assertTrue($command->testCheckDevice($deviceId));
        $this->assertTrue((bool) Cache::get("device_registered:{$deviceId}"));

        // 2. Deactivate device via Eloquent update: DeviceObserver::saved fires
        $device->update(['is_active' => false]);

        $this->assertFalse(
            (bool) Cache::get("device_registered:{$deviceId}"),
            'DeviceObserver must immediately update cache key to false upon deactivation'
        );
        $this->assertFalse($command->testCheckDevice($deviceId), 'Command check must evaluate to false after deactivation');

        // 3. Reactivate device
        $device->update(['is_active' => true]);

        $this->assertTrue(
            (bool) Cache::get("device_registered:{$deviceId}"),
            'DeviceObserver must immediately update cache key to true upon reactivation'
        );
        $this->assertTrue($command->testCheckDevice($deviceId), 'Command check must evaluate to true after reactivation');

        // 4. Device renaming: DeviceObserver cleans up old key and writes new key
        $newDeviceId = 'CAM-OBSERVER-RENAMED-01';
        $device->update(['device_id' => $newDeviceId]);

        $this->assertFalse(
            Cache::has("device_registered:{$deviceId}"),
            'Old device ID cache key must be forgotten on rename'
        );
        $this->assertTrue(
            (bool) Cache::get("device_registered:{$newDeviceId}"),
            'New device ID cache key must be staged on rename'
        );

        // 5. Deletion: DeviceObserver::deleted fires and forgets key
        $device->delete();

        $this->assertFalse(
            Cache::has("device_registered:{$newDeviceId}"),
            'DeviceObserver::deleted must forget cache key upon device deletion'
        );
    }

    /**
     * CHALLENGE 6.9-D: Edge cases - Null, empty string, and whitespace-padded device IDs:
     * Robust handling of null and empty strings without exceptions or database queries,
     * and whitespace trimming for enrolled devices.
     */
    public function test_challenge_6_9_edge_cases_null_empty_and_whitespace_padded_device_ids(): void
    {
        $command = $this->createTestableMqttCommand();

        DB::flushQueryLog();
        DB::enableQueryLog();

        // 1. Null device ID: immediately returns false with 0 queries
        $this->assertFalse($command->testCheckDevice(null));

        // 2. Empty string device ID: immediately returns false with 0 queries
        $this->assertFalse($command->testCheckDevice(''));

        $queries = array_filter(DB::getQueryLog(), fn($q) => str_contains(strtolower($q['query']), 'devices'));
        $this->assertCount(0, $queries, 'Null and empty device IDs must return false with zero database queries');

        // 3. Whitespace-padded valid device ID trims correctly
        $paddedDevice = Device::create([
            'device_id' => 'CAM-TRIMMED-01',
            'name' => 'Trimmed Camera',
            'ip_address' => '192.168.1.190',
            'is_active' => true,
        ]);

        $this->assertTrue($command->testCheckDevice('  CAM-TRIMMED-01  '));
        $this->assertTrue((bool) Cache::get('device_registered:CAM-TRIMMED-01'));
    }

    /**
     * CHALLENGE 6.9-E (ADVERSARIAL VULNERABILITY): Whitespace-only device ID bypasses truthy check
     * and executes SQL queries creating an invalid empty-string device record in database.
     */
    public function test_challenge_6_9_adversarial_vulnerability_whitespace_only_device_id_creates_empty_device_record(): void
    {
        $command = $this->createTestableMqttCommand();

        Cache::forget('device_registered:');
        Device::where('device_id', '')->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Whitespace string is truthy in PHP ("   " != false), so if (!$deviceId) check fails
        $result = $command->testCheckDevice('   ');

        // It returns false
        $this->assertFalse($result);

        // But it executed 2 queries against the devices table
        $queries = array_filter(DB::getQueryLog(), fn($q) => str_contains(strtolower($q['query']), 'devices'));
        $this->assertGreaterThanOrEqual(
            1,
            count($queries),
            'VULNERABILITY: Whitespace string was not rejected prior to SQL query'
        );

        // And it staged an invalid empty-string device in the database!
        $this->assertDatabaseHas('devices', [
            'device_id' => '',
            'name' => 'Camera ',
            'is_active' => false,
        ]);
    }
}
