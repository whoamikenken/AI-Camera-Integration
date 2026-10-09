<?php

namespace Tests\Feature;

use App\Http\Responses\ApiResponse;
use App\Models\AccessGroup;
use App\Models\AttendancePunch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Feature\E2E\E2ETestCase;

/**
 * Empirical Adversarial Challenge Test Suite for Milestone M6:
 * 1. ApiResponse envelope: paginators retain root keys while wrapping data, models retain root attributes, errors retain root errors.
 * 2. Hardware webhook endpoints: /Subscribe/* and /api/Subscribe/* strictly return raw Protocol V1.13 without envelope.
 * 3. Form Requests: reject invalid payloads with 422, pass valid payloads cleanly.
 */
class AdversarialMilestone6ChallengerTest extends E2ETestCase
{
    // =========================================================================
    // SECTION 1: ApiResponse Envelope Empirical Challenges
    // =========================================================================

    public function test_api_response_paginator_retains_root_keys_and_wraps_data_and_pagination_meta(): void
    {
        $items = collect([
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2'],
            ['id' => 3, 'name' => 'Item 3'],
        ]);

        $paginator = new LengthAwarePaginator(
            $items,
            25, // total
            5,  // per page
            2,  // current page
            ['path' => 'http://localhost/api/test']
        );

        $response = ApiResponse::paginated($paginator, 'Custom pagination message');
        $this->assertEquals(200, $response->getStatusCode());

        $payload = $response->getData(true);

        // 1. Root-level envelope fields
        $this->assertTrue($payload['success'], 'Root success must be true');
        $this->assertEquals('Custom pagination message', $payload['message']);
        $this->assertIsArray($payload['data'], 'Data must be an array');
        $this->assertCount(3, $payload['data'], 'Data must contain the 3 items');
        $this->assertArrayHasKey('meta', $payload, 'Meta must be present at root');

        // 2. Dual-compatibility: root-level pagination keys
        $this->assertEquals(2, $payload['current_page'], 'Root current_page must match paginator');
        $this->assertEquals(5, $payload['per_page'], 'Root per_page must match paginator');
        $this->assertEquals(25, $payload['total'], 'Root total must match paginator');
        $this->assertEquals(5, $payload['last_page'], 'Root last_page must match paginator');
        $this->assertEquals(6, $payload['from'], 'Root from must match paginator');
        $this->assertEquals(8, $payload['to'], 'Root to must match paginator');

        // 3. Meta pagination structure
        $this->assertArrayHasKey('pagination', $payload['meta']);
        $this->assertEquals(2, $payload['meta']['pagination']['current_page']);
        $this->assertEquals(5, $payload['meta']['pagination']['last_page']);
        $this->assertEquals(5, $payload['meta']['pagination']['per_page']);
        $this->assertEquals(25, $payload['meta']['pagination']['total']);
        $this->assertEquals(6, $payload['meta']['pagination']['from']);
        $this->assertEquals(8, $payload['meta']['pagination']['to']);
        $this->assertEquals('v1', $payload['meta']['version']);
        $this->assertNotEmpty($payload['meta']['timestamp']);
    }

    public function test_api_response_empty_paginator_handles_zero_items_cleanly(): void
    {
        $paginator = new LengthAwarePaginator(
            collect([]),
            0,
            15,
            1,
            ['path' => 'http://localhost/api/test']
        );

        $response = ApiResponse::paginated($paginator);
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertEquals(0, $payload['total']);
        $this->assertEquals(0, $payload['meta']['pagination']['total']);
        $this->assertEmpty($payload['data']);
        $this->assertNull($payload['from']);
        $this->assertNull($payload['to']);
    }

    public function test_api_response_success_delegates_to_paginated_when_given_paginator(): void
    {
        $paginator = new LengthAwarePaginator(
            collect([['id' => 10]]),
            1,
            10,
            1,
            ['path' => 'http://localhost/api/test']
        );

        $response = ApiResponse::success($paginator, 'Delegated pagination');
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertEquals('Delegated pagination', $payload['message']);
        $this->assertEquals(1, $payload['total']);
        $this->assertArrayHasKey('pagination', $payload['meta']);
    }

