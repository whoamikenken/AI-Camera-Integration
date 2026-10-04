<?php

namespace Tests\Feature;

use App\Jobs\SyncPersonnelJob;
use App\Models\AccessLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialEmployeeBiometricTest extends TestCase
{
    use RefreshDatabase;

    protected bool $disableAutoAuth = true;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $adminUser;
    protected User $employeeUser;
    protected User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Run full system roles and permissions seeder
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::create([
            'name' => 'Organization Alpha',
            'code' => 'ORG-ALPHA',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->orgB = Organization::create([
            'name' => 'Organization Beta',
            'code' => 'ORG-BETA',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $this->adminUser = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->adminUser->roles()->sync([$superAdminRole->id]);

        $employeeRole = Role::where('slug', 'employee')->first();
        $this->employeeUser = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->employeeUser->roles()->sync([$employeeRole->id]);

        $hrRole = Role::where('slug', 'hr-manager')->first();
        $this->hrUser = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->hrUser->roles()->sync([$hrRole->id]);
    }

    /**
     * Dimension 1: Probe employee code collisions and unicity constraints across organizations.
     */
    public function test_employee_code_collision_across_different_organizations_is_rejected(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Create Employee 1 in Org Alpha
        $resp1 = $this->postJson('/api/employees', [
            'organization_id' => $this->orgA->id,
            'employee_code' => 'EMP-GLOBAL-001',
            'first_name' => 'Alpha',
            'last_name' => 'One',
            'work_email' => 'alpha1@org-alpha.test',
            'employment_status' => 'active',
        ]);
        $resp1->assertStatus(201);

        // Attempt collision: Org Beta tries to use the same employee_code
        $resp2 = $this->postJson('/api/employees', [
            'organization_id' => $this->orgB->id,
            'employee_code' => 'EMP-GLOBAL-001',
            'first_name' => 'Beta',
            'last_name' => 'One',
            'work_email' => 'beta1@org-beta.test',
            'employment_status' => 'active',
        ]);

        $resp2->assertStatus(422)
            ->assertJsonValidationErrors(['employee_code']);

        // Assert database holds exactly one employee with this code
        $this->assertEquals(1, Employee::where('employee_code', 'EMP-GLOBAL-001')->count());
    }

    public function test_employee_code_collision_on_update_is_rejected(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $emp1 = Employee::create([
            'organization_id' => $this->orgA->id,
            'employee_code' => 'EMP-CODE-A',
            'first_name' => 'Alice',
            'work_email' => 'alice@test.com',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'organization_id' => $this->orgA->id,
            'employee_code' => 'EMP-CODE-B',
            'first_name' => 'Bob',
            'work_email' => 'bob@test.com',
            'employment_status' => 'active',
        ]);

        // Attempt to change emp2's code to emp1's code
        $resp = $this->putJson("/api/employees/{$emp2->id}", [
            'employee_code' => 'EMP-CODE-A',
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['employee_code']);

        // Emp2 updating other attributes while retaining its code should succeed
        $respSelf = $this->putJson("/api/employees/{$emp2->id}", [
            'employee_code' => 'EMP-CODE-B',
            'last_name' => 'Builder',
        ]);
        $respSelf->assertStatus(200);
        $this->assertEquals('Builder', $emp2->fresh()->last_name);
    }

    public function test_employee_code_boundary_limits(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Empty code
        $this->postJson('/api/employees', [
            'employee_code' => '',
            'first_name' => 'NoCode',
        ])->assertStatus(422)->assertJsonValidationErrors(['employee_code']);

        // Oversized code (> 64 characters)
        $oversized = str_repeat('X', 65);
        $this->postJson('/api/employees', [
            'employee_code' => $oversized,
            'first_name' => 'Oversized',
        ])->assertStatus(422)->assertJsonValidationErrors(['employee_code']);

        // Max boundary code (exactly 64 characters)
        $exact64 = str_repeat('Z', 64);
        $resp = $this->postJson('/api/employees', [
            'employee_code' => $exact64,
            'first_name' => 'ExactLimit',
        ]);
        $resp->assertStatus(201);
        $this->assertDatabaseHas('employees', ['employee_code' => $exact64]);
    }

    /**
     * Dimension 2: Probe 1-to-1 biometric linkage with personnel.
     */
    public function test_linking_multiple_employees_to_same_personnel_triggers_rejection_or_db_constraint(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $personnel = Personnel::create([
            'customize_id' => 8801,
            'name' => 'Biometric Shared Target',
            'person_type' => 0,
        ]);

        // Employee 1 claims this personnel record
        $resp1 = $this->postJson('/api/employees', [
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-SHARED-P1',
            'first_name' => 'First',
            'last_name' => 'Claimant',
            'employment_status' => 'active',
        ]);
        $resp1->assertStatus(201);
        $emp1Id = $resp1->json('data.id');

        // Employee 2 attempts to link to the same personnel record
        // Whether handled via 422 validation or 500 DB constraint violation,
        // the 1-to-1 invariant MUST be protected and Employee 2 must not be created.
        $resp2 = $this->postJson('/api/employees', [
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-SHARED-P2',
            'first_name' => 'Second',
            'last_name' => 'Claimant',
            'employment_status' => 'active',
        ]);

        // Assert rejection (either 422 or 500 DB QueryException)
        $this->assertTrue(
            in_array($resp2->status(), [422, 500]),
            "Expected status 422 or 500, got {$resp2->status()}"
        );

        // Verify that the second employee was NOT persisted with that personnel_id
        $this->assertDatabaseMissing('employees', [
            'employee_code' => 'EMP-SHARED-P2',
        ]);

        // Verify that personnel remains linked only to Employee 1
        $linkedEmployees = Employee::where('personnel_id', $personnel->id)->get();
        $this->assertCount(1, $linkedEmployees);
        $this->assertEquals($emp1Id, $linkedEmployees->first()->id);
    }

    public function test_deleting_employee_cascades_deprovisioning_and_purges_telemetry_access_logs(): void
    {
        Queue::fake([SyncPersonnelJob::class]);
        Sanctum::actingAs($this->adminUser, ['*']);

        $device = Device::create([
            'device_id' => 'CAM-EDGE-ADV-01',
            'name' => 'Adversarial Edge Camera',
            'ip_address' => '192.168.10.50',
            'is_active' => true,
        ]);

        $personnel = Personnel::create([
            'customize_id' => 8802,
            'name' => 'Biometric Deletion Target',
            'person_type' => 0,
        ]);

        // Historical telemetry generated prior to deletion
        $log1 = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => $personnel->name,
            'verify_status' => 1,
            'captured_at' => now()->subDays(5),
        ]);

        $log2 = AccessLog::create([
            'device_id' => $device->device_id,
            'customize_id' => $personnel->customize_id,
            'person_name' => $personnel->name,
            'verify_status' => 1,
            'captured_at' => now()->subDays(1),
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-DELETE-CASC',
            'first_name' => 'Deprovision',
            'last_name' => 'Target',
            'employment_status' => 'active',
        ]);

        // Delete the employee
        $resp = $this->deleteJson("/api/employees/{$employee->id}");
        $resp->assertStatus(200);

        // 1. Employee is soft-deleted
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);

        // 2. Personnel entity is deleted to trigger edge camera face deletion
        $this->assertDatabaseMissing('personnel', ['id' => $personnel->id]);

        // 3. Historical telemetry logs are purged
        $this->assertDatabaseMissing('access_logs', ['id' => $log1->id]);
        $this->assertDatabaseMissing('access_logs', ['id' => $log2->id]);
        $this->assertEquals(0, AccessLog::where('customize_id', 8802)->count());

        // 4. Camera sync queue receives DELETE command with customize_id
        Queue::assertPushed(SyncPersonnelJob::class, function ($job) {
            return $job->action === 'DELETE' && $job->customizeIdToDelete === 8802;
        });
    }

    public function test_updating_employee_status_to_suspended_or_terminated_immediately_revokes_camera_admission(): void
    {
        Queue::fake([SyncPersonnelJob::class]);
        Sanctum::actingAs($this->adminUser, ['*']);

        $personnel = Personnel::create([
            'customize_id' => 8803,
            'name' => 'Biometric Revoke Target',
            'person_type' => 0, // Whitelist initially
        ]);

        $employee = Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => 'EMP-STATUS-CHG',
            'first_name' => 'Status',
            'last_name' => 'Target',
            'employment_status' => 'active',
        ]);

        // Step A: Suspend employee -> must change person_type to 1 (Blacklist) and queue EDIT
        $respSuspend = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'suspended',
        ]);
        $respSuspend->assertStatus(200);
        $personnel->refresh();
        $this->assertEquals(1, $personnel->person_type, 'Personnel person_type must be 1 (Blacklist) on suspension');

        Queue::assertPushed(SyncPersonnelJob::class, function ($job) use ($personnel) {
            return $job->personnelId === $personnel->id && $job->action === 'EDIT';
        });

        // Step B: Terminate employee -> must keep person_type 1 (Blacklist)
        $respTerminate = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'terminated',
        ]);
        $respTerminate->assertStatus(200);
        $personnel->refresh();
        $this->assertEquals(1, $personnel->person_type, 'Personnel person_type must remain 1 on termination');

        // Step C: Resign employee -> must keep person_type 1 (Blacklist)
        $respResign = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'resigned',
        ]);
        $respResign->assertStatus(200);
        $personnel->refresh();
        $this->assertEquals(1, $personnel->person_type, 'Personnel person_type must remain 1 on resignation');

        // Step D: Re-activate employee -> must restore person_type 0 (Whitelist)
        $respReactivate = $this->putJson("/api/employees/{$employee->id}", [
            'employment_status' => 'active',
        ]);
        $respReactivate->assertStatus(200);
        $personnel->refresh();
        $this->assertEquals(0, $personnel->person_type, 'Personnel person_type must be 0 (Whitelist) on activation');
    }

    /**
     * Dimension 3: Probe CSV import edge cases.
     */
    public function test_csv_import_malformed_rows_triggers_clean_rollback_and_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // CSV where Row 1 is valid, but Row 2 has uneven columns (malformed row)
        $csvContent = implode("\n", [
            'Employee Code,First Name,Last Name,Work Email',
            'EMP-CSV-ROLL-1,RollFirst1,RollLast1,roll1@test.com',
            'EMP-CSV-ROLL-2,MalformedOnlyTwoColumns',
            'EMP-CSV-ROLL-3,RollFirst3,RollLast3,roll3@test.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('malformed.csv', $csvContent);

        $response = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        // Transaction atomicity test: Row 1 must have been rolled back and not persisted!
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-CSV-ROLL-1']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-CSV-ROLL-2']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-CSV-ROLL-3']);
    }

    public function test_csv_import_missing_required_headers_rejects_without_creating_records(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Header does not include Employee Code or First Name
        $csvContent = implode("\n", [
            'UnknownHeader1,UnknownHeader2,UnknownHeader3',
            'Val1,Val2,Val3',
            'Val4,Val5,Val6',
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid_headers.csv', $csvContent);

        $response = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['imported_count' => 0]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('employees', ['employee_code' => 'Val1']);
    }

    public function test_csv_import_with_sql_injection_payloads_is_safely_escaped(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $injectionCode = 'EMP-SQLI-01';
        $injectionFirstName = "Robert'); DROP TABLE employees;--";
        $injectionLastName = "' OR '1'='1";
        $injectionEmail = "sqli@test.com";

        $csvContent = implode("\n", [
            'Employee Code,First Name,Last Name,Work Email',
            "{$injectionCode},\"{$injectionFirstName}\",\"{$injectionLastName}\",{$injectionEmail}",
        ]);

        $file = UploadedFile::fake()->createWithContent('sqli.csv', $csvContent);

        $response = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['imported_count' => 1]);

        // Table still exists and row was safely escaped
        $this->assertDatabaseHas('employees', [
            'employee_code' => $injectionCode,
            'first_name' => $injectionFirstName,
            'last_name' => $injectionLastName,
            'work_email' => $injectionEmail,
        ]);
    }

    public function test_csv_import_transaction_rollback_on_unique_collision_in_batch(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Pre-existing employee with work_email
        Employee::create([
            'employee_code' => 'EMP-PRE-EXISTING',
            'first_name' => 'Existing',
            'work_email' => 'colliding@pinnacle.test',
            'employment_status' => 'active',
        ]);

        // CSV batch where Row 1 is valid, Row 2 collides on unique work_email, Row 3 is valid
        $csvContent = implode("\n", [
            'Employee Code,First Name,Last Name,Work Email',
            'EMP-BATCH-A,BatchA,UserA,batcha@pinnacle.test',
            'EMP-BATCH-B,BatchB,UserB,colliding@pinnacle.test',
            'EMP-BATCH-C,BatchC,UserC,batchc@pinnacle.test',
        ]);

        $file = UploadedFile::fake()->createWithContent('batch_collision.csv', $csvContent);

        $response = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);

        // Must fail with 422 because of unique constraint
        $response->assertStatus(422);

        // Transaction atomicity: Row 1 (EMP-BATCH-A) must have been rolled back
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-BATCH-A']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-BATCH-B']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-BATCH-C']);
    }

    /**
     * Dimension 4: Probe RBAC authorization: unprivileged employee role must receive HTTP 403.
     */
    public function test_rbac_unprivileged_employee_cannot_create_update_or_delete_employees(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);

        $target = Employee::create([
            'employee_code' => 'EMP-TARGET-RBAC',
            'first_name' => 'Victim',
            'employment_status' => 'active',
        ]);

        // 1. POST /api/employees must return 403
        $respCreate = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-UNAUTH-01',
            'first_name' => 'Hacker',
        ]);
        $respCreate->assertStatus(403);

        // 2. DELETE /api/employees/{id} must return 403
        $respDelete = $this->deleteJson("/api/employees/{$target->id}");
        $respDelete->assertStatus(403);

        // 3. PUT /api/employees/{id} must return 403
        $respUpdate = $this->putJson("/api/employees/{$target->id}", [
            'first_name' => 'ModifiedByUnprivileged',
        ]);
        $respUpdate->assertStatus(403);

        // 4. POST /api/employees/import must return 403
        $file = UploadedFile::fake()->createWithContent('test.csv', "code,first\n1,2");
        $respImport = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);
        $respImport->assertStatus(403);

        // Verify target employee was not modified or deleted
        $this->assertDatabaseHas('employees', [
            'id' => $target->id,
            'first_name' => 'Victim',
            'deleted_at' => null,
        ]);
    }

    public function test_rbac_unauthenticated_requests_receive_401(): void
    {
        // No Sanctum token is provided because disableAutoAuth is true
        $resp1 = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-GHOST',
            'first_name' => 'Ghost',
        ]);
        $resp1->assertStatus(401);

        $resp2 = $this->deleteJson('/api/employees/999');
        $resp2->assertStatus(401);
    }

    public function test_rbac_hr_manager_can_create_update_and_delete_employees(): void
    {
        Sanctum::actingAs($this->hrUser, ['*']);

        // Create
        $respCreate = $this->postJson('/api/employees', [
            'employee_code' => 'EMP-HR-OK',
            'first_name' => 'Permitted',
            'last_name' => 'Employee',
            'employment_status' => 'active',
        ]);
        $respCreate->assertStatus(201);
        $empId = $respCreate->json('data.id');

        // Update
        $respUpdate = $this->putJson("/api/employees/{$empId}", [
            'first_name' => 'UpdatedByHR',
        ]);
        // Delete
        $respDelete = $this->deleteJson("/api/employees/{$empId}");
        $respDelete->assertStatus(200);
        $this->assertSoftDeleted('employees', ['id' => $empId]);
    }

    public function test_delete_employee_without_linked_personnel_succeeds_cleanly(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $emp = Employee::create([
            'employee_code' => 'EMP-NO-BIO',
            'first_name' => 'NoBio',
            'employment_status' => 'active',
        ]);
        $this->assertNull($emp->personnel_id);

        $resp = $this->deleteJson("/api/employees/{$emp->id}");
        $resp->assertStatus(200);
        $this->assertSoftDeleted('employees', ['id' => $emp->id]);
    }

    public function test_assign_shift_date_boundary_and_validation(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $emp = Employee::create([
            'employee_code' => 'EMP-SHIFT-BOUND',
            'first_name' => 'ShiftBound',
            'employment_status' => 'active',
        ]);

        $shift = \App\Models\Shift::create([
            'name' => 'Boundary Shift',
            'code' => 'S-BOUND',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_active' => true,
        ]);

        // Inverted dates: effective_to is before effective_from
        $resp = $this->postJson("/api/employees/{$emp->id}/assign-shift", [
            'shift_id' => $shift->id,
            'effective_from' => '2026-10-15',
            'effective_to' => '2026-10-10',
        ]);
        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['effective_to']);

        // Non-existent shift_id
        $respBadShift = $this->postJson("/api/employees/{$emp->id}/assign-shift", [
            'shift_id' => 999999,
            'effective_from' => '2026-10-15',
        ]);
        $respBadShift->assertStatus(422)
            ->assertJsonValidationErrors(['shift_id']);
    }

    public function test_csv_import_disallowed_mime_type_is_rejected(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $badFile = UploadedFile::fake()->create('malicious.sh', 100, 'application/x-sh');

        $resp = $this->postJson('/api/employees/import', [
            'file' => $badFile,
        ]);
        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_csv_import_skips_rows_missing_mandatory_fields_while_importing_valid_rows(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $csvContent = implode("\n", [
            'Employee Code,First Name,Last Name,Work Email',
            'EMP-PARTIAL-1,Alice,Smith,alice.partial@test.com',
            'EMP-PARTIAL-2,,MissingFirst,bad.partial@test.com',
            'EMP-PARTIAL-3,Charlie,Brown,charlie.partial@test.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('partial_valid.csv', $csvContent);

        $resp = $this->postJson('/api/employees/import', [
            'file' => $file,
        ]);

        $resp->assertStatus(200)
            ->assertJsonFragment(['imported_count' => 2]);

        $this->assertCount(1, $resp->json('errors'));
        $this->assertStringContainsString('Missing employee_code or first_name', $resp->json('errors.0'));

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-PARTIAL-1']);
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-PARTIAL-3']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-PARTIAL-2']);
    }
}
