<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\RegularizationRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\CsvSanitizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class AdversarialAuthAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected Role $superAdminRole;
    protected Role $managerRole;
    protected Role $employeeRole;
    protected User $superAdmin;
    protected User $managerUser;
    protected User $standardUser1;
    protected User $standardUser2;
    protected Employee $managerEmployee;
    protected Employee $employee1;
    protected Employee $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Adversarial Test Org',
            'code' => 'ADV-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $this->managerRole = Role::firstOrCreate(['slug' => 'manager'], [
            'name' => 'Manager',
            'is_system' => true,
        ]);
        foreach ([
            'attendance.manage',
            'attendance.view',
            'leaves.manage',
            'leaves.view',
            'selfservice.view',
            'reports.view',
            'devices.view',
            'devices.manage',
        ] as $perm) {
            $this->managerRole->givePermission($perm);
        }

        $this->employeeRole = Role::firstOrCreate(['slug' => 'employee'], [
            'name' => 'Employee',
            'is_system' => true,
        ]);
        foreach ([
            'attendance.view',
            'leaves.view',
            'selfservice.view',
        ] as $perm) {
            $this->employeeRole->givePermission($perm);
        }

        // Super Admin
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->superAdmin->roles()->sync([$this->superAdminRole->id]);

        // Manager User & Employee
        $this->managerUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->managerUser->roles()->sync([$this->managerRole->id]);

        $this->managerEmployee = Employee::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->managerUser->id,
            'employee_code' => 'MGR-001',
            'first_name' => 'Marcus',
            'last_name' => 'Manager',
            'employment_status' => 'active',
        ]);

        // Standard User 1 & Employee 1
        $this->standardUser1 = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->standardUser1->roles()->sync([$this->employeeRole->id]);

        $this->employee1 = Employee::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->standardUser1->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'Edward',
            'last_name' => 'Employee',
            'employment_status' => 'active',
        ]);

        // Standard User 2 & Employee 2
        $this->standardUser2 = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->standardUser2->roles()->sync([$this->employeeRole->id]);

        $this->employee2 = Employee::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->standardUser2->id,
            'employee_code' => 'EMP-002',
            'first_name' => 'Emma',
            'last_name' => 'Employee',
            'employment_status' => 'active',
        ]);

        auth()->forgetGuards();
    }

    // =========================================================================
    // SCENARIO 1: Self-Approval & Privilege Escalation
    // =========================================================================

    public function test_employee_cannot_approve_own_leave_request(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Vacation Leave',
            'code' => 'VL-01',
            'max_days_per_year' => 10,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 10,
            'used' => 0,
            'pending' => 2,
        ]);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-11',
            'total_days' => 2,
            'status' => 'pending',
            'reason' => 'Annual break',
        ]);

        // 1. Without leaves.approve permission: blocked with 403 by permission middleware
        Sanctum::actingAs($this->standardUser1, ['*']);
        $respWithoutPerm = $this->putJson("/api/leave-requests/{$leaveRequest->id}/approve");
        $respWithoutPerm->assertStatus(403);

        // 2. Even if employee is granted leaves.approve permission: blocked with 403 by anti-self-approval guard
        $this->employeeRole->givePermission('leaves.approve');
        $this->standardUser1->load('roles.permissions');

        $respWithPerm = $this->putJson("/api/leave-requests/{$leaveRequest->id}/approve");
        $respWithPerm->assertStatus(403);
        $respWithPerm->assertJson([
            'message' => 'Self-approval of leave requests is forbidden.',
        ]);

        $this->assertEquals('pending', $leaveRequest->fresh()->status);
    }

    public function test_manager_cannot_approve_own_leave_request(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Medical Leave',
            'code' => 'ML-01',
            'max_days_per_year' => 15,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->managerEmployee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 15,
            'used' => 0,
            'pending' => 1,
        ]);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $this->managerEmployee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-15',
            'total_days' => 1,
            'status' => 'pending',
            'reason' => 'Manager doctor appointment',
        ]);

        // Act as Manager (who has leaves.manage, but is the owner of this request)
        Sanctum::actingAs($this->managerUser, ['*']);

        $response = $this->putJson("/api/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Self-approval of leave requests is forbidden.',
        ]);

        $this->assertEquals('pending', $leaveRequest->fresh()->status);
    }

    public function test_employee_cannot_submit_regularization_for_another_employee(): void
    {
        // Act as Employee 1
        Sanctum::actingAs($this->standardUser1, ['*']);

        // Attempt to submit regularization for Employee 2
        $response = $this->postJson('/api/regularization-requests', [
            'employee_id' => $this->employee2->id,
            'date' => '2026-09-25',
            'requested_in' => '2026-09-25 08:00:00',
            'requested_out' => '2026-09-25 17:00:00',
            'reason' => 'Submitting on behalf of peer without permission',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You cannot submit regularization requests for other employees.',
        ]);

        $this->assertDatabaseMissing('regularization_requests', [
            'employee_id' => $this->employee2->id,
            'reason' => 'Submitting on behalf of peer without permission',
        ]);
    }

    public function test_approver_cannot_approve_own_regularization_request(): void
    {
        // Create regularization request for Manager
        $regularization = RegularizationRequest::create([
            'employee_id' => $this->managerEmployee->id,
            'date' => '2026-09-20',
            'requested_in' => '2026-09-20 09:00:00',
            'requested_out' => '2026-09-20 18:00:00',
            'reason' => 'Missed punch due to meeting',
            'status' => 'pending',
        ]);

        // Act as Manager (has attendance.manage)
        Sanctum::actingAs($this->managerUser, ['*']);

        $response = $this->putJson("/api/regularization-requests/{$regularization->id}/approve");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Self-approval of regularization requests is forbidden.',
        ]);

        $this->assertEquals('pending', $regularization->fresh()->status);
        $this->assertNull($regularization->fresh()->approved_by);
    }

    public function test_manager_can_approve_subordinate_regularization_and_leave(): void
    {
        // Create leave request for Employee 1
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Emergency Leave',
            'code' => 'EL-01',
            'max_days_per_year' => 5,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 5,
            'used' => 0,
            'pending' => 1,
        ]);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-18',
            'end_date' => '2026-10-18',
            'total_days' => 1,
            'status' => 'pending',
            'reason' => 'Personal emergency',
        ]);

        // Regularization for Employee 1
        $regularization = RegularizationRequest::create([
            'employee_id' => $this->employee1->id,
            'date' => '2026-09-22',
            'requested_in' => '2026-09-22 08:30:00',
            'requested_out' => '2026-09-22 17:30:00',
            'reason' => 'Card reader malfunction',
            'status' => 'pending',
        ]);

        // Act as Manager approving Employee 1
        Sanctum::actingAs($this->managerUser, ['*']);

        $leaveResp = $this->putJson("/api/leave-requests/{$leaveRequest->id}/approve");
        $leaveResp->assertStatus(200);
        $this->assertEquals('approved', $leaveRequest->fresh()->status);

        $regResp = $this->putJson("/api/regularization-requests/{$regularization->id}/approve");
        $regResp->assertStatus(200);
        $this->assertEquals('approved', $regularization->fresh()->status);
        $this->assertEquals($this->managerUser->id, $regularization->fresh()->approved_by);
    }

    // =========================================================================
    // SCENARIO 2: IDOR on Notifications
    // =========================================================================

    public function test_user_a_cannot_mark_user_b_notification_as_read(): void
    {
        $notifId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'type' => 'App\Notifications\SystemAlert',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->standardUser2->id,
            'data' => json_encode(['title' => 'Private Alert for User 2']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User 1 attempts to mark User 2's notification as read via PUT
        Sanctum::actingAs($this->standardUser1, ['*']);

        $responsePut = $this->putJson("/api/notifications/{$notifId}/read");
        $responsePut->assertStatus(404);
        $responsePut->assertJson([
            'message' => 'Notification not found or access denied.',
        ]);

        // Verify notification remains unread in database
        $isUnread = DB::table('notifications')
            ->where('id', $notifId)
            ->whereNull('read_at')
            ->exists();
        $this->assertTrue($isUnread);
    }

    public function test_notification_mark_read_method_support(): void
    {
        $notifId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'type' => 'App\Notifications\SystemAlert',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->standardUser2->id,
            'data' => json_encode(['title' => 'Test Method Alert']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->standardUser1, ['*']);

        // Check PATCH request
        $responsePatch = $this->patchJson("/api/notifications/{$notifId}/read");

        // The current routes/api.php defines Route::put for notifications/{id}/read
        // If the router rejects PATCH, it returns 405 Method Not Allowed;
        // if patched to accept PATCH, it returns 404 due to ownership scoping.
        $this->assertContains($responsePatch->status(), [404, 405], 'PATCH must return either 404 (ownership check) or 405 (route only binds PUT)');

        // Crucially, under NO circumstance should User 1 be allowed to mark User 2's notification as read (never 200)
        $this->assertNotEquals(200, $responsePatch->status());
        $this->assertTrue(DB::table('notifications')->where('id', $notifId)->whereNull('read_at')->exists());
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $notifId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'type' => 'App\Notifications\SystemAlert',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->standardUser1->id,
            'data' => json_encode(['title' => 'Notification for User 1']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->standardUser1, ['*']);

        $response = $this->putJson("/api/notifications/{$notifId}/read");
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Notification marked as read.',
        ]);

        $this->assertNotNull(DB::table('notifications')->where('id', $notifId)->value('read_at'));
    }

    // =========================================================================
    // SCENARIO 3: CSV Formula Injection (DDE)
    // =========================================================================

    public function test_csv_sanitizer_prepends_quote_to_dde_formulas(): void
    {
        $maliciousValues = [
            '=cmd|\' /C calc\'!A0',
            '@SUM(1+1)*cmd',
            '-2+3+cmd|\' /C calc\'!A0',
            '+12345',
            "\tHYPERLINK(\"http://evil.com\")",
            "\r=1+1",
            'Normal Text',
            12345,
            null,
        ];

        $sanitized = CsvSanitizer::sanitizeRow($maliciousValues);

        $this->assertEquals("'=cmd|' /C calc'!A0", $sanitized[0]);
        $this->assertEquals("'@SUM(1+1)*cmd", $sanitized[1]);
        $this->assertEquals("'-2+3+cmd|' /C calc'!A0", $sanitized[2]);
        $this->assertEquals("'+12345", $sanitized[3]);
        $this->assertEquals("'\tHYPERLINK(\"http://evil.com\")", $sanitized[4]);
        $this->assertEquals("'\r=1+1", $sanitized[5]);
        $this->assertEquals('Normal Text', $sanitized[6]);
        $this->assertEquals(12345, $sanitized[7]);
        $this->assertNull($sanitized[8]);
    }

    public function test_employee_export_neutralizes_formula_injection(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => '-2+3+cmd|\' /C calc\'!A0',
            'code' => 'DDE-DEPT',
        ]);

        $maliciousEmp = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => '+12345',
            'first_name' => '=cmd|\' /C calc\'!A0',
            'last_name' => '@SUM(1+1)*cmd',
            'employment_status' => 'active',
            'work_email' => '=bad@evil.com',
            'phone' => '+639171234567',
        ]);

        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->get('/api/employees/export?format=csv');
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        // Check that formula execution trigger characters are neutralized with leading quote
        $this->assertStringContainsString("'+12345", $content);
        $this->assertStringContainsString("'=cmd|' /C calc'!A0", $content);
        $this->assertStringContainsString("'@SUM(1+1)*cmd", $content);
        $this->assertStringContainsString("'-2+3+cmd|' /C calc'!A0", $content);
        $this->assertStringContainsString("'+639171234567", $content);

        // Verify unescaped dangerous formulas do NOT exist at start of CSV fields
        // In CSV format, the field will appear as "'=cmd|..." inside quotes or as field value
        $this->assertStringNotContainsString(',=cmd|', $content);
        $this->assertStringNotContainsString(',@SUM(', $content);
        $this->assertStringNotContainsString(',-2+3+', $content);
        $this->assertStringNotContainsString(',+12345', $content);
    }

    public function test_payroll_export_neutralizes_formula_injection(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => '-2+3+cmd|\' /C calc\'!A0',
            'code' => 'DDE-PAY',
        ]);

        $maliciousEmp = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => '+12345',
            'first_name' => '=cmd|\' /C calc\'!A0',
            'last_name' => '@SUM(1+1)*cmd',
            'employment_status' => 'active',
        ]);

        $month = Carbon::now()->month;
        $year = Carbon::now()->year;

        AttendanceRecord::create([
            'employee_id' => $maliciousEmp->id,
            'date' => Carbon::create($year, $month, 5)->toDateString(),
            'status' => 'present',
            'total_work_hours' => 8.0,
            'overtime_hours' => 0.0,
        ]);

        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->get("/api/payroll/export?month={$month}&year={$year}&format=csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();

        // Verify escaped fields
        $this->assertStringContainsString("'+12345", $content);
        $this->assertStringContainsString("'-2+3+cmd|' /C calc'!A0", $content);
        $this->assertStringContainsString("'=cmd|' /C calc'!A0 @SUM(1+1)*cmd", $content);

        // Verify no raw unescaped triggers
        $this->assertStringNotContainsString(',+12345', $content);
        $this->assertStringNotContainsString(',-2+3+', $content);
        $this->assertStringNotContainsString(',=cmd|', $content);
    }

    public function test_report_export_neutralizes_formula_injection(): void
    {
        $visitor = Visitor::create([
            'organization_id' => $this->org->id,
            'first_name' => '@SUM(1+1)*cmd',
            'last_name' => 'Attacker',
            'company' => '=cmd|\' /C calc\'!A0',
            'phone' => '+639000000000',
            'is_blocked' => false,
        ]);

        Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $this->managerEmployee->id,
            'purpose' => '-2+3+cmd|\' /C calc\'!A0',
            'status' => 'checked_in',
            'check_in_time' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->get('/api/reports/export?type=visitors&format=csv');
        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringContainsString("'@SUM(1+1)*cmd Attacker", $content);
        $this->assertStringContainsString("'=cmd|' /C calc'!A0", $content);
        $this->assertStringContainsString("'-2+3+cmd|' /C calc'!A0", $content);

        $this->assertStringNotContainsString(',@SUM(', $content);
        $this->assertStringNotContainsString(',=cmd|', $content);
    }

    // =========================================================================
    // SCENARIO 4: Password Concealment & Encryption
    // =========================================================================

    public function test_device_password_concealed_from_array_and_json(): void
    {
        $rawPass = 'HardwareCameraSecret#987!';
        $device = Device::create([
            'device_id' => 'CAM-SECURITY-401',
            'name' => 'Perimeter Gate Cam',
            'ip_address' => '192.168.1.188',
            'port' => 1883,
            'username' => 'admin',
            'password' => $rawPass,
            'is_active' => true,
        ]);

        // 1. Single model toArray()
        $deviceArray = $device->toArray();
        $this->assertArrayNotHasKey('password', $deviceArray, 'Single Device toArray() must conceal password');

        // 2. Single model toJson()
        $deviceJson = $device->toJson();
        $this->assertStringNotContainsString('password', $deviceJson, 'Single Device toJson() must conceal password');
        $this->assertStringNotContainsString($rawPass, $deviceJson);

        // 3. Collection toArray()
        $collectionArray = Device::where('id', $device->id)->get()->toArray();
        $this->assertArrayNotHasKey('password', $collectionArray[0], 'Device collection toArray() must conceal password');

        // 4. Collection toJson()
        $collectionJson = Device::where('id', $device->id)->get()->toJson();
        $this->assertStringNotContainsString('password', $collectionJson, 'Device collection toJson() must conceal password');
        $this->assertStringNotContainsString($rawPass, $collectionJson);

        // 5. REST API index endpoint
        Sanctum::actingAs($this->superAdmin, ['*']);
        $indexResponse = $this->getJson('/api/devices');
        $indexResponse->assertStatus(200);

        $deviceList = $indexResponse->json();
        $deviceItem = collect(is_array($deviceList) && isset($deviceList['data']) ? $deviceList['data'] : $deviceList)->firstWhere('id', $device->id);
        $this->assertNotNull($deviceItem);
        $this->assertArrayNotHasKey('password', $deviceItem, 'REST API /api/devices must never leak password');

        // 6. REST API show endpoint
        $showResponse = $this->getJson("/api/devices/{$device->id}");
        $showResponse->assertStatus(200);
        $this->assertArrayNotHasKey('password', $showResponse->json(), 'REST API /api/devices/{id} must never leak password');
    }

    public function test_device_password_is_encrypted_at_rest_in_database(): void
    {
        $plainPass = 'SuperSecureVaultPass#2026!';
        $device = Device::create([
            'device_id' => 'CAM-CIPHER-402',
            'name' => 'Cipher Camera',
            'ip_address' => '192.168.1.199',
            'port' => 1883,
            'username' => 'admin',
            'password' => $plainPass,
            'is_active' => true,
        ]);

        // Query raw database column directly bypassing Eloquent casts
        $rawDbPassword = DB::table('devices')->where('id', $device->id)->value('password');

        $this->assertNotNull($rawDbPassword, 'Raw database password must not be null');
        $this->assertNotEquals($plainPass, $rawDbPassword, 'Database must never store password in plaintext');

        // Verify ciphertext structure: Laravel Encrypter uses base64 payload containing iv, value, mac
        $decodedPayload = json_decode(base64_decode($rawDbPassword), true);
        $this->assertIsArray($decodedPayload, 'Ciphertext payload must be valid serialized JSON');
        $this->assertArrayHasKey('iv', $decodedPayload, 'Ciphertext must contain IV');
        $this->assertArrayHasKey('value', $decodedPayload, 'Ciphertext must contain encrypted value');
        $this->assertArrayHasKey('mac', $decodedPayload, 'Ciphertext must contain HMAC');

        // Verify successful decryption via Crypt facade
        $decrypted = Crypt::decryptString($rawDbPassword);
        $this->assertEquals($plainPass, $decrypted, 'Crypt::decryptString must cleanly decrypt stored ciphertext');

        // Verify Eloquent model transparently decrypts
        $this->assertEquals($plainPass, $device->fresh()->password, 'Eloquent model accessor must decrypt password');
    }

    // =========================================================================
    // SCENARIO 5: Report Streaming & Cursor Performance
    // =========================================================================

    public function test_monthly_attendance_report_uses_single_sql_aggregate_query(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Analytics Dept',
            'code' => 'ANA-01',
        ]);

        // Seed 10 active employees
        $employees = [];
        for ($i = 1; $i <= 10; $i++) {
            $employees[] = Employee::create([
                'organization_id' => $this->org->id,
                'department_id' => $dept->id,
                'employee_code' => sprintf('EMP-PERF-%02d', $i),
                'first_name' => "Emp{$i}",
                'last_name' => 'Tester',
                'employment_status' => 'active',
            ]);
        }

        $month = Carbon::now()->month;
        $year = Carbon::now()->year;

        // Seed 3 attendance records per employee (30 total records)
        foreach ($employees as $emp) {
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create($year, $month, 1)->toDateString(),
                'status' => 'present',
                'total_work_hours' => 8.0,
                'overtime_hours' => 1.0,
            ]);
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create($year, $month, 2)->toDateString(),
                'status' => 'late',
                'total_work_hours' => 7.5,
                'overtime_hours' => 0.0,
            ]);
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create($year, $month, 3)->toDateString(),
                'status' => 'absent',
                'total_work_hours' => 0.0,
                'overtime_hours' => 0.0,
            ]);
        }

        Sanctum::actingAs($this->superAdmin, ['*']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/reports/attendance/monthly?month={$month}&year={$year}");
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Find attendance records aggregate query
        $attendanceQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'attendance_records') && str_contains($q['query'], 'group by');
        });

        $this->assertCount(1, $attendanceQueries, 'Monthly attendance must execute exactly 1 aggregate query with GROUP BY employee_id (no N+1 loop)');

        // Verify aggregation data correctness
        $data = collect($response->json('data'));
        $firstEmpData = $data->firstWhere('employee_code', 'EMP-PERF-01');

        $this->assertNotNull($firstEmpData);
        $this->assertEquals(2, $firstEmpData['days_present']); // present + late
        $this->assertEquals(1, $firstEmpData['days_late']);
        $this->assertEquals(1, $firstEmpData['days_absent']);
        $this->assertEquals(15.5, $firstEmpData['total_work_hours']);
        $this->assertEquals(1.0, $firstEmpData['total_overtime_hours']);
    }

    public function test_payroll_export_uses_single_aggregate_and_cursor_streaming(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Payroll Dept',
            'code' => 'PAY-01',
        ]);

        $designation = Designation::create([
            'organization_id' => $this->org->id,
            'name' => 'Software Engineer',
            'code' => 'SWE',
        ]);

        // Seed 12 employees
        $employees = [];
        for ($i = 1; $i <= 12; $i++) {
            $employees[] = Employee::create([
                'organization_id' => $this->org->id,
                'department_id' => $dept->id,
                'designation_id' => $designation->id,
                'employee_code' => sprintf('PAY-EMP-%02d', $i),
                'first_name' => "Worker{$i}",
                'last_name' => 'Scale',
                'employment_status' => 'active',
            ]);
        }

        $month = Carbon::now()->month;
        $year = Carbon::now()->year;

        // Seed attendance records
        foreach ($employees as $emp) {
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create($year, $month, 5)->toDateString(),
                'status' => 'present',
                'total_work_hours' => 8.0,
                'overtime_hours' => 2.0,
            ]);
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create($year, $month, 6)->toDateString(),
                'status' => 'half_day',
                'total_work_hours' => 4.0,
                'overtime_hours' => 0.0,
            ]);
        }

        Sanctum::actingAs($this->superAdmin, ['*']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get("/api/payroll/export?month={$month}&year={$year}&format=csv");
        $response->assertStatus(200);
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);

        $content = $response->streamedContent();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 1. Verify single aggregate SQL query for attendance
        $attendanceQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'attendance_records') && str_contains($q['query'], 'group by');
        });
        $this->assertCount(1, $attendanceQueries, 'Payroll export must execute exactly 1 aggregate query for attendance records');

        // 2. Verify all 12 employees appear in streamed CSV
        for ($i = 1; $i <= 12; $i++) {
            $this->assertStringContainsString(sprintf('PAY-EMP-%02d', $i), $content);
            $this->assertStringContainsString("Worker{$i} Scale", $content);
        }

        // 3. Verify math calculations in CSV row:
        // present_days = 1, half_days = 1, leave_days = 0, absent_days = 0, payable_days = 1 + (1 * 0.5) = 1.5, hours = 12.0, ot = 2.0
        $this->assertStringContainsString('1,1,0,0,1.5,12,2', $content);
    }

    public function test_attendance_report_export_streams_via_cursor(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Operations',
            'code' => 'OPS-01',
        ]);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'employee_code' => 'OPS-001',
            'first_name' => 'Oliver',
            'last_name' => 'Operator',
            'employment_status' => 'active',
        ]);

        for ($d = 1; $d <= 15; $d++) {
            AttendanceRecord::create([
                'employee_id' => $emp->id,
                'date' => Carbon::create(2026, 10, $d)->toDateString(),
                'first_clock_in' => Carbon::create(2026, 10, $d, 8, 0, 0),
                'last_clock_out' => Carbon::create(2026, 10, $d, 17, 0, 0),
                'status' => 'present',
                'total_work_hours' => 9.0,
                'overtime_hours' => 1.0,
            ]);
        }

        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->get('/api/reports/export?type=attendance&format=csv');
        $response->assertStatus(200);
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);

        $content = $response->streamedContent();

        $this->assertStringContainsString('Date,"Employee Code","Employee Name"', $content);
        $this->assertStringContainsString('OPS-001,"Oliver Operator"', $content);
        $this->assertStringContainsString('2026-10-01', $content);
        $this->assertStringContainsString('2026-10-15', $content);
    }

    public function test_unassociated_user_cannot_submit_leave_or_regularization(): void
    {
        $orphanRole = Role::firstOrCreate(['slug' => 'contractor-user'], [
            'name' => 'Contractor User',
            'is_system' => false,
        ]);
        $orphanRole->givePermission('leaves.apply');
        $orphanRole->givePermission('selfservice.view');

        $orphanUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $orphanUser->roles()->sync([$orphanRole->id]);

        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'General Leave',
            'code' => 'GEN-01',
            'max_days_per_year' => 5,
        ]);

        Sanctum::actingAs($orphanUser, ['*']);

        // Leave submission without employee record
        $leaveResp = $this->postJson('/api/leave-requests', [
            'employee_id' => 99999,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-21',
        ]);
        $leaveResp->assertStatus(403);
        $leaveResp->assertJson(['message' => 'User is not associated with an active employee record.']);

        // Regularization submission without employee record
        $regResp = $this->postJson('/api/regularization-requests', [
            'employee_id' => 99999,
            'date' => '2026-09-20',
            'reason' => 'Testing orphan user',
        ]);
        $regResp->assertStatus(403);
        $regResp->assertJson(['message' => 'User is not associated with an active employee record.']);
    }

    public function test_cannot_reapprove_already_approved_leave_or_regularization(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Bereavement Leave',
            'code' => 'BL-01',
            'max_days_per_year' => 3,
        ]);

        LeaveBalance::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 3,
            'used' => 0,
            'pending' => 1,
        ]);

        $leave = LeaveRequest::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-25',
            'end_date' => '2026-10-25',
            'total_days' => 1,
            'status' => 'approved',
        ]);

        $regularization = RegularizationRequest::create([
            'employee_id' => $this->employee1->id,
            'date' => '2026-09-15',
            'status' => 'approved',
            'reason' => 'Already approved item',
        ]);

        Sanctum::actingAs($this->managerUser, ['*']);

        // Attempt re-approval on already approved leave
        $leaveResp = $this->putJson("/api/leave-requests/{$leave->id}/approve");
        $leaveResp->assertStatus(422);
        $leaveResp->assertJson(['message' => "Cannot approve leave request with status 'approved'."]);

        // Attempt re-approval on already approved regularization
        $regResp = $this->putJson("/api/regularization-requests/{$regularization->id}/approve");
        $regResp->assertStatus(422);
        $regResp->assertJson(['message' => "Cannot approve regularization with status 'approved'."]);
    }

    public function test_high_volume_stream_memory_stability_500_records(): void
    {
        $dept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'High Volume Ops',
            'code' => 'HV-01',
        ]);

        // Seed 25 employees with 20 records each = 500 records
        $employees = [];
        for ($e = 1; $e <= 25; $e++) {
            $employees[] = Employee::create([
                'organization_id' => $this->org->id,
                'department_id' => $dept->id,
                'employee_code' => sprintf('HV-EMP-%03d', $e),
                'first_name' => "Batch{$e}",
                'last_name' => 'Streaming',
                'employment_status' => 'active',
            ]);
        }

        $records = [];
        $now = Carbon::create(2026, 10, 1);
        foreach ($employees as $emp) {
            for ($d = 1; $d <= 20; $d++) {
                $records[] = [
                    'employee_id' => $emp->id,
                    'date' => $now->copy()->addDays($d - 1)->toDateString(),
                    'first_clock_in' => '08:00:00',
                    'last_clock_out' => '17:00:00',
                    'status' => 'present',
                    'total_work_hours' => 8.0,
                    'overtime_hours' => 0.5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        AttendanceRecord::insert($records);

        Sanctum::actingAs($this->superAdmin, ['*']);

        $memStart = memory_get_usage();

        $response = $this->get('/api/payroll/export?month=10&year=2026&format=csv');
        $response->assertStatus(200);

        $content = $response->streamedContent();

        $memEnd = memory_get_usage();
        $memDelta = ($memEnd - $memStart) / 1024 / 1024; // MB

        // Assert memory growth during stream generation is tightly bounded (< 15MB)
        $this->assertLessThan(15.0, $memDelta, "Streaming memory growth must remain under 15MB, measured: {$memDelta}MB");

        // Verify all 25 employees are in output
        for ($e = 1; $e <= 25; $e++) {
            $this->assertStringContainsString(sprintf('HV-EMP-%03d', $e), $content);
        }
    }
}
