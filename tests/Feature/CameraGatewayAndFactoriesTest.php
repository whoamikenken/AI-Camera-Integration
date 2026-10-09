<?php

namespace Tests\Feature;

use App\Contracts\CameraGatewayInterface;
use App\Gateways\CameraGateway;
use App\Gateways\FakeCameraGateway;
use App\Gateways\HttpCameraGateway;
use App\Gateways\MqttCameraGateway;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\CameraMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CameraGatewayAndFactoriesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1. FACTORY VERIFICATION TESTS
    // =========================================================================

    public function test_device_factory_produces_valid_model_and_states(): void
    {
        $device = Device::factory()->create();
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertEquals(Device::ROLE_BIDIRECTIONAL, $device->device_role);

        // States
        $online = Device::factory()->online()->create();
        $this->assertTrue($online->is_online);
        $this->assertTrue($online->is_active);

        $offline = Device::factory()->offline()->create();
        $this->assertFalse($offline->is_online);

        $entry = Device::factory()->entryRole()->create();
        $this->assertEquals(Device::ROLE_ENTRY, $entry->device_role);

        $exit = Device::factory()->exitRole()->create();
        $this->assertEquals(Device::ROLE_EXIT, $exit->device_role);

        $bidirectional = Device::factory()->bidirectional()->create();
        $this->assertEquals(Device::ROLE_BIDIRECTIONAL, $bidirectional->device_role);

        $kiosk = Device::factory()->kiosk()->create();
        $this->assertEquals(Device::ROLE_VISITOR_KIOSK, $kiosk->device_role);

        $withLoc = Device::factory()->withLocation()->create();
        $this->assertNotNull($withLoc->location_id);
        $this->assertNotNull($withLoc->organization_id);
        $this->assertEquals($withLoc->location->organization_id, $withLoc->organization_id);
    }

    public function test_personnel_factory_produces_valid_model_and_states(): void
    {
        $person = Personnel::factory()->create();
        $this->assertDatabaseHas('personnel', ['id' => $person->id]);
        $this->assertGreaterThan(0, $person->customize_id);

        $whitelist = Personnel::factory()->whitelist()->create();
        $this->assertEquals(0, $whitelist->person_type);

        $blacklist = Personnel::factory()->blacklist()->create();
        $this->assertEquals(1, $blacklist->person_type);

        $temp = Personnel::factory()->temporary()->create();
        $this->assertEquals(1, $temp->temp_valid);
        $this->assertNotNull($temp->valid_begin);
        $this->assertNotNull($temp->valid_end);

        $perm = Personnel::factory()->permanent()->create();
        $this->assertEquals(0, $perm->temp_valid);
        $this->assertEquals(10000, $perm->effect_number);

        $withPhoto = Personnel::factory()->withPhoto()->create();
        $this->assertNotNull($withPhoto->photo_path);
        $this->assertNotNull($withPhoto->photo_base64);
    }

    public function test_employee_factory_produces_valid_model_and_states(): void
    {
        $emp = Employee::factory()->create();
        $this->assertDatabaseHas('employees', ['id' => $emp->id]);
        $this->assertEquals('active', $emp->employment_status);

        $active = Employee::factory()->active()->create();
        $this->assertEquals('active', $active->employment_status);
        $this->assertNull($active->date_of_leaving);

        $inactive = Employee::factory()->inactive()->create();
        $this->assertEquals('inactive', $inactive->employment_status);
        $this->assertNotNull($inactive->date_of_leaving);

        $withShift = Employee::factory()->withShift()->create();
        $this->assertNotNull($withShift->shift_id);
        $this->assertInstanceOf(Shift::class, $withShift->shift);

        $withDept = Employee::factory()->withDepartment()->create();
        $this->assertNotNull($withDept->department_id);
        $this->assertInstanceOf(Department::class, $withDept->department);

        $withPerson = Employee::factory()->withPersonnel()->create();
        $this->assertNotNull($withPerson->personnel_id);
        $this->assertInstanceOf(Personnel::class, $withPerson->personnel);

        $withUser = Employee::factory()->withUser()->create();
        $this->assertNotNull($withUser->user_id);
        $this->assertInstanceOf(User::class, $withUser->user);
    }

    public function test_shift_factory_produces_valid_model_and_states(): void
    {
        $shift = Shift::factory()->create();
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);

        $standard = Shift::factory()->standard()->create();
        $this->assertFalse($standard->is_overnight);
        $this->assertFalse($standard->is_flexible);
        $this->assertEquals('09:00:00', $standard->shift_start);
        $this->assertEquals('18:00:00', $standard->shift_end);

        $overnight = Shift::factory()->overnight()->create();
        $this->assertTrue($overnight->is_overnight);
        $this->assertTrue($overnight->crossesMidnight());

        $flexible = Shift::factory()->flexible()->create();
        $this->assertTrue($flexible->is_flexible);
        $this->assertEquals(8.0, $flexible->min_hours_full_day);
    }

    public function test_attendance_punch_factory_produces_valid_model_and_states(): void
    {
        $punch = AttendancePunch::factory()->create();
        $this->assertDatabaseHas('attendance_punches', ['id' => $punch->id]);

        $in = AttendancePunch::factory()->in()->create();
        $this->assertEquals('in', $in->direction);

        $out = AttendancePunch::factory()->out()->create();
        $this->assertEquals('out', $out->direction);

        $approved = AttendancePunch::factory()->approved()->create();
        $this->assertNotEmpty($approved->reason);

        $bio = AttendancePunch::factory()->biometric()->create();
        $this->assertEquals('camera_auto', $bio->source);

        $device = Device::factory()->create();
        $punchDev = AttendancePunch::factory()->forDevice($device)->create();
        $this->assertEquals($device->device_id, $punchDev->device_id);
    }

    public function test_visitor_factory_produces_valid_model_and_states(): void
    {
        $visitor = Visitor::factory()->create();
        $this->assertDatabaseHas('visitors', ['id' => $visitor->id]);
        $this->assertFalse($visitor->is_blocked);

        $blocked = Visitor::factory()->blocked('Security issue')->create();
        $this->assertTrue($blocked->is_blocked);
        $this->assertEquals('Security issue', $blocked->block_reason);

        $approved = Visitor::factory()->approved()->create();
        $this->assertFalse($approved->is_blocked);
        $this->assertNull($approved->block_reason);
    }

    public function test_visit_factory_produces_valid_model_and_states(): void
    {
        $visit = Visit::factory()->create();
        $this->assertDatabaseHas('visits', ['id' => $visit->id]);
        $this->assertEquals('expected', $visit->status);

        $expected = Visit::factory()->expected()->create();
        $this->assertEquals('expected', $expected->status);
        $this->assertNull($expected->check_in_time);

        $checkedIn = Visit::factory()->checkedIn()->create();
        $this->assertEquals('checked_in', $checkedIn->status);
        $this->assertNotNull($checkedIn->check_in_time);
        $this->assertNotNull($checkedIn->badge_number);

        $checkedOut = Visit::factory()->checkedOut()->create();
        $this->assertEquals('checked_out', $checkedOut->status);
        $this->assertNotNull($checkedOut->check_out_time);

        $cancelled = Visit::factory()->cancelled()->create();
        $this->assertEquals('cancelled', $cancelled->status);

        $overstayed = Visit::factory()->overstayed()->create();
        $this->assertEquals('checked_in', $overstayed->status);
        $this->assertNull($overstayed->check_out_time);
    }

    public function test_leave_type_factory_produces_valid_model_and_states(): void
    {
        $type = LeaveType::factory()->create();
        $this->assertDatabaseHas('leave_types', ['id' => $type->id]);

        $paid = LeaveType::factory()->paid()->create();
        $this->assertTrue($paid->is_paid);

        $unpaid = LeaveType::factory()->unpaid()->create();
        $this->assertFalse($unpaid->is_paid);

        $annual = LeaveType::factory()->annual()->create();
        $this->assertTrue($annual->is_paid);
        $this->assertStringContainsString('Annual', $annual->name);

        $sick = LeaveType::factory()->sick()->create();
        $this->assertTrue($sick->is_paid);
        $this->assertStringContainsString('Sick', $sick->name);
    }

    public function test_leave_request_factory_produces_valid_model_and_states(): void
    {
        $req = LeaveRequest::factory()->create();
        $this->assertDatabaseHas('leave_requests', ['id' => $req->id]);
        $this->assertEquals('pending', $req->status);

        $pending = LeaveRequest::factory()->pending()->create();
        $this->assertEquals('pending', $pending->status);
        $this->assertNull($pending->approved_by);

        $user = User::factory()->create();
        $approved = LeaveRequest::factory()->approved($user)->create();
        $this->assertEquals('approved', $approved->status);
        $this->assertEquals($user->id, $approved->approved_by);

        $rejected = LeaveRequest::factory()->rejected($user, 'Not enough balance')->create();
        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals('Not enough balance', $rejected->rejection_reason);

        $cancelled = LeaveRequest::factory()->cancelled()->create();
        $this->assertEquals('cancelled', $cancelled->status);
    }

    public function test_department_and_location_factories_produce_valid_models(): void
    {
        $org = Organization::factory()->create();

        $loc = Location::factory()->withOrganization($org)->create();
        $this->assertEquals($org->id, $loc->organization_id);

        $parentDept = Department::factory()->withOrganization($org)->create();
        $childDept = Department::factory()->withParent($parentDept)->create();

        $this->assertEquals($parentDept->id, $childDept->parent_id);
        $this->assertEquals($org->id, $childDept->organization_id);
    }

    // =========================================================================
    // 2. CAMERA GATEWAY CONTRACT & IMPLEMENTATION TESTS
    // =========================================================================

    public function test_container_resolves_camera_gateway_interface(): void
    {
        $gateway = app(CameraGatewayInterface::class);
        $this->assertInstanceOf(CameraGatewayInterface::class, $gateway);
        $this->assertInstanceOf(FakeCameraGateway::class, $gateway);
    }

    public function test_mqtt_and_http_gateways_implement_interface(): void
    {
        $mqttGateway = new MqttCameraGateway();
        $this->assertInstanceOf(CameraGatewayInterface::class, $mqttGateway);

        $httpGateway = new HttpCameraGateway();
        $this->assertInstanceOf(CameraGatewayInterface::class, $httpGateway);
    }

    public function test_camera_gateway_fake_fluent_assertions(): void
    {
        $fake = CameraGateway::fake();
        $fake->assertNothingDispatched();

        $device = Device::factory()->create(['device_id' => '1299991']);

        // Test command publishing
        $res = CameraGateway::rebootDevice($device);
        $this->assertTrue($res['success']);
        $this->assertEquals(200, $res['code']);

        CameraGateway::assertDispatched('RebootDevice');
        CameraGateway::assertDispatchedTimes('RebootDevice', 1);
        CameraGateway::assertDispatched('RebootDevice', function (Device $d, array $info) use ($device) {
            return $d->device_id === $device->device_id;
        });

        CameraGateway::assertNotDispatched('EditPerson');

        // Test personnel enrollment dispatch
        $person = Personnel::factory()->whitelist()->create(['name' => 'Alice Smith']);
        $fake->reset();
        CameraGateway::addOrUpdatePerson($device, $person);

        CameraGateway::assertDispatched('EditPerson');
        CameraGateway::assertDispatchedTimes('EditPerson', 1);

        // Test person deletion
        CameraGateway::deletePerson($device, [1001, 1002]);
        CameraGateway::assertDispatched('DeletePersons');

        // Test single person deletion
        CameraGateway::deletePerson($device, [1003]);
        CameraGateway::assertDispatched('DelPerson');
    }

    public function test_camera_gateway_fake_custom_responses(): void
    {
        $device = Device::factory()->create(['device_id' => '1299992']);

        CameraGateway::fake([
            'RebootDevice' => [
                'success' => true,
                'code' => 200,
                'custom_flag' => 'rebooted-cleanly',
            ],
            'GetDeviceInformation' => function (Device $d) {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'firmware' => 'v5.0.0-pro',
                        'device_id' => $d->device_id,
                    ],
                ];
            },
        ]);

        $rebootRes = CameraGateway::rebootDevice($device);
        $this->assertTrue($rebootRes['success']);
        $this->assertEquals('rebooted-cleanly', $rebootRes['custom_flag']);

        $infoRes = CameraGateway::getDeviceInformation($device);
        $this->assertTrue($infoRes['success']);
        $this->assertEquals('v5.0.0-pro', $infoRes['data']['firmware']);
    }

    public function test_camera_gateway_fake_timeout_and_error_simulation(): void
    {
        $device = Device::factory()->create(['device_id' => '1299993']);
        $fake = CameraGateway::fake();

        // Simulate timeout
        $fake->simulateTimeout(true);
        $timeoutRes = CameraGateway::publishCommand($device, 'Ping');
        $this->assertFalse($timeoutRes['success']);
        $this->assertEquals(504, $timeoutRes['code']);

        // Reset timeout and simulate error
        $fake->simulateTimeout(false);
        $fake->simulateError(400, 'Invalid parameters');
        $errorRes = CameraGateway::publishCommand($device, 'Ping');
        $this->assertFalse($errorRes['success']);
        $this->assertEquals(400, $errorRes['code']);
        $this->assertEquals('Invalid parameters', $errorRes['error']);
    }

    // =========================================================================
    // 3. CAMERA MQTT SERVICE DELEGATION TESTS (ZERO ENVIRONMENT TESTING CHECKS)
    // =========================================================================

    public function test_camera_mqtt_service_delegates_to_gateway_without_testing_checks(): void
    {
        CameraGateway::fake();
        $device = Device::factory()->create(['device_id' => '1299994']);
        $person = Personnel::factory()->whitelist()->create(['name' => 'Bob Builder']);

        $mqttService = app(CameraMqttService::class);

        // 1. publishCommandAndWait delegation
        $waitRes = $mqttService->publishCommandAndWait($device, 'CheckStatus');
        $this->assertTrue($waitRes['success']);
        CameraGateway::assertDispatched('CheckStatus');

        // 2. publishCommand delegation
        $pubRes = $mqttService->publishCommand($device, 'TriggerAlarm');
        $this->assertTrue($pubRes['success']);
        CameraGateway::assertDispatched('TriggerAlarm');

        // 3. searchPersonList delegation
        $listRes = $mqttService->searchPersonList($device, 0, 50);
        $this->assertTrue($listRes['success']);
        CameraGateway::assertDispatched('SearchPersonList');

        // 4. testConnection delegation
        $connRes = $mqttService->testConnection($device);
        $this->assertTrue($connRes['success']);
        CameraGateway::assertDispatched('GetDeviceInformation');

        // 5. High-level methods delegating through gateway
        $mqttService->rebootDevice($device);
        CameraGateway::assertDispatched('RebootDevice');

        $mqttService->addOrUpdatePerson($device, $person);
        CameraGateway::assertDispatched('EditPerson');

        $mqttService->deletePerson($device, [9901]);
        CameraGateway::assertDispatched('DelPerson');
    }
}
