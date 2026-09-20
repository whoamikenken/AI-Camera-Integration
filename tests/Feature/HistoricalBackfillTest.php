<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoricalBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_trigger_manual_push_records_for_historical_backfill(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-BACKFILL-101',
            'name' => 'Backfill Test Camera',
            'scheme' => 'http',
            'ip_address' => '192.168.1.105',
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.1.105:8080/action/ManualPushRecords' => Http::response([
                'operator' => 'ManualPushRecords',
                'code' => 200,
                'info' => [
                    'Result' => 'Ok',
                    'Detail' => 'Historical verification records backfill stream initiated',
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/manual-push-records", [
            'time_s' => '2026-09-16 00:00:00',
            'time_e' => '2026-09-17 00:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        Http::assertSent(function ($request) use ($device) {
            return $request->url() === 'http://192.168.1.105:8080/action/ManualPushRecords' &&
                $request['operator'] === 'ManualPushRecords' &&
                $request['info']['DeviceID'] === 'CAM-BACKFILL-101' &&
                $request['info']['TimeS'] === '2026-09-16T00:00:00' &&
                $request['info']['TimeE'] === '2026-09-17T00:00:00';
        });
    }

    public function test_can_trigger_manual_push_snaps_for_historical_backfill(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-BACKFILL-102',
            'name' => 'Backfill Stranger Camera',
            'scheme' => 'https',
            'ip_address' => 'ai-camera-api.philyra.cloud',
            'port' => 443,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'https://ai-camera-api.philyra.cloud/action/ManualPushSnaps' => Http::response([
                'operator' => 'ManualPushSnaps',
                'code' => 200,
                'info' => [
                    'Result' => 'Ok',
                    'Detail' => 'Historical stranger snapshots backfill stream initiated',
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/manual-push-snaps", [
            'time_s' => '2026-09-16 00:00:00',
            'time_e' => '2026-09-17 00:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ai-camera-api.philyra.cloud/action/ManualPushSnaps' &&
                $request['operator'] === 'ManualPushSnaps' &&
                $request['info']['DeviceID'] === 'CAM-BACKFILL-102' &&
                $request['info']['TimeS'] === '2026-09-16T00:00:00' &&
                $request['info']['TimeE'] === '2026-09-17T00:00:00';
        });
    }
}
