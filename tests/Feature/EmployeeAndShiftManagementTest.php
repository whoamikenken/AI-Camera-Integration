<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAndShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        $this->org = Organization::create([
            'name' => 'Pinnacle Technologies Inc.',
            'code' => 'PINNACLE-HQ',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$role->id]);

        Sanctum::actingAs($this->adminUser, ['*']);
    }

    public function test_employee_crud_full_lifecycle(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9001,
            'name' => 'John Wick',
            'person_type' => 0,
        ]);

        $dept = Department::create(['name' => 'Operations', 'code' => 'OPS', 'organization_id' => $this->org->id]);
        $desig = Designation::create(['name' => 'Specialist', 'level' => 3, 'organization_id' => $this->org->id]);
        $loc = Location::create(['name' => 'Building B', 'code' => 'BLD-B', 'organization_id' => $this->org->id]);

        // Create
        $response = $this->postJson('/api/employees', [
            'personnel_id' => $personnel->id,
            'department_id' => $dept->id,
            'designation_id' => $desig->id,
            'location_id' => $loc->id,
            'employee_code' => 'EMP-JW-01',
            'first_name' => 'John',
            'last_name' => 'Wick',
            'work_email' => 'john.wick@continental.com',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'date_of_joining' => '2026-01-15',
        ]);

        $response->assertStatus(201);
        $empId = $response->json('data.id');
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-JW-01', 'id' => $empId]);

        // Read
        $getResp = $this->getJson("/api/employees/{$empId}");
        $getResp->assertStatus(200)
            ->assertJsonFragment(['name' => 'John Wick']);

        // Update
        $updateResp = $this->putJson("/api/employees/{$empId}", [
            'first_name' => 'Jonathan',
            'phone' => '+1-555-0199',
        ]);
        $updateResp->assertStatus(200)
            ->assertJsonFragment(['name' => 'Jonathan Wick', 'phone' => '+1-555-0199']);

        // Soft Delete
        $delResp = $this->deleteJson("/api/employees/{$empId}");
        $delResp->assertStatus(200);
        $this->assertSoftDeleted('employees', ['id' => $empId]);
    }

    public function test_employee_code_and_email_uniqueness_validation(): void
    {
        Employee::create([
            'employee_code' => 'EMP-EXISTING',
            'first_name' => 'Unique',
            'last_name' => 'User',
            'work_email' => 'unique@example.com',
            'employment_status' => 'active',
        ]);

        // Duplicate code
        $resp1 = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-EXISTING',
            'first_name' => 'Another',
            'last_name' => 'User',
            'work_email' => 'another@example.com',
        ]);
        $resp1->assertStatus(422);

        // Duplicate email
        $resp2 = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-NEW',
            'first_name' => 'Another',
            'last_name' => 'User',
            'work_email' => 'unique@example.com',
        ]);
        $resp2->assertStatus(422);
    }

    public function test_employee_csv_export_and_import(): void
    {
        Employee::create([
            'employee_code' => 'EMP-IMP-01',
            'first_name' => 'Export',
            'last_name' => 'Test',
            'work_email' => 'export.test@example.com',
            'employment_status' => 'active',
        ]);

        $exportResp = $this->get('/api/employees/export');
        $exportResp->assertStatus(200);
        $this->assertStringContainsString('text/csv', $exportResp->headers->get('content-type'));

        // Test CSV Import
        $csvContent = "employee_code,first_name,last_name,work_email\nEMP-NEW-CSV,CsvFirst,CsvLast,csv@example.com";
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $importResp = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);
        $importResp->assertStatus(200)
            ->assertJsonFragment(['imported_count' => 1]);

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-NEW-CSV', 'first_name' => 'CsvFirst']);
    }

    public function test_shift_definition_and_batch_assignment(): void
    {
        $shiftResp = $this->postJson('/api/shifts', [
            'name' => 'Morning Shift A',
            'code' => 'SHIFT-AM-A',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'grace_period_minutes' => 10,
            'early_out_threshold_minutes' => 15,
            'break_duration_minutes' => 60,
            'min_hours_full_day' => 8.0,
            'half_day_threshold_hours' => 4.0,
        ]);
        $shiftResp->assertStatus(201);
        $shiftId = $shiftResp->json('data.id');

        $emp1 = Employee::create([
            'employee_code' => 'EMP-S1',
            'first_name' => 'Emp',
            'last_name' => 'One',
            'employment_status' => 'active',
        ]);
        $emp2 = Employee::create([
            'employee_code' => 'EMP-S2',
            'first_name' => 'Emp',
            'last_name' => 'Two',
            'employment_status' => 'active',
        ]);

        // Batch Assign
        $assignResp = $this->postJson("/api/shifts/{$shiftId}/assign", [
            'employee_ids' => [$emp1->id, $emp2->id],
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-12-31',
            'assigned_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);
        $assignResp->assertStatus(201);

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $emp1->id,
            'shift_id' => $shiftId,
        ]);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $emp2->id,
            'shift_id' => $shiftId,
        ]);

        $emp1->refresh();
        $this->assertEquals($shiftId, $emp1->shift_id);
    }

    public function test_holiday_crud_and_filtering(): void
    {
        $holResp = $this->postJson('/api/holidays', [
            'name' => 'Christmas Day',
            'date' => '2026-12-25',
            'type' => 'public',
            'is_recurring' => true,
        ]);
        $holResp->assertStatus(201);
        $holId = $holResp->json('data.id');

        $this->assertDatabaseHas('holidays', ['id' => $holId, 'name' => 'Christmas Day', 'is_recurring' => true]);

        // List with year filter
        $listResp = $this->getJson('/api/holidays?year=2026');
        $listResp->assertStatus(200)
            ->assertJsonFragment(['name' => 'Christmas Day']);

        // Update
        $upResp = $this->putJson("/api/holidays/{$holId}", [
            'name' => 'Christmas Holiday',
        ]);
        $upResp->assertStatus(200)
            ->assertJsonFragment(['name' => 'Christmas Holiday']);

        // Delete
        $delResp = $this->deleteJson("/api/holidays/{$holId}");
        $delResp->assertStatus(200);
        $this->assertDatabaseMissing('holidays', ['id' => $holId]);
    }

    public function test_biometric_bridge_auto_creates_personnel_and_dispatches_sync(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncPersonnelJob::class]);

        $response = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-BIO-01',
            'first_name' => 'Biometric',
            'last_name' => 'Subject',
            'photo_base64' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
            'employment_status' => 'active',
        ]);

        $response->assertStatus(201);
        $empId = $response->json('data.id');
        $employee = Employee::with('personnel')->findOrFail($empId);

        $this->assertNotNull($employee->personnel_id);
        $this->assertEquals('Biometric Subject', $employee->personnel->name);
        $this->assertEquals(0, $employee->personnel->person_type); // Whitelist
        $this->assertEquals('data:image/jpeg;base64,/9j/4AAQSkZJRg==', $employee->personnel->photo_base64);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncPersonnelJob::class, function ($job) use ($employee) {
            return $job->personnelId === $employee->personnel_id && $job->action === 'ADD';
        });
    }

    public function test_biometric_bridge_status_update_cascades_blacklist_to_personnel(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 9101,
            'name' => 'Agent Smith',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-SMITH',
            'first_name' => 'Agent',
            'last_name' => 'Smith',
            'employment_status' => 'active',
        ]);

        // Suspend employee -> blacklist personnel
        $resp1 = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'suspended',
        ]);
        $resp1->assertStatus(200);

        $personnel->refresh();
        $this->assertEquals(1, $personnel->person_type); // Blacklisted

        // Activate employee -> whitelist personnel
        $resp2 = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'active',
        ]);
        $resp2->assertStatus(200);

        $personnel->refresh();
        $this->assertEquals(0, $personnel->person_type); // Whitelisted
    }

    public function test_employee_deletion_de_provisions_biometrics_and_purges_telemetry_access_logs(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SyncPersonnelJob::class]);

        $personnel = Personnel::create([
            'customize_id' => 9201,
            'name' => 'Neo Anderson',
            'person_type' => 0,
        ]);

        $device = \App\Models\Device::create([
            'device_id' => 'DEV-TERMINAL-01',
            'name' => 'Front Turnstile',
            'ip_address' => '192.168.1.100',
            'is_active' => true,
        ]);

        \App\Models\AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $personnel->customize_id,
            'verify_status' => 1,
            'captured_at' => now(),
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-NEO',
            'first_name' => 'Thomas',
            'last_name' => 'Anderson',
            'employment_status' => 'active',
        ]);

        $delResp = $this->deleteJson("/api/employees/{$employee->id}");
        $delResp->assertStatus(200);

        // Employee soft-deleted
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);

        // Personnel deleted to revoke biometric edge profile
        $this->assertDatabaseMissing('personnel', ['id' => $personnel->id]);

        // Access log telemetry purged by customize_id
        $this->assertDatabaseMissing('access_logs', ['customize_id' => 9201]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncPersonnelJob::class, function ($job) {
            return $job->action === 'DELETE' && $job->customizeIdToDelete === 9201;
        });
    }

    public function test_employee_and_shift_m3_interface_contracts(): void
    {
        $dayShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day 9-18',
            'code' => 'SHIFT-M3-DAY',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'grace_period_minutes' => 15,
            'break_duration_minutes' => 60,
            'is_overnight' => false,
            'is_active' => true,
        ]);

        $nightShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Graveyard 22-07',
            'code' => 'SHIFT-M3-NIGHT',
            'shift_start' => '22:00:00',
            'shift_end' => '07:00:00',
            'break_duration_minutes' => 60,
            'is_overnight' => true,
            'is_active' => true,
        ]);

        // Shift helper tests
        $this->assertTrue($dayShift->isDayShift());
        $this->assertFalse($dayShift->isOvernight());
        $this->assertEquals(540, $dayShift->durationMinutes(false)); // 9 hours gross
        $this->assertEquals(480, $dayShift->durationMinutes(true));  // 8 hours net of break
        $this->assertEquals('09:00:00', $dayShift->start_time);
        $this->assertEquals('18:00:00', $dayShift->end_time);

        $this->assertTrue($nightShift->isOvernight());
        $this->assertTrue($nightShift->crossesMidnight());
        $this->assertEquals(540, $nightShift->durationMinutes(false)); // 22:00 to 07:00 = 9h
        $this->assertEquals(480, $nightShift->durationMinutes(true));

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'shift_id' => $dayShift->id,
            'employee_code' => 'EMP-CONTRACT',
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
            'employment_status' => 'active',
        ]);

        // Default shift
        $current = $employee->currentShift(Carbon::parse('2026-10-05')); // Monday
        $this->assertEquals($dayShift->id, $current->id);

        // Assign Night Shift for weekdays
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $nightShift->id,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'assigned_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        $assignedCurrent = $employee->currentShift(Carbon::parse('2026-10-05'));
        $this->assertEquals($nightShift->id, $assignedCurrent->id);

        // Rest Day logic: Weekday is NOT rest day, Saturday is rest day
        $this->assertFalse($employee->isRestDay(Carbon::parse('2026-10-05'))); // Monday
        $this->assertTrue($employee->isRestDay(Carbon::parse('2026-10-10')));  // Saturday

        // Holiday test
        Holiday::create([
            'organization_id' => $this->org->id,
            'name' => 'Mid-Autumn Festival',
            'date' => '2026-10-06',
            'type' => 'company',
            'is_recurring' => false,
        ]);

        $this->assertTrue($employee->isHoliday(Carbon::parse('2026-10-06')));
        $this->assertFalse($employee->isHoliday(Carbon::parse('2026-10-07')));
    }

    public function test_shift_bulk_assign_by_departments_and_rotation_capping(): void
    {
        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG', 'organization_id' => $this->org->id]);

        $emp1 = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => 'EMP-BULK-1',
            'first_name' => 'Dev',
            'last_name' => 'One',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => 'EMP-BULK-2',
            'first_name' => 'Dev',
            'last_name' => 'Two',
            'employment_status' => 'active',
        ]);

        $shiftA = Shift::create([
            'name' => 'Shift A',
            'code' => 'S-A',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'is_active' => true,
        ]);

        $shiftB = Shift::create([
            'name' => 'Shift B',
            'code' => 'S-B',
            'shift_start' => '13:00:00',
            'shift_end' => '22:00:00',
            'is_active' => true,
        ]);

        // First assignment: open ended starting Oct 1
        EmployeeShiftAssignment::create([
            'employee_id' => $emp1->id,
            'shift_id' => $shiftA->id,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
        ]);

        // Bulk assign Shift B to entire department starting Nov 1
        $response = $this->postJson('/api/shifts/bulk-assign', [
            'shift_id' => $shiftB->id,
            'department_ids' => [$dept->id],
            'effective_from' => '2026-11-01',
            'effective_to' => null,
            'assigned_days' => [1, 2, 3, 4, 5],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['count' => 2]);

        // Check prior assignment for emp1 was capped at 2026-10-31
        $prevAssignment = EmployeeShiftAssignment::where('employee_id', $emp1->id)
            ->where('shift_id', $shiftA->id)
            ->first();
        $this->assertEquals('2026-10-31', $prevAssignment->effective_to->format('Y-m-d'));

        // Check new assignment for emp2 was created
        $newAssignment = EmployeeShiftAssignment::where('employee_id', $emp2->id)
            ->where('shift_id', $shiftB->id)
            ->first();
        $this->assertNotNull($newAssignment);
        $this->assertEquals('2026-11-01', $newAssignment->effective_from->format('Y-m-d'));
    }

    public function test_employee_attendance_summary_endpoint(): void
    {
        $shift = Shift::create([
            'name' => 'General Shift',
            'code' => 'GEN-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'shift_id' => $shift->id,
            'employee_code' => 'EMP-SUM-01',
            'first_name' => 'Summary',
            'last_name' => 'User',
            'employment_status' => 'active',
        ]);

        $response = $this->getJson("/api/employees/{$employee->id}/attendance-summary?from=2026-10-01&to=2026-10-31");
        $response->assertStatus(200)
            ->assertJsonStructure([
                'employee_id',
                'period' => ['from', 'to'],
                'total_working_days',
                'present_days',
                'absent_days',
                'late_days',
                'half_days',
                'on_leave_days',
                'total_work_hours',
                'total_overtime_hours',
                'recent_punches',
            ]);

        $this->assertEquals($employee->id, $response->json('employee_id'));
        $this->assertGreaterThan(0, $response->json('total_working_days'));
    }
}
