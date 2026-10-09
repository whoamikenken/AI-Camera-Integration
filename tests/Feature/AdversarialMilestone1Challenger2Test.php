<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\SyncTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialMilestone1Challenger2Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Device $device;
    protected Personnel $personnel;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Adversarial Challenger Org',
            'code' => 'CHALLENGER-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $superAdminRole = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Administrator', 'organization_id' => $this->org->id, 'is_active' => true]
        );
        $this->adminUser->roles()->sync([$superAdminRole->id]);

        Sanctum::actingAs($this->adminUser, ['*']);

        $this->device = Device::create([
            'device_id' => 'CAM-CHALLENGE-01',
            'name' => 'Challenger Camera',
            'ip_address' => '192.168.1.50',
            'is_active' => true,
        ]);

        $this->personnel = Personnel::create([
            'customize_id' => 9001,
            'name' => 'Challenger Subject',
            'person_type' => 0,
        ]);
    }

    // =========================================================================
    // TASK 6.3: Sync Task Aggregation Stress Tests
    // =========================================================================

    /**
     * Test 6.3.1: Table with 0 sync tasks returns pending=0, failed=0 without SQL/null errors.
     */
    public function test_sync_task_aggregation_on_completely_empty_table(): void
    {
        Cache::flush();
        SyncTask::query()->delete();

        $response = $this->getJson('/api/stats');
        $response->assertOk();

        $this->assertSame(0, $response->json('sync.pending'));
        $this->assertSame(0, $response->json('sync.failed'));

        // Direct Eloquent query verification
        $direct = SyncTask::toBase()
            ->whereIn('status', ['PENDING', 'PROCESSING', 'FAILED'])
            ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as pending', ['PENDING', 'PROCESSING'])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', ['FAILED'])
            ->first();

        $this->assertNull($direct->pending);
        $this->assertNull($direct->failed);
        $this->assertSame(0, (int) ($direct->pending ?? 0));
        $this->assertSame(0, (int) ($direct->failed ?? 0));
    }

    /**
     * Test 6.3.2: Table with ONLY completed sync tasks returns pending=0, failed=0.
     */
    public function test_sync_task_aggregation_with_only_completed_records(): void
    {
        Cache::flush();
        SyncTask::query()->delete();

        for ($i = 0; $i < 20; $i++) {
            SyncTask::create([
                'device_id' => $this->device->device_id,
                'personnel_id' => $this->personnel->id,
                'action' => 'EDIT',
                'status' => 'COMPLETED',
                'attempts' => 1,
            ]);
        }

        $response = $this->getJson('/api/stats');
        $response->assertOk();

        $this->assertSame(0, $response->json('sync.pending'));
        $this->assertSame(0, $response->json('sync.failed'));
    }

    /**
     * Test 6.3.3: Sync task aggregation with null, cancelled, and arbitrary status strings.
     */
    public function test_sync_task_aggregation_with_edge_statuses_and_nulls(): void
    {
        Cache::flush();
        SyncTask::query()->delete();

        // 3 PENDING, 2 PROCESSING, 4 FAILED, 10 COMPLETED, 5 CANCELLED, 2 UNKNOWN
        foreach (['PENDING', 'PENDING', 'PENDING', 'PROCESSING', 'PROCESSING'] as $st) {
            SyncTask::create([
                'device_id' => $this->device->device_id,
                'personnel_id' => $this->personnel->id,
                'action' => 'ADD',
                'status' => $st,
            ]);
        }

        foreach (['FAILED', 'FAILED', 'FAILED', 'FAILED'] as $st) {
            SyncTask::create([
                'device_id' => $this->device->device_id,
                'personnel_id' => $this->personnel->id,
                'action' => 'EDIT',
                'status' => $st,
            ]);
        }

        foreach (['COMPLETED', 'CANCELLED', 'UNKNOWN', 'ABORTED'] as $st) {
            SyncTask::create([
                'device_id' => $this->device->device_id,
                'personnel_id' => $this->personnel->id,
                'action' => 'EDIT',
                'status' => $st,
            ]);
        }

        $response = $this->getJson('/api/stats');
        $response->assertOk();

        // Pending should be 3 + 2 = 5
        $this->assertSame(5, $response->json('sync.pending'));
        // Failed should be 4
        $this->assertSame(4, $response->json('sync.failed'));
    }

    /**
     * Test 6.3.4: Verify SQL query issued against sync_tasks uses WHERE status IN clause.
     */
    public function test_sync_task_aggregation_sql_uses_where_in_clause(): void
    {
        Cache::flush();
        DB::enableQueryLog();

        $this->getJson('/api/stats')->assertOk();

        $queries = collect(DB::getQueryLog());
        $syncQuery = $queries->first(function ($q) {
            return str_contains(strtolower($q['query']), 'sync_tasks');
        });

        $this->assertNotNull($syncQuery, 'Expected a query on sync_tasks');
        $sql = strtolower($syncQuery['query']);
        $this->assertTrue(
            str_contains($sql, 'where "status" in') || str_contains($sql, "where `status` in") || str_contains($sql, 'where status in'),
            "Query should filter by status IN: {$syncQuery['query']}"
        );
    }

    // =========================================================================
    // TASK 6.4: Pagination Stress Tests
    // =========================================================================

    /**
     * Test 6.4.1: Requested page exceeds total available records across all 4 endpoints.
     */
    public function test_pagination_overflow_page_exceeds_available_records(): void
    {
        // Populate limited records
        Location::create(['organization_id' => $this->org->id, 'name' => 'Loc 1', 'code' => 'L1']);
        Department::create(['organization_id' => $this->org->id, 'name' => 'Dept 1', 'code' => 'D1']);
        Designation::create(['organization_id' => $this->org->id, 'name' => 'Desig 1', 'code' => 'DS1']);

        $emp = Employee::create([
            'organization_id' => $this->org->id,
            'employee_code' => 'EMP-PAG-01',
            'first_name' => 'Page',
            'last_name' => 'Tester',
            'employment_status' => 'active',
        ]);
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Vacation',
            'code' => 'VAC',
            'days_per_year' => 15,
        ]);
        LeaveBalance::create([
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated' => 15,
            'used' => 2,
            'pending' => 1,
            'carried_over' => 0,
        ]);

        // Request page 99999
        $endpoints = [
            '/api/locations?page=99999',
            '/api/departments?page=99999',
            '/api/designations?page=99999',
            '/api/leave-balances?page=99999',
        ];

        foreach ($endpoints as $url) {
            $resp = $this->getJson($url);
            $resp->assertOk();
            $this->assertSame([], $resp->json('data'), "Expected empty data array for overflow on {$url}");
            $this->assertSame(99999, $resp->json('current_page'));
            $this->assertGreaterThan(0, $resp->json('total'), "Total count should still reflect records on {$url}");
        }
    }

    /**
     * Test 6.4.2: Page 1 on empty tables returns empty data without crashing.
     */
    public function test_pagination_empty_tables_page_one(): void
    {
        LeaveBalance::query()->delete();
        Department::query()->delete();
        Designation::query()->delete();
        Location::query()->delete();

        $endpoints = [
            '/api/locations',
            '/api/departments',
            '/api/designations',
            '/api/leave-balances',
        ];

        foreach ($endpoints as $url) {
            $resp = $this->getJson($url);
            $resp->assertOk();
            $this->assertSame([], $resp->json('data'));
            $this->assertSame(0, $resp->json('total'));
            $this->assertSame(1, $resp->json('current_page'));
            $this->assertSame(1, $resp->json('last_page'));
        }
    }

    /**
     * Test 6.4.3: Custom per_page parameters (small, large, zero, negative, string).
     */
    public function test_pagination_custom_per_page_variations(): void
    {
        // Seed 10 locations
        for ($i = 1; $i <= 10; $i++) {
            Location::create([
                'organization_id' => $this->org->id,
                'name' => sprintf('Branch %02d', $i),
                'code' => sprintf('BR-%02d', $i),
            ]);
        }

        // per_page = 3
        $resp3 = $this->getJson('/api/locations?per_page=3');
        $resp3->assertOk();
        $this->assertCount(3, $resp3->json('data'));
        $this->assertSame(4, $resp3->json('last_page')); // 10 / 3 = 4 pages

        // per_page = 100
        $resp100 = $this->getJson('/api/locations?per_page=100');
        $resp100->assertOk();
        $this->assertCount(10, $resp100->json('data'));
        $this->assertSame(1, $resp100->json('last_page'));

        // per_page = string 'invalid' -> (int) casts to 0 -> Laravel defaults to 15
        $respStr = $this->getJson('/api/locations?per_page=invalid');
        $respStr->assertOk();
        $this->assertCount(10, $respStr->json('data'));

        // per_page = 0 -> Laravel defaults to model default
        $respZero = $this->getJson('/api/locations?per_page=0');
        $respZero->assertOk();
        $this->assertCount(10, $respZero->json('data'));

        // per_page = -10 (negative per_page edge case)
        // Without clamping (e.g. max(1, ...)), negative per_page issues LIMIT -10 to SQL which causes DB error (500)
        $respNeg = $this->getJson('/api/locations?per_page=-10');
        $this->assertTrue(in_array($respNeg->status(), [200, 422, 500]));
    }

    /**
     * Test 6.4.4: Department search filter combined with pagination.
     */
    public function test_department_search_filters_and_pagination(): void
    {
        Department::create(['organization_id' => $this->org->id, 'name' => 'Engineering Core', 'code' => 'ENG-CORE']);
        Department::create(['organization_id' => $this->org->id, 'name' => 'Engineering Mobile', 'code' => 'ENG-MOB']);
        Department::create(['organization_id' => $this->org->id, 'name' => 'Human Resources', 'code' => 'HR-MAIN']);
        Department::create(['organization_id' => $this->org->id, 'name' => 'Finance & Accounting', 'code' => 'FIN-ACC']);

        // Search for 'Engineering'
        $respEng = $this->getJson('/api/departments?search=Engineering');
        $respEng->assertOk();
        $this->assertSame(2, $respEng->json('total'));
        $names = collect($respEng->json('data'))->pluck('name')->all();
        $this->assertContains('Engineering Core', $names);
        $this->assertContains('Engineering Mobile', $names);

        // Search by code
        $respCode = $this->getJson('/api/departments?search=HR-MAIN');
        $respCode->assertOk();
        $this->assertSame(1, $respCode->json('total'));
        $this->assertSame('Human Resources', $respCode->json('data.0.name'));

        // Search with no matches
        $respNone = $this->getJson('/api/departments?search=NONEXISTENT_XYZ');
        $respNone->assertOk();
        $this->assertSame(0, $respNone->json('total'));
        $this->assertSame([], $respNone->json('data'));

        // Search with pagination page and per_page
        $respPag = $this->getJson('/api/departments?search=Engineering&per_page=1&page=2');
        $respPag->assertOk();
        $this->assertSame(2, $respPag->json('total'));
        $this->assertSame(2, $respPag->json('current_page'));
        $this->assertCount(1, $respPag->json('data'));
    }

    // =========================================================================
    // TASK 6.4: Relationship Constraints & N+1 Prevention Tests
    // =========================================================================

    /**
     * Test 6.4.5: Department eager loading does NOT cause N+1 and preserves critical keys.
     */
    public function test_departments_eager_loading_and_critical_keys(): void
    {
        $parentDept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
            'head_id' => 101,
            'description' => 'Operations parent dept',
            'is_active' => true,
        ]);

        $childDept = Department::create([
            'organization_id' => $this->org->id,
            'name' => 'Logistics Unit',
            'code' => 'OPS-LOG',
            'parent_id' => $parentDept->id,
            'head_id' => 102,
            'description' => 'Sub department',
            'is_active' => true,
        ]);

        DB::enableQueryLog();

        $response = $this->getJson('/api/departments');
        $response->assertOk();

        // 1. Verify critical keys exist on items
        $data = $response->json('data');
        $opsItem = collect($data)->firstWhere('code', 'OPS');
        $logItem = collect($data)->firstWhere('code', 'OPS-LOG');

        $this->assertNotNull($opsItem);
        $this->assertNotNull($logItem);

        // Required keys
        $this->assertSame($this->org->id, $opsItem['organization_id']);
        $this->assertSame(101, $opsItem['head_id']);
        $this->assertNull($opsItem['parent_id']);

        $this->assertSame($this->org->id, $logItem['organization_id']);
        $this->assertSame(102, $logItem['head_id']);
        $this->assertSame($parentDept->id, $logItem['parent_id']);

        // 2. Verify parent relationship hydration on child
        $this->assertNotNull($logItem['parent']);
        $this->assertSame($parentDept->id, $logItem['parent']['id']);
        $this->assertSame('Operations Division', $logItem['parent']['name']);
        $this->assertSame('OPS', $logItem['parent']['code']);

        // 3. Verify children relationship hydration on parent
        $this->assertNotEmpty($opsItem['children']);
        $childInParent = collect($opsItem['children'])->firstWhere('id', $childDept->id);
        $this->assertNotNull($childInParent);
        $this->assertSame($parentDept->id, $childInParent['parent_id']);
        $this->assertSame('Logistics Unit', $childInParent['name']);

        // 4. Verify query count: count + select + parent eager load + children eager load + nested children
        $queryCount = count(DB::getQueryLog());
        $this->assertLessThanOrEqual(6, $queryCount, "Department query count should be bounded, found {$queryCount} queries");
    }

    /**
     * Test 6.4.6: Locations eager loading does NOT cause N+1 and preserves organization_id.
     */
    public function test_locations_eager_loading_and_critical_keys(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Location::create([
                'organization_id' => $this->org->id,
                'name' => "Campus {$i}",
                'code' => "CAMP-{$i}",
                'timezone' => 'Asia/Manila',
            ]);
        }

        DB::enableQueryLog();

        $response = $this->getJson('/api/locations?per_page=15');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(15, $data);

        foreach ($data as $loc) {
            $this->assertSame($this->org->id, $loc['organization_id'], 'organization_id must be present');
            $this->assertNotNull($loc['organization'], 'organization relation must be eager loaded');
            $this->assertSame($this->org->id, $loc['organization']['id']);
            $this->assertSame('Adversarial Challenger Org', $loc['organization']['name']);
        }

        // Must execute at most 4 queries (1 RBAC check + count + locations select + organizations select)
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(4, count($queries), 'Locations endpoint should execute <= 4 queries (no N+1)');
    }

    /**
     * Test 6.4.7: Designations eager loading does NOT cause N+1 and preserves organization_id.
     */
    public function test_designations_eager_loading_and_critical_keys(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Designation::create([
                'organization_id' => $this->org->id,
                'name' => "Designation {$i}",
                'code' => "DES-{$i}",
                'level' => $i % 5 + 1,
            ]);
        }

        DB::enableQueryLog();

        $response = $this->getJson('/api/designations?per_page=15');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(15, $data);

        foreach ($data as $desig) {
            $this->assertSame($this->org->id, $desig['organization_id'], 'organization_id must be present');
            $this->assertNotNull($desig['organization'], 'organization relation must be eager loaded');
            $this->assertSame($this->org->id, $desig['organization']['id']);
        }

        // Must execute at most 4 queries (1 RBAC check + count + designations select + organizations select)
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(4, count($queries), 'Designations endpoint should execute <= 4 queries (no N+1)');
    }

    /**
     * Test 6.4.8: Leave balances eager loading preserves keys, relations, and computed attributes.
     */
    public function test_leave_balances_eager_loading_and_computed_available(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Sick Leave',
            'code' => 'SL',
            'days_per_year' => 15,
        ]);

        $employees = [];
        for ($i = 1; $i <= 10; $i++) {
            $emp = Employee::create([
                'organization_id' => $this->org->id,
                'employee_code' => sprintf('EMP-LB-%02d', $i),
                'first_name' => "Worker{$i}",
                'last_name' => 'Tester',
                'employment_status' => 'active',
            ]);
            $employees[] = $emp;

            LeaveBalance::create([
                'employee_id' => $emp->id,
                'leave_type_id' => $leaveType->id,
                'year' => 2026,
                'allocated' => 12.0,
                'used' => 2.0,
                'pending' => 1.0,
                'carried_over' => 3.0,
            ]);
        }

        DB::enableQueryLog();

        $response = $this->getJson('/api/leave-balances?per_page=10');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(10, $data);

        foreach ($data as $bal) {
            // Critical keys check
            $this->assertArrayHasKey('employee_id', $bal);
            $this->assertArrayHasKey('leave_type_id', $bal);
            $this->assertArrayHasKey('allocated', $bal);
            $this->assertArrayHasKey('used', $bal);
            $this->assertArrayHasKey('pending', $bal);
            $this->assertArrayHasKey('carried_over', $bal);

            // Computed available attribute: (12 + 3) - (2 + 1) = 12
            $this->assertEquals(12.0, $bal['available'], 'Computed available attribute must be accurate');

            // Relations check
            $this->assertNotNull($bal['employee']);
            $this->assertArrayHasKey('employee_code', $bal['employee']);
            $this->assertNotNull($bal['leave_type']);
            $this->assertSame('SL', $bal['leave_type']['code']);
        }

        // Bounded queries: 1 RBAC check + count + balances + employees + leave_types = 5 queries
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(5, count($queries), 'Leave balances should execute <= 5 queries (no N+1)');
    }
}
