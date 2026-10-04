<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialShiftAndHolidayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $disableAutoAuth = true;

    protected Organization $org;
    protected Organization $otherOrg;
    protected User $adminUser;
    protected User $employeeUser;
    protected User $hrUser;
    protected Department $deptEngineering;
    protected Department $deptSales;
    protected Location $locMain;
    protected Location $locBranch;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed standard roles and permissions catalog
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Apex Dynamics Corp.',
            'code' => 'APEX-HQ',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->otherOrg = Organization::create([
            'name' => 'Foreign Subsidiary Inc.',
            'code' => 'FOREIGN-01',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $this->deptEngineering = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $this->deptSales = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Sales',
            'code' => 'SALES',
        ]);

        $this->locMain = Location::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Campus',
            'code' => 'LOC-MAIN',
        ]);

        $this->locBranch = Location::create([
            'organization_id' => $this->org->id,
            'name' => 'Branch Office',
            'code' => 'LOC-BRANCH',
        ]);

        // 1. Admin User with super-admin role
        $superAdminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$superAdminRole->id]);

        // 2. HR User with hr-manager role (has schedules.manage, shifts.manage)
        $hrRole = Role::where('slug', 'hr-manager')->firstOrFail();
        $this->hrUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->hrUser->roles()->sync([$hrRole->id]);

        // 3. Unprivileged User with employee role (strictly lacks schedules.manage, shifts.manage)
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $this->employeeUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->employeeUser->roles()->sync([$employeeRole->id]);
    }

    private function createTestEmployee(array $attributes = []): Employee
    {
        $personnel = Personnel::create([
            'customize_id' => rand(100000, 999999),
            'name' => 'Test Subject ' . rand(10, 99),
            'person_type' => 0,
        ]);

        return Employee::create(array_merge([
            'organization_id' => $this->org->id,
            'personnel_id' => $personnel->id,
            'department_id' => $this->deptEngineering->id,
            'location_id' => $this->locMain->id,
            'employee_code' => 'EMP-' . rand(1000, 9999),
            'first_name' => 'Test',
            'last_name' => 'Subject',
            'work_email' => 'emp_' . rand(1000, 9999) . '@apexdynamics.com',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'date_of_joining' => '2026-01-01',
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Area 1: Overnight Shift Calculation & Break Deduction Stress Tests
    |--------------------------------------------------------------------------
    */

    public function test_overnight_shift_duration_crossing_midnight_exact_boundary(): void
    {
        // 22:00:00 to 07:00:00 is 9 hours = 540 minutes
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Graveyard Shift',
            'code' => 'GRAVE-01',
            'shift_start' => '22:00:00',
            'shift_end' => '07:00:00',
            'is_overnight' => true,
            'break_duration_minutes' => 60,
        ]);

        $this->assertTrue($shift->isOvernight(), 'Shift should report isOvernight as true.');
        $this->assertTrue($shift->crossesMidnight(), 'Shift should report crossesMidnight as true.');
        $this->assertFalse($shift->isDayShift(), 'Overnight shift must not be classified as day shift.');

        // Gross duration: 22:00 to 07:00 = 9 hours = 540 minutes
        $this->assertSame(540, $shift->durationMinutes(false), 'Gross duration across midnight must be 540 minutes.');

        // Net duration: 540 - 60 break = 480 minutes (8 hours)
        $this->assertSame(480, $shift->durationMinutes(true), 'Net duration deducting break must be 480 minutes.');
    }

    public function test_overnight_shift_spanning_fractional_late_night_to_morning(): void
    {
        // 23:45:00 to 08:15:00 is 8 hours and 30 minutes = 510 minutes
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Late Midnight Staggered',
            'code' => 'NIGHT-STAGGER',
            'shift_start' => '23:45:00',
            'shift_end' => '08:15:00',
            'is_overnight' => true,
            'break_duration_minutes' => 45,
        ]);

        $this->assertSame(510, $shift->durationMinutes(false), 'Gross duration must correctly calculate 510 minutes.');
        $this->assertSame(465, $shift->durationMinutes(true), 'Net duration must correctly calculate 510 - 45 = 465 minutes.');
    }

    public function test_shift_crosses_midnight_auto_detected_when_flag_is_false(): void
    {
        // Shift 20:30 to 04:30 with is_overnight set to false
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Implicit Midnight Shift',
            'code' => 'IMPLICIT-NIGHT',
            'shift_start' => '20:30:00',
            'shift_end' => '04:30:00',
            'is_overnight' => false,
            'break_duration_minutes' => 30,
        ]);

        // Auto-detection via end < start in crossesMidnight()
        $this->assertTrue($shift->crossesMidnight(), 'Shift ending before start time must auto-detect midnight crossing.');
        $this->assertTrue($shift->isOvernight(), 'isOvernight() should evaluate to true when crossesMidnight() is true.');

        // 20:30 to 04:30 = 8 hours = 480 minutes gross
        $this->assertSame(480, $shift->durationMinutes(false), 'Gross duration must handle cross-midnight even if is_overnight flag was false.');
        $this->assertSame(450, $shift->durationMinutes(true), 'Net duration must be 480 - 30 = 450 minutes.');
    }

    public function test_break_duration_deduction_never_returns_negative_values(): void
    {
        // Part-time 4-hour shift: 13:00 to 17:00 = 240 minutes
        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Short Afternoon Shift',
            'code' => 'SHORT-AFT',
            'shift_start' => '13:00:00',
            'shift_end' => '17:00:00',
            'is_overnight' => false,
            'break_duration_minutes' => 300, // Break exceeds duration by 60 mins
        ]);

        $this->assertSame(240, $shift->durationMinutes(false));
        $this->assertSame(0, $shift->durationMinutes(true), 'Break deduction exceeding total duration must clamp to 0, never negative.');
    }

    public function test_flexible_shift_duration_in_memory_with_null_times(): void
    {
        // Flexible shift instantiated in memory without start/end times
        $flexShift = new Shift([
            'is_flexible' => true,
            'min_hours_full_day' => 7.5,
            'break_duration_minutes' => 50,
        ]);

        $this->assertSame(450, $flexShift->durationMinutes(false));
        $this->assertSame(400, $flexShift->durationMinutes(true));

        $flexShift->break_duration_minutes = 600;
        $this->assertSame(0, $flexShift->durationMinutes(true));
    }

    /**
     * PROBE DEFECT 3:
     * When a flexible shift is persisted in the database, the schema default (or API required)
     * shift_start and shift_end times override min_hours_full_day because durationMinutes()
     * checks: if ($this->is_flexible && (!$this->shift_start || !$this->shift_end)).
     * Because shift_start is never null in DB, durationMinutes() computes start-to-end difference.
     */
    public function test_adversarial_defect_flexible_shift_persisted_in_db_ignores_min_hours_full_day(): void
    {
        $persistedFlex = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Persisted Flex Shift',
            'code' => 'FLEX-PERSIST',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_flexible' => true,
            'min_hours_full_day' => 6.0, // Expected 360 minutes
            'break_duration_minutes' => 60,
        ]);

        // Specification expects 6.0 * 60 = 360 minutes.
        // Implementation defect returns 540 minutes (difference between 09:00 and 18:00).
        $this->assertSame(
            360,
            $persistedFlex->durationMinutes(false),
            'DEFECT: Persisted flexible shift durationMinutes() ignores min_hours_full_day because shift_start is non-null.'
        );
    }

    public function test_shift_api_rejects_identical_start_and_end_times_for_non_overnight(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Non-overnight with identical start and end times must be rejected
        $response = $this->postJson('/api/shifts', [
            'organization_id' => $this->org->id,
            'name' => 'Bogus Zero Shift',
            'code' => 'BOGUS-01',
            'shift_start' => '09:00',
            'shift_end' => '09:00',
            'is_overnight' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shift_end']);

        // Overnight with identical start and end times represents a 24-hour shift and should be accepted
        $response24h = $this->postJson('/api/shifts', [
            'organization_id' => $this->org->id,
            'name' => '24-Hour Continuous Guard',
            'code' => 'GUARD-24H',
            'shift_start' => '08:00',
            'shift_end' => '08:00',
            'is_overnight' => true,
        ]);

        $response24h->assertStatus(201);
        $shiftId = $response24h->json('data.id');
        $shift = Shift::find($shiftId);
        $this->assertSame(1440, $shift->durationMinutes(false), '24-hour overnight shift must equal 1440 minutes.');
    }

    public function test_shift_api_rejects_invalid_time_formats(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Hour > 23
        $resp1 = $this->postJson('/api/shifts', [
            'name' => 'Invalid Hour',
            'code' => 'INV-01',
            'shift_start' => '25:00',
            'shift_end' => '17:00',
        ]);
        $resp1->assertStatus(422)->assertJsonValidationErrors(['shift_start']);

        // Minute > 59
        $resp2 = $this->postJson('/api/shifts', [
            'name' => 'Invalid Minute',
            'code' => 'INV-02',
            'shift_start' => '09:00',
            'shift_end' => '17:60',
        ]);
        $resp2->assertStatus(422)->assertJsonValidationErrors(['shift_end']);

        // Malformed non-numeric string
        $resp3 = $this->postJson('/api/shifts', [
            'name' => 'Garbage Time',
            'code' => 'INV-03',
            'shift_start' => 'morning',
            'shift_end' => 'evening',
        ]);
        $resp3->assertStatus(422)->assertJsonValidationErrors(['shift_start', 'shift_end']);
    }

    /*
    |--------------------------------------------------------------------------
    | Area 2: Shift Assignment Timeline Overlaps, Rotation & Days of Week
    |--------------------------------------------------------------------------
    */

    public function test_shift_assignment_rotation_caps_preceding_open_ended_assignment(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $employee = $this->createTestEmployee();

        $shiftA = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Shift Morning A',
            'code' => 'SHIFT-MORN-A',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);

        $shiftB = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Shift Evening B',
            'code' => 'SHIFT-EVE-B',
            'shift_start' => '16:00:00',
            'shift_end' => '01:00:00',
            'is_overnight' => true,
        ]);

        // Step 1: Assign Shift A effective from 2026-01-01 open-ended (effective_to = null)
        $this->postJson("/api/shifts/{$shiftA->id}/assign", [
            'employee_ids' => [$employee->id],
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ])->assertStatus(201);

        $assignmentA = EmployeeShiftAssignment::where('employee_id', $employee->id)
            ->where('shift_id', $shiftA->id)
            ->firstOrFail();
        $this->assertNull($assignmentA->effective_to);

        // Step 2: Assign Shift B starting 2026-06-01
        $this->postJson("/api/shifts/{$shiftB->id}/assign", [
            'employee_ids' => [$employee->id],
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ])->assertStatus(201);

        // Prior assignment A must now be capped at 2026-05-31
        $assignmentA->refresh();
        $this->assertSame('2026-05-31', $assignmentA->effective_to->toDateString(), 'Preceding assignment must be capped at effective_from - 1 day.');
    }

    /**
     * PROBE DEFECT 1:
     * Shift resolution on the exact effective_from start date fails because
     * Employee::currentShift() queries: where('effective_from', '<=', $dateStr).
     * Because effective_from is stored with timestamp ('2026-06-01 00:00:00') in SQLite,
     * the string comparison '2026-06-01 00:00:00' <= '2026-06-01' evaluates to false!
     */
    public function test_adversarial_defect_shift_resolution_fails_on_exact_effective_from_date(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $employee = $this->createTestEmployee();

        $shiftA = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Base Shift A',
            'code' => 'BASE-A',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);

        $shiftB = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Promoted Shift B',
            'code' => 'PROM-B',
            'shift_start' => '16:00:00',
            'shift_end' => '01:00:00',
            'is_overnight' => true,
        ]);

        // Assign Shift A from Jan 1
        $this->postJson("/api/shifts/{$shiftA->id}/assign", [
            'employee_ids' => [$employee->id],
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ])->assertStatus(201);

        // Assign Shift B from June 1 (Monday)
        $this->postJson("/api/shifts/{$shiftB->id}/assign", [
            'employee_ids' => [$employee->id],
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ])->assertStatus(201);

        // On 2026-06-01 (exact start date of Shift B), currentShift() must resolve to Shift B.
        // In current implementation, this fails because where('effective_from', '<=', '2026-06-01') misses '2026-06-01 00:00:00'.
        $resolvedShift = $employee->currentShift('2026-06-01');
        $this->assertSame(
            $shiftB->id,
            $resolvedShift?->id,
            'DEFECT: currentShift() fails on exact effective_from date because of missing whereDate() comparison.'
        );
    }

    public function test_shift_assignment_precedence_on_nested_overlapping_date_ranges(): void
    {
        $employee = $this->createTestEmployee();

        $annualShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual Regular Shift',
            'code' => 'ANNUAL-REG',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $summerPeakShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Summer Peak Shift',
            'code' => 'SUMMER-PEAK',
            'shift_start' => '07:00:00',
            'shift_end' => '16:00:00',
        ]);

        // Assignment 1: 2026-01-01 to 2026-12-31
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $annualShift->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        // Assignment 2: 2026-06-01 to 2026-08-31 (Summer overlay)
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $summerPeakShift->id,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-08-31',
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        // In May (before summer overlay): Annual shift
        $this->assertSame($annualShift->id, $employee->currentShift('2026-05-15')?->id);

        // In July (during summer overlay): Summer peak shift takes precedence
        $this->assertSame($summerPeakShift->id, $employee->currentShift('2026-07-15')?->id);

        // In September (after summer overlay expired): Falls back to Annual shift which runs until end of year
        $this->assertSame($annualShift->id, $employee->currentShift('2026-09-15')?->id);
    }

    public function test_assigned_days_of_week_filtering_numeric_iso_codes(): void
    {
        $employee = $this->createTestEmployee();

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekday Standard',
            'code' => 'WEEKDAY-STD',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        // Assign Monday through Friday [1, 2, 3, 4, 5]
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        // 2026-10-05 is Monday (ISO 1)
        $this->assertFalse($employee->isRestDay('2026-10-05'), 'Monday must not be a rest day.');

        // 2026-10-07 is Wednesday (ISO 3)
        $this->assertFalse($employee->isRestDay('2026-10-07'), 'Wednesday must not be a rest day.');

        // 2026-10-09 is Friday (ISO 5)
        $this->assertFalse($employee->isRestDay('2026-10-09'), 'Friday must not be a rest day.');

        // 2026-10-10 is Saturday (ISO 6)
        $this->assertTrue($employee->isRestDay('2026-10-10'), 'Saturday must be a rest day for Mon-Fri schedule.');

        // 2026-10-11 is Sunday (ISO 7)
        $this->assertTrue($employee->isRestDay('2026-10-11'), 'Sunday must be a rest day for Mon-Fri schedule.');
    }

    public function test_assigned_days_of_week_filtering_full_string_names(): void
    {
        $employee = $this->createTestEmployee();

        $weekendShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekend Warrior',
            'code' => 'WEEKEND-WAR',
            'shift_start' => '10:00:00',
            'shift_end' => '22:00:00',
        ]);

        // Full string names: 'saturday' and 'sunday'
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $weekendShift->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'assigned_days' => ['Saturday', 'Sunday'],
        ]);

        // 2026-10-07 is Wednesday -> Rest Day
        $this->assertTrue($employee->isRestDay('2026-10-07'), 'Wednesday must be a rest day for weekend worker.');

        // 2026-10-10 is Saturday -> Scheduled Work Day
        $this->assertFalse($employee->isRestDay('2026-10-10'), 'Saturday must not be a rest day for weekend worker.');

        // 2026-10-11 is Sunday -> Scheduled Work Day
        $this->assertFalse($employee->isRestDay('2026-10-11'), 'Sunday must not be a rest day for weekend worker.');
    }

    /**
     * PROBE DEFECT 2:
     * While EmployeeShiftAssignment::appliesToDay() supports 3-letter day abbreviations ('Mon', 'Wed'),
     * Employee::currentShift() and Employee::isRestDay() only check full names ($dayName) and numeric IDs,
     * omitting $dayShort. This causes 'Mon' to fail matching on Monday.
     */
    public function test_adversarial_defect_short_day_abbreviations_fail_in_current_shift_and_is_rest_day(): void
    {
        $employee = $this->createTestEmployee();

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'MWF Shift',
            'code' => 'MWF-01',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);

        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'assigned_days' => ['Mon', 'Wed', 'Fri'],
        ]);

        // appliesToDay() works because it checks $dayShort
        $this->assertTrue($assignment->appliesToDay('2026-10-05'), 'appliesToDay() correctly supports Mon short code.');

        // DEFECT: isRestDay() falsely returns true for Monday because it does not check $dayShort
        $this->assertFalse(
            $employee->isRestDay('2026-10-05'),
            'DEFECT: isRestDay() fails on short day abbreviation ["Mon"] and treats Monday as a Rest Day.'
        );
    }

    public function test_bulk_shift_assignment_department_targeting_and_rotation(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $empEng1 = $this->createTestEmployee(['department_id' => $this->deptEngineering->id]);
        $empEng2 = $this->createTestEmployee(['department_id' => $this->deptEngineering->id]);
        $empSales = $this->createTestEmployee(['department_id' => $this->deptSales->id]);

        $initialShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Old Base Shift',
            'code' => 'OLD-BASE',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $newShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering Rotation Shift',
            'code' => 'ENG-ROTATION',
            'shift_start' => '08:30:00',
            'shift_end' => '17:30:00',
        ]);

        // Seed initial open-ended assignment for all 3 employees
        foreach ([$empEng1, $empEng2, $empSales] as $emp) {
            EmployeeShiftAssignment::create([
                'employee_id' => $emp->id,
                'shift_id' => $initialShift->id,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'assigned_days' => [1, 2, 3, 4, 5],
            ]);
        }

        // Bulk assign newShift to Engineering department only starting 2026-07-01
        $response = $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $newShift->id,
            'department_ids' => [$this->deptEngineering->id],
            'effective_from' => '2026-07-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('count'), 'Bulk assign should target exactly 2 engineering employees.');

        // Engineering employees must have old assignment capped at 2026-06-30
        $oldAssign1 = EmployeeShiftAssignment::where('employee_id', $empEng1->id)
            ->where('shift_id', $initialShift->id)
            ->firstOrFail();
        $this->assertSame('2026-06-30', $oldAssign1->effective_to->toDateString());

        // Sales employee was NOT in target department -> old assignment remains open-ended
        $salesAssign = EmployeeShiftAssignment::where('employee_id', $empSales->id)
            ->where('shift_id', $initialShift->id)
            ->firstOrFail();
        $this->assertNull($salesAssign->effective_to);
    }

    /*
    |--------------------------------------------------------------------------
    | Area 3: Holiday Calendar Edge Cases & Department Scope Probing
    |--------------------------------------------------------------------------
    */

    public function test_annual_recurring_holiday_across_leap_years(): void
    {
        // Leap day holiday: February 29, 2024
        $leapHoliday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Quadrennial Leap Day Festival',
            'date' => '2024-02-29',
            'type' => 'company',
            'is_recurring' => true,
        ]);

        // 1. Same leap year (2024-02-29) -> True
        $this->assertTrue($leapHoliday->isHolidayOn('2024-02-29'), 'Must match date in original leap year.');

        // 2. Next leap year (2028-02-29) -> True
        $this->assertTrue($leapHoliday->isHolidayOn('2028-02-29'), 'Annual recurrence must match February 29 in subsequent leap year 2028.');

        // 3. Non-leap year 2025: Feb 28 does not match 02-29
        $this->assertFalse($leapHoliday->isHolidayOn('2025-02-28'), 'Feb 28 in non-leap year must not match 02-29 recurring holiday.');

        // 4. Non-leap year 2025: March 1 does not match 02-29
        $this->assertFalse($leapHoliday->isHolidayOn('2025-03-01'), 'March 1 in non-leap year must not match 02-29 recurring holiday.');

        // 5. Non-leap year 2026: Feb 28 does not match
        $this->assertFalse($leapHoliday->isHolidayOn('2026-02-28'));
    }

    public function test_annual_recurring_holiday_matches_standard_calendar_dates(): void
    {
        // Christmas Day recurring holiday
        $christmas = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Christmas Day',
            'date' => '2024-12-25',
            'type' => 'public',
            'is_recurring' => true,
        ]);

        $this->assertTrue($christmas->isHolidayOn('2024-12-25'));
        $this->assertTrue($christmas->isHolidayOn('2025-12-25'), 'Recurring holiday must match December 25 in 2025.');
        $this->assertTrue($christmas->isHolidayOn('2026-12-25'), 'Recurring holiday must match December 25 in 2026.');
        $this->assertTrue($christmas->isHolidayOn('2030-12-25'), 'Recurring holiday must match December 25 in 2030.');

        $this->assertFalse($christmas->isHolidayOn('2026-12-24'), 'Christmas Eve must not match December 25.');
        $this->assertFalse($christmas->isHolidayOn('2026-12-26'), 'Boxing Day must not match December 25.');
    }

    public function test_department_scoped_holiday_isolation(): void
    {
        $empEng = $this->createTestEmployee(['department_id' => $this->deptEngineering->id]);
        $empSales = $this->createTestEmployee(['department_id' => $this->deptSales->id]);

        // Holiday scoped only to Engineering via structured dictionary applies_to
        $engHoliday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering Hackathon Holiday',
            'date' => '2026-05-18',
            'type' => 'company',
            'is_recurring' => false,
            'applies_to' => [
                'departments' => [$this->deptEngineering->id],
            ],
        ]);

        $this->assertTrue($engHoliday->appliesToEmployee($empEng), 'Must apply to Engineering employee.');
        $this->assertFalse($engHoliday->appliesToEmployee($empSales), 'Must NOT apply to Sales employee.');

        $this->assertTrue($empEng->isHoliday('2026-05-18'), 'Employee in Engineering must evaluate isHoliday as true.');
        $this->assertFalse($empSales->isHoliday('2026-05-18'), 'Employee in Sales must evaluate isHoliday as false.');
    }

    public function test_department_scoped_holiday_raw_array_list_format(): void
    {
        $empEng = $this->createTestEmployee(['department_id' => $this->deptEngineering->id]);
        $empSales = $this->createTestEmployee(['department_id' => $this->deptSales->id]);

        // Holiday scoped via plain array list: [dept_id]
        $salesHoliday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Sales Quota Celebration Day',
            'date' => '2026-06-30',
            'type' => 'company',
            'is_recurring' => false,
            'applies_to' => [$this->deptSales->id],
        ]);

        $this->assertFalse($salesHoliday->appliesToEmployee($empEng));
        $this->assertTrue($salesHoliday->appliesToEmployee($empSales));

        $this->assertFalse($empEng->isHoliday('2026-06-30'));
        $this->assertTrue($empSales->isHoliday('2026-06-30'));
    }

    public function test_location_scoped_holiday_isolation(): void
    {
        $empMain = $this->createTestEmployee(['location_id' => $this->locMain->id]);
        $empBranch = $this->createTestEmployee(['location_id' => $this->locBranch->id]);

        $locHoliday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Campus Founder Day',
            'date' => '2026-08-10',
            'type' => 'company',
            'is_recurring' => false,
            'applies_to' => [
                'locations' => [$this->locMain->id],
            ],
        ]);

        $this->assertTrue($empMain->isHoliday('2026-08-10'));
        $this->assertFalse($empBranch->isHoliday('2026-08-10'));
    }

    public function test_company_wide_holiday_and_cross_organization_isolation(): void
    {
        $empOrg1 = $this->createTestEmployee(['organization_id' => $this->org->id]);

        // Employee in other organization
        $personnelOther = Personnel::create([
            'customize_id' => 888123,
            'name' => 'Other Org Worker',
            'person_type' => 0,
        ]);
        $empOrg2 = Employee::create([
            'organization_id' => $this->otherOrg->id,
            'personnel_id' => $personnelOther->id,
            'employee_code' => 'EMP-OTHER-01',
            'first_name' => 'Foreign',
            'last_name' => 'Worker',
            'work_email' => 'foreign.worker@foreign.com',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        // Company-wide holiday for Apex Dynamics (applies_to = null)
        $companyHoliday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Company Foundation Day',
            'date' => '2026-11-20',
            'type' => 'company',
            'is_recurring' => false,
            'applies_to' => null,
        ]);

        $this->assertTrue($empOrg1->isHoliday('2026-11-20'), 'Company-wide holiday applies to Org 1 employee.');
        $this->assertFalse($empOrg2->isHoliday('2026-11-20'), 'Org 1 holiday must NEVER apply to Org 2 employee.');
    }

    /*
    |--------------------------------------------------------------------------
    | Area 4: RBAC Authorization & Security Boundaries
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_request_to_create_shift_returns_401(): void
    {
        $response = $this->postJson('/api/shifts', [
            'name' => 'Unauthorized Day Shift',
            'code' => 'UNAUTH-SHIFT',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthenticated_request_to_create_holiday_returns_401(): void
    {
        $response = $this->postJson('/api/holidays', [
            'name' => 'Unauthorized Holiday',
            'date' => '2026-12-31',
        ]);

        $response->assertStatus(401);
    }

    public function test_unprivileged_employee_role_cannot_create_shift_returns_403(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);

        $response = $this->postJson('/api/shifts', [
            'organization_id' => $this->org->id,
            'name' => 'Employee Attempted Shift',
            'code' => 'EMP-HACK-01',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_unprivileged_employee_role_cannot_create_holiday_returns_403(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);

        $response = $this->postJson('/api/holidays', [
            'organization_id' => $this->org->id,
            'name' => 'Employee Fictional Holiday',
            'date' => '2026-05-01',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_unprivileged_employee_role_cannot_modify_delete_or_assign_shifts_and_holidays(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);

        $shift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Protected Shift',
            'code' => 'PROT-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
        ]);

        $holiday = Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Protected Holiday',
            'date' => '2026-07-04',
        ]);

        $emp = $this->createTestEmployee();

        // 1. PUT /api/shifts/{id} -> 403
        $this->putJson("/api/shifts/{$shift->id}", ['name' => 'Hacked Shift'])->assertStatus(403);

        // 2. DELETE /api/shifts/{id} -> 403
        $this->deleteJson("/api/shifts/{$shift->id}")->assertStatus(403);

        // 3. POST /api/shifts/{id}/assign -> 403
        $this->postJson("/api/shifts/{$shift->id}/assign", [
            'employee_ids' => [$emp->id],
            'effective_from' => '2026-01-01',
        ])->assertStatus(403);

        // 4. POST /api/shifts/bulk-assign -> 403
        $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $shift->id,
            'department_ids' => [$this->deptEngineering->id],
            'effective_from' => '2026-01-01',
        ])->assertStatus(403);

        // 5. POST /api/employees/{id}/assign-shift -> 403
        $this->postJson("/api/employees/{$emp->id}/assign-shift", [
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
        ])->assertStatus(403);

        // 6. PUT /api/holidays/{id} -> 403
        $this->putJson("/api/holidays/{$holiday->id}", ['name' => 'Hacked Holiday'])->assertStatus(403);

        // 7. DELETE /api/holidays/{id} -> 403
        $this->deleteJson("/api/holidays/{$holiday->id}")->assertStatus(403);
    }

    public function test_privileged_hr_manager_can_create_shifts_and_holidays(): void
    {
        Sanctum::actingAs($this->hrUser, ['*']);

        // HR Manager has schedules.manage and shifts.manage -> HTTP 201
        $shiftResp = $this->postJson('/api/shifts', [
            'organization_id' => $this->org->id,
            'name' => 'HR Morning Shift',
            'code' => 'HR-MORN-01',
            'shift_start' => '07:30',
            'shift_end' => '16:30',
            'grace_period_minutes' => 15,
        ]);
        $shiftResp->assertStatus(201);

        $holidayResp = $this->postJson('/api/holidays', [
            'organization_id' => $this->org->id,
            'name' => 'HR Scheduled Holiday',
            'date' => '2026-09-01',
            'type' => 'company',
        ]);
        $holidayResp->assertStatus(201);
    }
}