    public function test_api_response_model_retains_root_attributes_for_backward_compatibility(): void
    {
        $device = $this->createTestDevice([
            'device_id' => 'CAM-MODEL-TEST',
            'name' => 'Entrance Gate Model',
            'ip_address' => '10.0.0.50',
            'port' => 1883,
        ]);

        $response = ApiResponse::success($device, 'Device retrieved');
        $payload = $response->getData(true);

        // Envelope keys
        $this->assertTrue($payload['success']);
        $this->assertEquals('Device retrieved', $payload['message']);
        $this->assertIsArray($payload['data']);
        $this->assertEquals('CAM-MODEL-TEST', $payload['data']['device_id']);
        $this->assertArrayHasKey('meta', $payload);

        // Backward compatibility: Root-level attributes
        $this->assertEquals($device->id, $payload['id']);
        $this->assertEquals('CAM-MODEL-TEST', $payload['device_id']);
        $this->assertEquals('Entrance Gate Model', $payload['name']);
        $this->assertEquals('10.0.0.50', $payload['ip_address']);
        $this->assertEquals(1883, $payload['port']);

        // Reserved envelope keys are preserved
        $this->assertIsBool($payload['success']);
        $this->assertIsString($payload['message']);
        $this->assertIsArray($payload['meta']);
    }

    public function test_api_response_prevents_double_data_nesting(): void
    {
        // When user passes ['data' => ['nested' => 'val']]
        $nestedData = ['data' => ['key' => 'unwrapped']];
        $response = ApiResponse::success($nestedData);
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertEquals(['key' => 'unwrapped'], $payload['data']);
        $this->assertEquals('unwrapped', $payload['key']);
    }

    public function test_api_response_collection_is_not_unpacked_to_root_indices(): void
    {
        $items = collect([
            ['id' => 101, 'name' => 'First'],
            ['id' => 102, 'name' => 'Second'],
        ]);

        $response = ApiResponse::success($items, 'List retrieved');
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertEquals('List retrieved', $payload['message']);
        $this->assertCount(2, $payload['data']);
        $this->assertArrayNotHasKey(0, $payload, 'Numeric index 0 must not be hoisted to root');
        $this->assertArrayNotHasKey(1, $payload, 'Numeric index 1 must not be hoisted to root');
    }

