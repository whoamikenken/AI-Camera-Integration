<?php

namespace Database\Factories;

use App\Models\AttendancePunch;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    protected $model = AttendancePunch::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'access_log_id' => null,
            'device_id' => null,
            'punch_time' => now(),
            'direction' => 'in',
            'source' => 'camera_auto',
            'reason' => null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    public function in(): static
    {
        return $this->state(fn () => [
            'direction' => 'in',
        ]);
    }

    public function out(): static
    {
        return $this->state(fn () => [
            'direction' => 'out',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'reason' => 'Approved attendance punch',
        ]);
    }

    public function biometric(): static
    {
        return $this->state(fn () => [
            'source' => 'camera_auto',
        ]);
    }

    public function manual(?string $reason = null): static
    {
        return $this->state(fn () => [
            'source' => 'manual',
            'reason' => $reason ?? 'Manual attendance punch entry',
        ]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'employee_id' => $employee->id,
        ]);
    }

    public function forDevice(Device $device): static
    {
        return $this->state(fn () => [
            'device_id' => $device->device_id,
        ]);
    }

    public function atTime(\DateTimeInterface|string $time): static
    {
        return $this->state(fn () => [
            'punch_time' => $time,
        ]);
    }
}
