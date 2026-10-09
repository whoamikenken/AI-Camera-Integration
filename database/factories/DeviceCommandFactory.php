<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCommand>
 */
class DeviceCommandFactory extends Factory
{
    protected $model = DeviceCommand::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'message_id' => 'CMD-' . strtoupper(substr(uniqid(), -8)),
            'operator' => fake()->randomElement(['RebootDevice', 'UpMQTTconfig', 'SetSysTime', 'GetDeviceInformation']),
            'status' => 'pending',
            'payload' => [],
            'response' => null,
            'error_message' => null,
            'dispatched_at' => now(),
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'response' => null,
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'response' => ['code' => 0, 'info' => ['Result' => 0]],
            'error_message' => null,
            'completed_at' => now(),
        ]);
    }

    public function failed(?string $errorMessage = 'Hardware execution failed'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'response' => ['code' => 1, 'desc' => $errorMessage],
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function forDevice(Device $device): static
    {
        return $this->state(fn () => [
            'device_id' => $device->id,
            'payload' => ['facesluiceId' => $device->device_id],
        ]);
    }
}
