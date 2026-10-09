<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(['Engineering', 'Human Resources', 'Finance', 'Operations', 'Security', 'Marketing', 'Sales', 'IT Support', 'Administration', 'Logistics']) . ' ' . fake()->unique()->numerify('###'),
            'code' => strtoupper('DEP-' . fake()->unique()->lexify('???')),
            'parent_id' => null,
            'head_id' => null,
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

    public function withParent(?Department $parent = null): static
    {
        return $this->state(function (array $attributes) use ($parent) {
            $parentDept = $parent ?? Department::factory()->create([
                'organization_id' => $attributes['organization_id'] ?? Organization::factory(),
            ]);

            return [
                'parent_id' => $parentDept->id,
                'organization_id' => $parentDept->organization_id,
            ];
        });
    }
}
