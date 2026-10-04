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
            'port' => 1883,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
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
    }

    public function test_can_trigger_manual_push_snaps_for_historical_backfill(): void
    {
        $device = Device::create([
            'device_id' => 'CAM-BACKFILL-102',
            'name' => 'Backfill Stranger Camera',
            'scheme' => 'http',
            'ip_address' => 'ai-camera-api.philyra.cloud',
            'port' => 1883,
            'username' => 'admin',
            'password' => 'admin',
            'is_active' => true,
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
    }
}