    public function test_api_response_error_retains_root_errors_for_validation_assertions(): void
    {
        $validationErrors = [
            'email' => ['The email field is required.'],
            'device_id' => ['The device_id has already been taken.'],
        ];

        $response = ApiResponse::error('Validation Failed', 422, $validationErrors);
        $this->assertEquals(422, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertEquals('Validation Failed', $payload['message']);
        $this->assertEquals(422, $payload['code']);
        $this->assertArrayHasKey('errors', $payload);
        $this->assertEquals($validationErrors, $payload['errors']);
        $this->assertEquals('v1', $payload['meta']['version']);
    }

    public function test_api_response_error_without_errors_omits_errors_key(): void
    {
        $response = ApiResponse::error('Resource Not Found', 404);
        $this->assertEquals(404, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertEquals(404, $payload['code']);
        $this->assertArrayNotHasKey('errors', $payload);
    }

    public function test_api_response_created_message_and_nocontent_helpers(): void
    {
        $created = ApiResponse::created(['id' => 99, 'title' => 'New']);
        $this->assertEquals(201, $created->getStatusCode());
        $createdPayload = $created->getData(true);
        $this->assertTrue($createdPayload['success']);
        $this->assertEquals(99, $createdPayload['id']);
        $this->assertEquals(99, $createdPayload['data']['id']);

        $msg = ApiResponse::message('Operation queued');
        $this->assertEquals(200, $msg->getStatusCode());
        $msgPayload = $msg->getData(true);
        $this->assertTrue($msgPayload['success']);
        $this->assertEquals('Operation queued', $msgPayload['message']);
        $this->assertNull($msgPayload['data']);

        $noContent = ApiResponse::noContent();
        $this->assertEquals(204, $noContent->getStatusCode());
        $this->assertNull($noContent->getData());
    }

    // =========================================================================
    // SECTION 2: Hardware Webhook Endpoints Strict Protocol V1.13 Exemption
    // =========================================================================

    public function test_webhook_heartbeat_strictly_returns_raw_protocol_v113_on_both_routes(): void
    {
        // 1. POST /Subscribe/heartbeat
        $respWeb = $this->postJson('/Subscribe/heartbeat', [
            'operator' => 'HeartBeat',
            'info' => [
                'facesluiceId' => 'CAM-HW-HB-01',
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $respWeb->assertStatus(200);
        $dataWeb = $respWeb->json();
        $this->assertEquals(200, $dataWeb['code']);
        $this->assertEquals('OK', $dataWeb['desc']);
        $this->assertEquals('Ok', $dataWeb['info']['Result']);
        $this->assertArrayNotHasKey('success', $dataWeb, 'Raw protocol must NOT contain envelope key: success');
        $this->assertArrayNotHasKey('meta', $dataWeb, 'Raw protocol must NOT contain envelope key: meta');
        $this->assertArrayNotHasKey('data', $dataWeb, 'Raw protocol must NOT contain envelope key: data');

        // 2. POST /api/Subscribe/heartbeat
        $respApi = $this->postJson('/api/Subscribe/heartbeat', [
            'operator' => 'HeartBeat',
            'info' => [
                'facesluiceId' => 'CAM-HW-HB-02',
                'time' => now()->format('Y-m-d H:i:s'),
            ],
        ]);

        $respApi->assertStatus(200);
        $dataApi = $respApi->json();
        $this->assertEquals(200, $dataApi['code']);
        $this->assertEquals('OK', $dataApi['desc']);
        $this->assertArrayNotHasKey('success', $dataApi);
        $this->assertArrayNotHasKey('meta', $dataApi);
    }

    public function test_webhook_verify_strictly_returns_raw_protocol_v113_for_success_and_dedup(): void
    {
        $device = $this->createTestDevice([
            'device_id' => 'CAM-HW-VERIFY',
            'is_active' => true,
        ]);

        $payload = [
            'SanpPic' => 'data:image/jpeg;base64,' . base64_encode('fake-snap-bytes'),
            'info' => [
                'DeviceID' => $device->device_id,
                'RecordID' => 998811,
                'PersonID' => 55,
                'Name' => 'Biometric Challenger',
                'VerifyStatus' => 1,
                'Similarity1' => 92.5,
                'CreateTime' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        // First verification push -> 200 OK
        $resp = $this->postJson('/Subscribe/Verify', $payload);
        $resp->assertStatus(200);
        $data = $resp->json();
        $this->assertEquals(200, $data['code']);
        $this->assertEquals('OK', $data['desc']);
        $this->assertEquals('Ok', $data['info']['Result']);
        $this->assertArrayNotHasKey('success', $data);
        $this->assertArrayNotHasKey('meta', $data);

        // Immediate duplicate push -> 200 OK (Duplicate ignored)
        $respDup = $this->postJson('/api/Subscribe/Verify', $payload);
        $respDup->assertStatus(200);
        $dataDup = $respDup->json();
        $this->assertEquals(200, $dataDup['code']);
        $this->assertEquals('OK (Duplicate ignored)', $dataDup['desc']);
        $this->assertArrayNotHasKey('success', $dataDup);
        $this->assertArrayNotHasKey('meta', $dataDup);
    }

    public function test_webhook_snap_strictly_returns_raw_protocol_v113(): void
    {
        $device = $this->createTestDevice([
            'device_id' => 'CAM-HW-SNAP',
            'is_active' => true,
        ]);

        $payload = [
            'SnapPic' => 'data:image/jpeg;base64,' . base64_encode('fake-stranger-snap'),
            'info' => [
                'DeviceID' => $device->device_id,
                'SnapID' => 771122,
                'CreateTime' => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $resp = $this->postJson('/Subscribe/Snap', $payload);
        $resp->assertStatus(200);
        $data = $resp->json();
        $this->assertEquals(200, $data['code']);
        $this->assertEquals('OK', $data['desc']);
        $this->assertArrayNotHasKey('success', $data);
        $this->assertArrayNotHasKey('meta', $data);
    }

    public function test_webhook_error_paths_return_protocol_v113_code_and_desc_without_envelope(): void
    {
        // 1. Missing DeviceID -> 400
        $respMissing = $this->postJson('/Subscribe/Verify', [
            'info' => [],
        ]);
        $respMissing->assertStatus(400);
        $dataMissing = $respMissing->json();
        $this->assertEquals(400, $dataMissing['code']);
        $this->assertArrayHasKey('desc', $dataMissing);
        $this->assertArrayNotHasKey('success', $dataMissing);
        $this->assertArrayNotHasKey('meta', $dataMissing);

        // 2. Oversized DeviceID > 64 chars -> 400
        $respOversized = $this->postJson('/Subscribe/heartbeat', [
            'info' => ['DeviceID' => str_repeat('A', 65)],
        ]);
        $respOversized->assertStatus(400);
        $dataOversized = $respOversized->json();
        $this->assertEquals(400, $dataOversized['code']);
        $this->assertArrayNotHasKey('success', $dataOversized);

        // 3. Inactive device without secret -> 401
        $inactive = $this->createTestDevice([
            'device_id' => 'CAM-INACTIVE-HW',
            'password' => 'secret123',
            'is_active' => false,
        ]);
        $respInactiveUnauth = $this->postJson('/Subscribe/Verify', [
            'info' => ['DeviceID' => $inactive->device_id],
        ]);
        $respInactiveUnauth->assertStatus(401);
        $dataUnauth = $respInactiveUnauth->json();
        $this->assertEquals(401, $dataUnauth['code']);
        $this->assertArrayNotHasKey('success', $dataUnauth);

        // 4. Inactive device WITH valid secret header -> 403 Forbidden
        $respInactiveAuth = $this->withHeaders(['X-Camera-Secret' => 'secret123'])
            ->postJson('/Subscribe/Verify', [
                'info' => ['DeviceID' => $inactive->device_id],
            ]);
        $respInactiveAuth->assertStatus(403);
        $dataInactive = $respInactiveAuth->json();
        $this->assertEquals(403, $dataInactive['code']);
        $this->assertEquals('Forbidden: Camera device not enrolled or inactive', $dataInactive['desc']);
        $this->assertArrayNotHasKey('success', $dataInactive);
    }

    // =========================================================================
    // SECTION 3: Form Requests Validation (422 Rejection vs Clean Pass)
    // =========================================================================

    public function test_store_device_form_request_rejects_invalid_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        // Invalid: missing required device_id and name
        $respInvalid = $this->postJson('/api/devices', []);
        $respInvalid->assertStatus(422);
        $respInvalid->assertJsonValidationErrors(['device_id', 'name']);

        // Invalid: bad device_type
        $respBadType = $this->postJson('/api/devices', [
            'device_id' => 'CAM-TEST-TYPE',
            'name' => 'Camera',
            'device_type' => 99,
        ]);
        $respBadType->assertStatus(422);
        $respBadType->assertJsonValidationErrors(['device_type']);

        // Valid payload
        $respValid = $this->postJson('/api/devices', [
            'device_id' => 'CAM-NEW-VALID-01',
            'name' => 'North Gate Camera',
            'ip_address' => '192.168.10.20',
            'port' => 1883,
            'device_role' => 'entry',
            'device_type' => 0,
        ]);
        $respValid->assertStatus(201);
        $this->assertDatabaseHas('devices', ['device_id' => 'CAM-NEW-VALID-01']);

        // Duplicate device_id rejected with 422
        $respDup = $this->postJson('/api/devices', [
            'device_id' => 'CAM-NEW-VALID-01',
            'name' => 'Duplicate North Gate',
        ]);
        $respDup->assertStatus(422);
        $respDup->assertJsonValidationErrors(['device_id']);
    }

    public function test_update_device_form_request_validates_inputs(): void
    {
        $this->actingAsAdmin();
        $device = $this->createTestDevice();

        // Invalid: bad scheme
        $respBadScheme = $this->putJson("/api/devices/{$device->id}", [
            'scheme' => 'ftp',
        ]);
        $respBadScheme->assertStatus(422);
        $respBadScheme->assertJsonValidationErrors(['scheme']);

        // Valid update
        $respValid = $this->putJson("/api/devices/{$device->id}", [
            'name' => 'Updated Device Name',
            'port' => 8883,
        ]);
        $respValid->assertStatus(200);
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'name' => 'Updated Device Name', 'port' => 8883]);
    }

    public function test_store_employee_form_request_rejects_invalid_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        // Invalid: empty payload
        $respInvalid = $this->postJson('/api/employees', []);
        $respInvalid->assertStatus(422);
        $respInvalid->assertJsonValidationErrors(['first_name', 'employee_code']);

        // Invalid: bad email format
        $respBadEmail = $this->postJson('/api/employees', [
            'first_name' => 'John',
            'employee_code' => 'EMP-VAL-001',
            'work_email' => 'not-an-email-address',
        ]);
        $respBadEmail->assertStatus(422);
        $respBadEmail->assertJsonValidationErrors(['work_email']);

        // Valid payload
        $respValid = $this->postJson('/api/employees', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'employee_code' => 'EMP-VAL-002',
            'work_email' => 'jane.doe.val@example.com',
            'employment_status' => 'active',
        ]);
        $respValid->assertStatus(201);
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-VAL-002']);

        // Duplicate employee_code rejected with 422
        $respDup = $this->postJson('/api/employees', [
            'first_name' => 'Another',
            'employee_code' => 'EMP-VAL-002',
        ]);
        $respDup->assertStatus(422);
        $respDup->assertJsonValidationErrors(['employee_code']);
    }

    public function test_update_employee_form_request_validates_inputs(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        // Invalid: duplicate work_email
        $otherEmployee = Employee::factory()->create(['work_email' => 'unique.taken@example.com']);
        $respDupEmail = $this->putJson("/api/employees/{$employee->id}", [
            'work_email' => 'unique.taken@example.com',
        ]);
        $respDupEmail->assertStatus(422);
        $respDupEmail->assertJsonValidationErrors(['work_email']);

        // Valid update
        $respValid = $this->putJson("/api/employees/{$employee->id}", [
            'first_name' => 'UpdatedFirst',
            'phone' => '1234567890',
        ]);
        $respValid->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'UpdatedFirst']);
    }

    public function test_store_shift_form_request_rejects_invalid_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        // Invalid: empty payload
        $respInvalid = $this->postJson('/api/shifts', []);
        $respInvalid->assertStatus(422);
        $respInvalid->assertJsonValidationErrors(['name', 'shift_start', 'shift_end']);

        // Valid payload
        $respValid = $this->postJson('/api/shifts', [
            'name' => 'Challenger Day Shift',
            'code' => 'CHAL-DAY-01',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'grace_period_minutes' => 15,
            'break_duration_minutes' => 60,
        ]);
        $respValid->assertStatus(201);
        $this->assertDatabaseHas('shifts', ['code' => 'CHAL-DAY-01']);
    }

    public function test_store_access_group_form_request_rejects_invalid_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        // Invalid: missing name and code
        $respInvalid = $this->postJson('/api/access-groups', []);
        $respInvalid->assertStatus(422);
        $respInvalid->assertJsonValidationErrors(['name', 'code']);

        // Valid payload
        $respValid = $this->postJson('/api/access-groups', [
            'name' => 'Engineering Lab Zone',
            'code' => 'AG-ENG-LAB',
            'description' => 'Restricted R&D facility',
            'is_active' => true,
        ]);
        $respValid->assertStatus(201);
        $this->assertDatabaseHas('access_groups', ['code' => 'AG-ENG-LAB']);

        // Duplicate code rejected with 422
        $respDup = $this->postJson('/api/access-groups', [
            'name' => 'Duplicate Lab',
            'code' => 'AG-ENG-LAB',
        ]);
        $respDup->assertStatus(422);
        $respDup->assertJsonValidationErrors(['code']);
    }

