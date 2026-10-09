<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => 'Regular Day Shift ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('SHF-' . fake()->unique()->lexify('????')),
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'grace_period_minutes' => 15,
            'early_out_threshold_minutes' => 30,
            'half_day_threshold_hours' => 4.0,
            'min_hours_full_day' => 8.0,
            'is_overnight' => false,
            'break_duration_minutes' => 60,
            'is_flexible' => false,
            'color' => '#3B82F6',
            'is_active' => true,
        ];
    }

    public function standard(): static
    {
        return $this->state(fn () => [
            'name' => 'Standard Day Shift',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'is_overnight' => false,
            'is_flexible' => false,
        ]);
    }

    public function overnight(): static
    {
        return $this->state(fn () => [
            'name' => 'Overnight Shift',
            'shift_start' => '22:00:00',
            'shift_end' => '06:00:00',
            'is_overnight' => true,
            'is_flexible' => false,
        ]);
    }

    public function flexible(): static
    {
        return $this->state(fn () => [
            'name' => 'Flexible Shift',
            'is_flexible' => true,
            'min_hours_full_day' => 8.0,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }
}
