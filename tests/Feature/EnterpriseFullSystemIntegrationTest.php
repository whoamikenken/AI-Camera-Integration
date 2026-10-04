<?php

namespace Tests\Feature;

use App\Events\AttendancePunchReceived;
use App\Events\VisitorCheckedIn;
use App\Events\VisitorCheckedOut;
use App\Jobs\ProcessAttendancePunchJob;
use App\Jobs\SyncPersonnelJob;
use App\Models\AccessLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\RegularizationRequest;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\AttendanceProcessingService;
use App\Services\LeaveService;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnterpriseFullSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Department $dept;
    protected Designation $desig;
    protected Shift $dayShift;
    protected Shift $nightShift;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed Roles & Setup Admin
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

        $this->org = Organization::create([
            'name' => 'Acme Corp Global',
            'code' => 'ACME',
            'timezone' => 'UTC',
        ]);

        $this->dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $this->desig = Designation::create([
            'organization_id' => $this->org->id,
            'name' => 'Senior DevOps Engineer',
            'level' => 3,
        ]);

        $this->adminUser = User::factory()->create([
            'email' => 'admin@acme.com',
        ]);
        $adminRole = Role::where('slug', 'super-admin')->first();
        if ($adminRole) {
            $this->adminUser->roles()->attach($adminRole->id);
        }

        // Shifts
        $this->dayShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Day Shift',
            'code' => 'DAY',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'grace_period_minutes' => 15,
            'early_out_threshold_minutes' => 30,
            'half_day_threshold_hours' => 4,
            'min_hours_full_day' => 8,
            'break_duration_minutes' => 60,
            'is_overnight' => false,
            'is_active' => true,
        ]);

        $this->nightShift = Shift::create([
            'organization_id' => $this->org->id,
            'name' => 'Night Shift',
            'code' => 'NIGHT',
            'shift_start' => '22:00:00',
            'shift_end' => '06:00:00',
            'grace_period_minutes' => 15,
            'early_out_threshold_minutes' => 30,
            'half_day_threshold_hours' => 4,
            'min_hours_full_day' => 7,
            'break_duration_minutes' => 60,
            'is_overnight' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Full Employee Onboarding & Biometric Linkage
     */
    public function test_employee_onboarding_and_biometric_face_linkage(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/employees', [
            'first_name' => 'Alex',
            'last_name' => 'Mercer',
            'employee_code' => 'EMP-0099',
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'designation_id' => $this->desig->id,
            'shift_id' => $this->dayShift->id,
            'work_email' => 'alex.mercer@acme.com',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $empId = $response->json('data.id');

        $this->assertDatabaseHas('employees', [
            'id' => $empId,
            'employee_code' => 'EMP-0099',
            'first_name' => 'Alex',
        ]);

        $employee = Employee::with('personnel')->find($empId);
        $this->assertNotNull($employee->personnel_id);
        $this->assertEquals('Alex Mercer', $employee->personnel->name);
        $this->assertEquals(0, $employee->personnel->person_type); // Whitelist

        Queue::assertPushed(SyncPersonnelJob::class);
    }

    /**
     * Test 2: Biometric Punch Pipeline, Late Detection and Break Calculation
     */
    public function test_biometric_telemetry_punch_processing_and_metrics(): void
    {
        Event::fake([AttendancePunchReceived::class]);

        $personnel = Personnel::create([
            'customize_id' => 123456,
            'name' => 'Sarah Connor',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-1234',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'shift_id' => $this->dayShift->id,
            'employment_status' => 'active',
        ]);

        $entryCam = Device::create([
            'device_id' => 'CAM-ENTRY-01',
            'name' => 'Main Gate Inbound',
            'ip_address' => '192.168.1.50',
            'device_role' => 'entry',
            'is_active' => true,
        ]);

        $exitCam = Device::create([
            'device_id' => 'CAM-EXIT-01',
            'name' => 'Main Gate Outbound',
            'ip_address' => '192.168.1.51',
            'device_role' => 'exit',
            'is_active' => true,
        ]);

        $service = app(AttendanceProcessingService::class);

        // Clock In at 09:25 AM (10 mins after 15m grace period -> late)
        $punchIn = $service->processPunch(
            employee: $employee,
            punchTime: '2026-09-30 09:25:00',
            direction: 'in',
            device: $entryCam,
            source: 'camera_auto'
        );

        $this->assertNotNull($punchIn);
        $this->assertEquals('in', $punchIn->direction);

        // Clock Out at 18:30 PM
        $punchOut = $service->processPunch(
            employee: $employee,
            punchTime: '2026-09-30 18:30:00',
            direction: 'out',
            device: $exitCam,
            source: 'camera_auto'
        );

        $this->assertNotNull($punchOut);
        $this->assertEquals('out', $punchOut->direction);

        // Check Attendance Record for the day
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', '2026-09-30')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->is_late);
        $this->assertEquals(25, $record->late_minutes);
        $this->assertEquals('late', $record->status);
        $this->assertGreaterThan(7.5, (float) $record->total_work_hours);

        Event::assertDispatched(AttendancePunchReceived::class);
    }

    /**
     * Test 3: Overnight Shift Punch Alignment
     */
    public function test_overnight_shift_punch_reconciliation(): void
    {
        $personnel = Personnel::create([
            'customize_id' => 777888,
            'name' => 'Night Guard',
            'person_type' => 0,
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-NIGHT-1',
            'first_name' => 'Night',
            'last_name' => 'Guard',
            'shift_id' => $this->nightShift->id,
            'employment_status' => 'active',
        ]);

        $service = app(AttendanceProcessingService::class);

        // Entry at 21:55 (night of Sep 29)
        $service->processPunch(
            employee: $employee,
            punchTime: '2026-09-29 21:55:00',
            direction: 'in'
        );

        // Exit at 06:05 (morning of Sep 30)
        $service->processPunch(
            employee: $employee,
            punchTime: '2026-09-30 06:05:00',
            direction: 'out'
        );

        // Both punches should map to work date 2026-09-29
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', '2026-09-29')
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('present', $record->status);
        $this->assertFalse((bool) $record->is_late);
        $this->assertGreaterThanOrEqual(7.0, (float) $record->total_work_hours);
    }

    /**
     * Test 4: Leave Application, Quota Deduction and Attendance Propagation
     */
    public function test_leave_lifecycle_and_attendance_status_sync(): void
    {
        $employee = Employee::create([
            'employee_code' => 'EMP-LEAVE-1',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'employment_status' => 'active',
        ]);

        $annualLeave = LeaveType::create([
            'name' => 'Annual Paid Leave',
            'code' => 'AL',
            'max_days_per_year' => 14,
            'is_paid' => true,
        ]);

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => 2026,
            'allocated' => 14,
            'used' => 0,
            'pending' => 0,
        ]);

        $leaveService = app(LeaveService::class);

        // Apply for 2 days leave
        $leaveRequest = $leaveService->createRequest($employee, [
            'leave_type_id' => $annualLeave->id,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
            'reason' => 'Annual Vacation',
        ]);

        $this->assertEquals('pending', $leaveRequest->status);

        // Approve Request
        $approved = $leaveService->approveRequest($leaveRequest, $this->adminUser->id);
        $this->assertEquals('approved', $approved->status);

        // Check balance deducted
        $balance->refresh();
        $this->assertEquals(2, $balance->used);

        // Check attendance records marked as on_leave
        $att1 = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '2026-10-05')->first();
        $att2 = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '2026-10-06')->first();

        $this->assertNotNull($att1);
        $this->assertEquals('on_leave', $att1->status);
        $this->assertNotNull($att2);
        $this->assertEquals('on_leave', $att2->status);
    }

    /**
     * Test 5: Visitor Pre-Registration, Check-in Whitelist Provisioning & Checkout Revocation
     */
    public function test_visitor_lifecycle_and_biometric_camera_sync(): void
    {
        Queue::fake();
        Event::fake([VisitorCheckedIn::class, VisitorCheckedOut::class]);

        $host = Employee::create([
            'employee_code' => 'HOST-001',
            'first_name' => 'David',
            'last_name' => 'Miller',
            'employment_status' => 'active',
        ]);

        $visitor = Visitor::create([
            'first_name' => 'Elon',
            'last_name' => 'Musk',
            'company' => 'SpaceX',
            'email' => 'elon@spacex.com',
            'phone' => '+1-555-0199',
            'is_blocked' => false,
        ]);

        $visit = Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $host->id,
            'purpose' => 'meeting',
            'status' => 'expected',
        ]);

        $visitorSync = app(VisitorSyncService::class);

        // Check In
        $checkedInVisit = $visitorSync->checkIn($visit, ['badge_number' => 'V-999']);
        $this->assertEquals('checked_in', $checkedInVisit->status);
        $this->assertNotNull($checkedInVisit->personnel_id);

        // Verify temporary personnel profile exists
        $tempPerson = Personnel::find($checkedInVisit->personnel_id);
        $this->assertNotNull($tempPerson);
        $this->assertEquals(0, $tempPerson->person_type); // Whitelist
        $this->assertEquals(1, $tempPerson->temp_valid);

        Queue::assertPushed(SyncPersonnelJob::class);
        Event::assertDispatched(VisitorCheckedIn::class);

        // Check Out
        $checkedOutVisit = $visitorSync->checkOut($checkedInVisit);
        $this->assertEquals('checked_out', $checkedOutVisit->status);
        $this->assertNull($checkedOutVisit->personnel_id);

        Event::assertDispatched(VisitorCheckedOut::class);
    }

    /**
     * Test 6: Security Watchlist Interception
     */
    public function test_security_watchlist_blocked_visitor_interception(): void
    {
        $blockedVisitor = Visitor::create([
            'first_name' => 'Suspicious',
            'last_name' => 'Person',
            'is_blocked' => true,
            'block_reason' => 'Previous security policy violation',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/visits/pre-register', [
            'visitor_id' => $blockedVisitor->id,
            'purpose' => 'meeting',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test 7: Payroll Export Generation
     */
    public function test_payroll_export_csv_and_json_endpoints(): void
    {
        $emp = Employee::create([
            'employee_code' => 'PAY-001',
            'first_name' => 'Robert',
            'last_name' => 'Pattinson',
            'employment_status' => 'active',
        ]);

        AttendanceRecord::create([
            'employee_id' => $emp->id,
            'date' => '2026-09-01',
            'first_clock_in' => '2026-09-01 09:00:00',
            'last_clock_out' => '2026-09-01 18:00:00',
            'total_work_hours' => 8.0,
            'overtime_hours' => 1.5,
            'status' => 'present',
        ]);

        $resJson = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/payroll/export?month=9&year=2026&format=json');
        $resJson->assertStatus(200);
        $resJson->assertJsonStructure(['month', 'year', 'data']);

        $resCsv = $this->actingAs($this->adminUser, 'sanctum')->get('/api/payroll/export?month=9&year=2026&format=csv');
        $resCsv->assertStatus(200);
        $this->assertStringContainsString('PAY-001', $resCsv->streamedContent());
        $this->assertStringContainsString('Robert Pattinson', $resCsv->streamedContent());
    }
}
