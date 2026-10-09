<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $deviceId = (string) fake()->unique()->numerify('129####');

        return [
            'device_id' => $deviceId,
            'name' => 'Camera ' . fake()->word() . ' ' . fake()->numerify('##'),
            'scheme' => 'http',
            'ip_address' => fake()->ipv4(),
            'port' => 8080,
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0, // IPC
            'device_role' => Device::ROLE_BIDIRECTIONAL,
            'mqtt_topic' => "mqtt/face/{$deviceId}",
            'is_active' => true,
            'last_heartbeat_at' => now(),
            'organization_id' => null,
            'location_id' => null,
            'department_ids' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'last_heartbeat_at' => now(),
            'is_active' => true,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'last_heartbeat_at' => now()->subMinutes(15),
            'is_active' => true,
        ]);
    }

    public function entryRole(): static
    {
        return $this->state(fn () => [
            'device_role' => Device::ROLE_ENTRY,
        ]);
    }

    public function exitRole(): static
    {
        return $this->state(fn () => [
            'device_role' => Device::ROLE_EXIT,
        ]);
    }

    public function bidirectional(): static
    {
        return $this->state(fn () => [
            'device_role' => Device::ROLE_BIDIRECTIONAL,
        ]);
    }

    public function kiosk(): static
    {
        return $this->state(fn () => [
            'device_role' => Device::ROLE_VISITOR_KIOSK,
        ]);
    }

    public function visitorKiosk(): static
    {
        return $this->kiosk();
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withLocation(?Location $location = null): static
    {
        return $this->state(function (array $attributes) use ($location) {
            $loc = $location ?? Location::factory()->create();

            return [
                'location_id' => $loc->id,
                'organization_id' => $loc->organization_id,
            ];
        });
    }

    public function withOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }
}
