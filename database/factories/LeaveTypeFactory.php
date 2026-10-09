<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => 'Paid Time Off ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('LT-' . fake()->unique()->lexify('????')),
            'max_days_per_year' => 15.0,
            'is_paid' => true,
            'is_carry_forward' => false,
            'max_carry_forward_days' => 0.0,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'is_paid' => true,
        ]);
    }

    public function unpaid(): static
    {
        return $this->state(fn () => [
            'name' => 'Unpaid Leave ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('UNP-' . fake()->unique()->lexify('???')),
            'is_paid' => false,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'name' => 'Annual Leave ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('ANN-' . fake()->unique()->lexify('???')),
            'is_paid' => true,
            'max_days_per_year' => 15.0,
        ]);
    }

    public function sick(): static
    {
        return $this->state(fn () => [
            'name' => 'Sick Leave ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('SCK-' . fake()->unique()->lexify('???')),
            'is_paid' => true,
            'max_days_per_year' => 10.0,
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