    public function test_submit_leave_form_request_rejects_invalid_date_order_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        $leaveType = LeaveType::create([
            'organization_id' => $employee->organization_id,
            'name' => 'Sick Leave',
            'code' => 'SL-CHAL',
            'days_per_year' => 10,
            'is_paid' => true,
        ]);

        // Invalid: missing required fields
        $respEmpty = $this->postJson('/api/leave-requests', []);
        $respEmpty->assertStatus(422);
        $respEmpty->assertJsonValidationErrors(['employee_id', 'leave_type_id', 'start_date', 'end_date']);

        // Invalid: end_date before start_date
        $respBadDates = $this->postJson('/api/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-15',
            'reason' => 'Backwards dates',
        ]);
        $respBadDates->assertStatus(422);
        $respBadDates->assertJsonValidationErrors(['end_date']);

        // Valid payload passes Form Request validation cleanly
        $respValid = $this->postJson('/api/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-22',
            'reason' => 'Annual Medical Checkup',
        ]);
        $this->assertNotEquals(422, $respValid->getStatusCode(), 'Valid payload must not fail with 422');
    }

    public function test_bulk_fleet_campaign_form_requests_reject_invalid_arrays(): void
    {
        $this->actingAsAdmin();

        // Bulk reboot: empty device_ids array
        $respEmptyReboot = $this->postJson('/api/devices/bulk-reboot', [
            'device_ids' => [],
        ]);
        $respEmptyReboot->assertStatus(422);
        $respEmptyReboot->assertJsonValidationErrors(['device_ids']);

        // Bulk reboot: invalid non-numeric device id
        $respBadIdReboot = $this->postJson('/api/devices/bulk-reboot', [
            'device_ids' => ['not-an-id'],
        ]);
        $respBadIdReboot->assertStatus(422);
        $respBadIdReboot->assertJsonValidationErrors(['device_ids.0']);

        // Bulk personnel deletion: empty personnel_ids array
        $respEmptyDel = $this->postJson('/api/personnel/bulk-delete', [
            'personnel_ids' => [],
        ]);
        $respEmptyDel->assertStatus(422);
        $respEmptyDel->assertJsonValidationErrors(['personnel_ids']);

        // Bulk personnel sync: empty personnel_ids array
        $respEmptySync = $this->postJson('/api/personnel/bulk-sync', [
            'personnel_ids' => [],
        ]);
        $respEmptySync->assertStatus(422);
        $respEmptySync->assertJsonValidationErrors(['personnel_ids']);
    }

    public function test_store_visitor_form_request_rejects_missing_first_name_and_accepts_valid(): void
    {
        $this->actingAsAdmin();

        $respEmpty = $this->postJson('/api/visitors', []);
        $respEmpty->assertStatus(422);
        $respEmpty->assertJsonValidationErrors(['first_name']);

        $respValid = $this->postJson('/api/visitors', [
            'first_name' => 'Alice',
            'last_name' => 'Auditor',
            'email' => 'alice.auditor@security.org',
            'phone' => '+15551234567',
            'company' => 'Security Audit Firm',
        ]);
        $respValid->assertStatus(201);
        $this->assertDatabaseHas('visitors', ['first_name' => 'Alice', 'last_name' => 'Auditor']);
    }

    public function test_submit_regularization_form_request_rejects_missing_fields(): void
    {
        $this->actingAsAdmin();

        $respEmpty = $this->postJson('/api/regularization-requests', []);
        $respEmpty->assertStatus(422);
        $respEmpty->assertJsonValidationErrors(['employee_id', 'date', 'reason']);
    }

    public function test_store_attendance_punch_form_request_rejects_invalid(): void
    {
        $this->actingAsAdmin();

        $respEmpty = $this->postJson('/api/attendance/punch', []);
        $respEmpty->assertStatus(422);
        $respEmpty->assertJsonValidationErrors(['employee_id', 'punch_time']);
    }
}
