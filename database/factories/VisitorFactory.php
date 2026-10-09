<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'id_type' => 'national_id',
            'id_number' => fake()->numerify('ID-########'),
            'photo_path' => null,
            'is_blocked' => false,
            'block_reason' => null,
        ];
    }

    public function blocked(?string $reason = null): static
    {
        return $this->state(fn () => [
            'is_blocked' => true,
            'block_reason' => $reason ?? 'Security watchlist restriction',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'is_blocked' => false,
            'block_reason' => null,
        ]);
    }

    public function withOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }
}
