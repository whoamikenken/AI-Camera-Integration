<?php

namespace Database\Factories;

use App\Models\AccessGroup;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGroup>
 */
class AccessGroupFactory extends Factory
{
    protected $model = AccessGroup::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => 'Zone ' . fake()->words(2, true),
            'code' => strtoupper('ZONE-' . fake()->unique()->lexify('???-###')),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
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
