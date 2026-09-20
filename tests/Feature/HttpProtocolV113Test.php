<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\StrangerSnap;
use App\Services\CameraHttpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use Tests\TestCase;

class HttpProtocolV113Test extends TestCase
{
    use RefreshDatabase;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'device_id' => 'CAM-V113-TEST',
            'name' => 'V1.13 Test Camera',
            'scheme' => 'http',
            'ip_address' => '192.168.1.200',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ]);
    }

    /* =========================================================================
     * 1. PERSONNEL LIST MANAGEMENT (Section 2)
     * ========================================================================= */

    public function test_add_or_modify_single_person_edit_person_new(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/EditPersonNew' => Http::response([
                'operator' => 'EditPersonNew',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $person = Personnel::create([
            'name' => 'Alice Protocol',
            'customize_id' => 501,
            'person_type' => 0,
            'gender' => 1,
            'id_card' => '430923199011044411',
            'tel_num' => '19999999999',
            'address' => 'Shenzhen Tech Park',
            'native' => 'Guangdong',
            'notes' => 'VIP Access',
            'mj_card_no' => 'CARD-888',
            'mj_card_from' => 1,
            'photo_base64' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $service = app(CameraHttpService::class);
        $result = $service->addOrUpdatePerson($this->device, $person);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://192.168.1.200:8080/action/EditPersonNew' &&
                $data['operator'] === 'EditPersonNew' &&
                $data['info']['CustomizeID'] === 501 &&
                $data['info']['Name'] === 'Alice Protocol' &&
                $data['info']['Native'] === 'Guangdong' &&
                $data['info']['Notes'] === 'VIP Access' &&
                $data['info']['MjCardNo'] === 'CARD-888' &&
                $data['info']['MjCardFrom'] === 1 &&
                isset($data['picinfo']);
        });
    }

    public function test_batch_add_persons_add_persons(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/AddPersons' => Http::response([
                'operator' => 'AddPersons',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $service = app(CameraHttpService::class);
        $result = $service->addPersons($this->device, [
            [
                'CustomizeID' => 601,
                'Name' => 'Batch User 1',
                'PersonType' => 0,
            ],
            [
                'CustomizeID' => 602,
                'Name' => 'Batch User 2',
                'PersonType' => 1,
            ],
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://192.168.1.200:8080/action/AddPersons' &&
                $data['info']['Total'] === 2 &&
                $data['info']['Personinfo_0']['CustomizeID'] === 601 &&
                $data['info']['Personinfo_1']['CustomizeID'] === 602;
        });
    }

    public function test_batch_modify_persons_edit_persons_new(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/EditPersonsNew' => Http::response([
                'operator' => 'EditPersonsNew',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $service = app(CameraHttpService::class);
        $result = $service->editPersonsNew($this->device, [
            ['CustomizeID' => 701, 'Name' => 'Mod User 1', 'PersonType' => 0],
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://192.168.1.200:8080/action/EditPersonsNew' &&
                $data['info']['Total'] === 1 &&
                $data['info']['info'][0]['CustomizeID'] === 701;
        });
    }

    public function test_delete_person_and_delete_all_person(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/DeletePerson' => Http::response([
                'operator' => 'DeletePerson',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/DeleteAllPerson' => Http::response([
                'operator' => 'DeleteAllPerson',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $service = app(CameraHttpService::class);

        // Delete single/multiple
        $delResult = $service->deletePerson($this->device, [501, 502]);
        $this->assertTrue($delResult['success']);

        // Delete all
        $delAllResult = $service->deleteAllPersonnel($this->device);
        $this->assertTrue($delAllResult['success']);

        Http::assertSent(function ($request) {
            if ($request->url() === 'http://192.168.1.200:8080/action/DeletePerson') {
                return $request['info']['TotalNum'] === 2 && $request['info']['CustomizeID'] === [501, 502];
            }
            if ($request->url() === 'http://192.168.1.200:8080/action/DeleteAllPerson') {
                return $request['info']['DeleteAllPersonCheck'] === 1;
            }
            return false;
        });
    }

    public function test_search_person_search_person_num_and_search_person_list(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/SearchPerson' => Http::response([
                'operator' => 'SearchPerson',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'Name' => 'Target Person'],
            ], 200),
            'http://192.168.1.200:8080/action/SearchPersonNum' => Http::response([
                'operator' => 'SearchPersonNum',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'PersonNum' => 42, 'MaxListsNum' => 10000],
            ], 200),
            'http://192.168.1.200:8080/action/SearchPersonList' => Http::response([
                'operator' => 'SearchPersonList',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'Listnum' => 1, 'Personinfo_0' => ['CustomizeID' => 501]],
            ], 200),
        ]);

        $service = app(CameraHttpService::class);

        $res1 = $service->searchPerson($this->device, '501');
        $this->assertTrue($res1['success']);

        $res2 = $service->searchPersonNum($this->device);
        $this->assertTrue($res2['success']);
        $this->assertEquals(42, $res2['data']['info']['PersonNum']);

        $res3 = $service->searchPersonList($this->device, 0, 50);
        $this->assertTrue($res3['success']);
    }

    /* =========================================================================
     * 2. EVENT SUBSCRIPTIONS & WEBHOOK PUSH NOTIFICATIONS (Section 3)
     * ========================================================================= */

    public function test_subscription_management_subscribe_unsubscribe_get_subscribe(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/Subscribe' => Http::response([
                'operator' => 'Subscribe',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/Unsubscribe' => Http::response([
                'operator' => 'Unsubscribe',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/GetSubscribe' => Http::response([
                'operator' => 'GetSubscribe',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'Topics' => ['Snap', 'VerifyWithSnap']],
            ], 200),
        ]);

        $response1 = $this->postJson("/api/devices/{$this->device->id}/subscribe");
        $response1->assertStatus(200)->assertJson(['success' => true]);

        $response2 = $this->getJson("/api/devices/{$this->device->id}/subscribe");
        $response2->assertStatus(200)->assertJson(['success' => true]);

        $response3 = $this->postJson("/api/devices/{$this->device->id}/unsubscribe");
        $response3->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_subscription_heartbeat_webhook(): void
    {
        $response = $this->postJson('/Subscribe/heartbeat', [
            'operator' => 'HeartBeat',
            'info' => [
                'DeviceID' => 'CAM-V113-TEST',
                'Time' => '2026-09-17T01:00:00',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 200, 'desc' => 'OK']);

        $this->device->refresh();
        $this->assertNotNull($this->device->last_heartbeat_at);
    }

    public function test_verify_push_webhook_verifypush(): void
    {
        $response = $this->postJson('/Subscribe/Verify', [
            'operator' => 'VerifyPush',
            'info' => [
                'DeviceID' => 'CAM-V113-TEST',
                'PersonID' => 101,
                'CustomizeID' => 501,
                'Name' => 'John Whitelist',
                'PersonType' => 0,
                'VerifyStatus' => 1,
                'VerfyType' => 1,
                'Similarity1' => 95.8,
                'CreateTime' => '2026-09-17T01:05:00',
            ],
            'SanpPic' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 200, 'desc' => 'OK']);

        $this->assertDatabaseHas('access_logs', [
            'device_id' => 'CAM-V113-TEST',
            'customize_id' => 501,
            'person_name' => 'John Whitelist',
            'verify_status' => 1,
        ]);
    }

    public function test_snap_push_webhook_and_special_alarms_snappush_platesnappush(): void
    {
        // Standard stranger snap push
        $response1 = $this->postJson('/Subscribe/Snap', [
            'operator' => 'SnapPush',
            'info' => [
                'DeviceID' => 'CAM-V113-TEST',
                'CreateTime' => '2026-09-17T01:06:00',
            ],
            'SnapPic' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response1->assertStatus(200)->assertJson(['code' => 200]);

        $this->assertDatabaseHas('stranger_snaps', [
            'device_id' => 'CAM-V113-TEST',
        ]);

        // License plate snap push
        $response2 = $this->postJson('/Subscribe/Snap', [
            'operator' => 'PlateSnapPush',
            'info' => [
                'DeviceID' => 'CAM-V113-TEST',
                'LicencePlate' => '粤B888H9',
                'CreateTime' => '2026-09-17T01:07:00',
            ],
            'PlatePic' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response2->assertStatus(200)->assertJson(['code' => 200]);
    }

    public function test_manual_push_records_and_snaps(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/ManualPushRecords' => Http::response([
                'operator' => 'ManualPushRecords',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/ManualPushSnaps' => Http::response([
                'operator' => 'ManualPushSnaps',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $res1 = $this->postJson("/api/devices/{$this->device->id}/manual-push-records", [
            'time_s' => '2026-09-16 00:00:00',
            'time_e' => '2026-09-17 00:00:00',
        ]);
        $res1->assertStatus(200)->assertJson(['success' => true]);

        $res2 = $this->postJson("/api/devices/{$this->device->id}/manual-push-snaps", [
            'time_s' => '2026-09-16 00:00:00',
            'time_e' => '2026-09-17 00:00:00',
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);
    }

    /* =========================================================================
     * 3. DEVICE MAINTENANCE & SYSTEM OPERATIONS (Section 4)
     * ========================================================================= */

    public function test_mqtt_parameters_get_mqtt_param_and_set_mqtt_param(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/GetMQTTParam' => Http::response([
                'operator' => 'GetMQTTParam',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'MQEnable' => 1, 'MQTopic' => 'mqtt/face/CAM-V113-TEST'],
            ], 200),
            'http://192.168.1.200:8080/action/SetMQTTParam' => Http::response([
                'operator' => 'SetMQTTParam',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $res1 = $this->getJson("/api/devices/{$this->device->id}/mqtt-param");
        $res1->assertStatus(200)->assertJson(['success' => true]);

        $res2 = $this->postJson("/api/devices/{$this->device->id}/sync-mqtt", [
            'MQEnable' => 1,
            'MQAddr' => '192.168.1.50',
            'MQPort' => 1883,
            'MQTopic' => 'mqtt/face/CAM-V113-TEST',
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_system_parameters_get_sys_param_set_sys_param_and_set_sys_time(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/GetSysParam' => Http::response([
                'operator' => 'GetSysParam',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'Name' => 'Entrance Camera'],
            ], 200),
            'http://192.168.1.200:8080/action/SetSysParam' => Http::response([
                'operator' => 'SetSysParam',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/SetSysTime' => Http::response([
                'operator' => 'SetSysTime',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $res1 = $this->getJson("/api/devices/{$this->device->id}/sys-param");
        $res1->assertStatus(200)->assertJson(['success' => true]);

        $res2 = $this->postJson("/api/devices/{$this->device->id}/sys-param", [
            'Name' => 'Updated Gate Camera',
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);

        $res3 = $this->postJson("/api/devices/{$this->device->id}/sync-time", [
            'time' => '2026-09-17 01:10:00',
        ]);
        $res3->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_device_information_get_device_information(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/GetDeviceInformation' => Http::response([
                'operator' => 'GetDeviceInformation',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'DeviceType' => 0, 'HardwareID' => 'HW-9988'],
            ], 200),
        ]);

        $res = $this->getJson("/api/devices/{$this->device->id}/device-info");
        $res->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_handshake_data_storage_get_hand_shark_data_and_set_hand_shark_data(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/GetHandSharkData' => Http::response([
                'operator' => 'GetHandSharkData',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'HandSharkInfo' => 'token_custom_123'],
            ], 200),
            'http://192.168.1.200:8080/action/SetHandSharkData' => Http::response([
                'operator' => 'SetHandSharkData',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $res1 = $this->getJson("/api/devices/{$this->device->id}/handshake-data");
        $res1->assertStatus(200)->assertJson(['success' => true]);

        $res2 = $this->postJson("/api/devices/{$this->device->id}/handshake-data", [
            'handshake_info' => 'token_custom_456',
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://192.168.1.200:8080/action/SetHandSharkData' &&
                $request['info']['HandSharkInfo'] === 'token_custom_456';
        });
    }

    public function test_flow_directional_counts_get_count(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/GetCount' => Http::response([
                'operator' => 'GetCount',
                'code' => 200,
                'info' => ['Result' => 'Ok', 'TotalCount' => 150, 'InCount' => 90, 'OutCount' => 60],
            ], 200),
        ]);

        $res = $this->getJson("/api/devices/{$this->device->id}/flow-count?object_type=0&behaviour_direction=0");
        $res->assertStatus(200)->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://192.168.1.200:8080/action/GetCount' &&
                $request['info']['ObjectType'] === 0 &&
                $request['info']['BehaviourDirection'] === 0;
        });
    }

    public function test_system_actions_reboot_factory_reset_and_firmware_upgrade(): void
    {
        Http::fake([
            'http://192.168.1.200:8080/action/RebootDevice' => Http::response([
                'operator' => 'RebootDevice',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/SetFactoryDefault' => Http::response([
                'operator' => 'SetFactoryDefault',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
            'http://192.168.1.200:8080/action/Upgrade' => Http::response([
                'operator' => 'Upgrade',
                'code' => 200,
                'info' => ['Result' => 'Ok'],
            ], 200),
        ]);

        $res1 = $this->postJson("/api/devices/{$this->device->id}/reboot");
        $res1->assertStatus(200)->assertJson(['success' => true]);

        $res2 = $this->postJson("/api/devices/{$this->device->id}/factory-reset", [
            'default_net_par' => 0,
            'default_person' => 1,
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);

        $res3 = $this->postJson("/api/devices/{$this->device->id}/upgrade", [
            'name' => 'Firmware_v2.0',
            'url' => 'https://example.com/firmware/v2.udx',
            'upgrade_type' => 1,
        ]);
        $res3->assertStatus(200)->assertJson(['success' => true]);
    }
}
