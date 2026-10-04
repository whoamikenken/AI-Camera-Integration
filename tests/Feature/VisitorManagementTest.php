<?php

namespace Tests\Feature;

use App\Jobs\SyncPersonnelJob;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Employee $hostEmployee;

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

        $this->hostEmployee = Employee::create([
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-HOST-01',
            'first_name' => 'David',
            'last_name' => 'Hostman',
            'employment_status' => 'active',
        ]);
    }

    public function test_visitor_crud_and_watchlist_blocking(): void
    {
        // 1. Create visitor
        $resp = $this->postJson('/api/visitors', [
            'first_name' => 'Bruce',
            'last_name' => 'Wayne',
            'company' => 'Wayne Enterprises',
            'id_type' => 'passport',
            'id_number' => 'PASS-WAYNE-007',
            'email' => 'bruce@wayne.com',
            'phone' => '+1-555-0100',
        ]);
        $resp->assertStatus(201);
        $visitorId = $resp->json('data.id');

        $this->assertDatabaseHas('visitors', ['id' => $visitorId, 'id_number' => 'PASS-WAYNE-007']);

        // 2. Block on watchlist
        $blockResp = $this->postJson("/api/visitors/{$visitorId}/block", [
            'is_blocked' => true,
            'block_reason' => 'Suspected vigilante activity',
        ]);
        $blockResp->assertStatus(200);
        $this->assertDatabaseHas('visitors', ['id' => $visitorId, 'is_blocked' => true]);

        // 3. Attempt to pre-register visit for blocked visitor -> 403 Forbidden
        $preResp = $this->postJson('/api/visits/pre-register', [
            'visitor_id' => $visitorId,
            'host_employee_id' => $this->hostEmployee->id,
            'purpose' => 'meeting',
        ]);
        $preResp->assertStatus(403);
    }

    public function test_visit_full_lifecycle_checkin_and_checkout_with_face_sync(): void
    {
        Queue::fake([SyncPersonnelJob::class]);

        $visitor = Visitor::create([
            'first_name' => 'Clark',
            'last_name' => 'Kent',
            'company' => 'Daily Planet',
            'id_type' => 'press_card',
            'id_number' => 'PRESS-991',
        ]);

        // 1. Pre-register
        $preResp = $this->postJson('/api/visits/pre-register', [
            'visitor_id' => $visitor->id,
            'host_employee_id' => $this->hostEmployee->id,
            'purpose' => 'interview',
            'purpose_detail' => 'Feature story on AI Camera integration',
            'expected_arrival' => Carbon::tomorrow()->setHour(10)->format('Y-m-d H:i:s'),
        ]);
        $preResp->assertStatus(201);
        $visitId = $preResp->json('data.id');

        $this->assertDatabaseHas('visits', ['id' => $visitId, 'status' => 'expected']);

        // 2. Check In
        $checkInResp = $this->putJson("/api/visits/{$visitId}/check-in", [
            'badge_number' => 'BADGE-PRESS-01',
            'nda_signed' => true,
        ]);
        $checkInResp->assertStatus(200);

        $visit = Visit::find($visitId);
        $this->assertEquals('checked_in', $visit->status);
        $this->assertNotNull($visit->personnel_id);

        // Verify SyncPersonnelJob dispatched for ADD
        Queue::assertPushed(SyncPersonnelJob::class, function ($job) use ($visit) {
            return $job->personnelId === $visit->personnel_id && $job->action === 'ADD';
        });

        // 3. Check Out
        $checkOutResp = $this->putJson("/api/visits/{$visitId}/check-out");
        $checkOutResp->assertStatus(200);

        $visit->refresh();
        $this->assertEquals('checked_out', $visit->status);
        $this->assertNull($visit->personnel_id);

        // 4. Second check out should fail with 422
        $secondOut = $this->putJson("/api/visits/{$visitId}/check-out");
        $secondOut->assertStatus(422);
    }
}
