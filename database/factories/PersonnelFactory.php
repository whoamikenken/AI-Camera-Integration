<?php

namespace Database\Factories;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    protected $model = Personnel::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'person_type' => 0, // 0: Whitelist
            'gender' => fake()->randomElement([0, 1]), // 0: Male, 1: Female
            'id_card' => fake()->numerify('ID-########'),
            'tel_num' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'birthday' => fake()->date('Y-m-d', '-20 years'),
            'temp_valid' => 0, // 0: Permanent
            'valid_begin' => now()->startOfDay(),
            'valid_end' => now()->addYears(5)->endOfDay(),
            'effect_number' => 10000,
            'photo_path' => null,
            'photo_base64' => null,
        ];
    }

    public function whitelist(): static
    {
        return $this->state(fn () => [
            'person_type' => 0,
        ]);
    }

    public function blacklist(): static
    {
        return $this->state(fn () => [
            'person_type' => 1,
        ]);
    }

    public function temporary(?\DateTimeInterface $begin = null, ?\DateTimeInterface $end = null): static
    {
        return $this->state(fn () => [
            'temp_valid' => 1,
            'valid_begin' => $begin ?? now(),
            'valid_end' => $end ?? now()->addDays(7),
            'effect_number' => 10,
        ]);
    }

    public function permanent(): static
    {
        return $this->state(fn () => [
            'temp_valid' => 0,
            'valid_begin' => now()->startOfDay(),
            'valid_end' => now()->addYears(10)->endOfDay(),
            'effect_number' => 10000,
        ]);
    }

    public function male(): static
    {
        return $this->state(fn () => [
            'gender' => 0,
        ]);
    }

    public function female(): static
    {
        return $this->state(fn () => [
            'gender' => 1,
        ]);
    }

    public function withPhoto(?string $path = null, ?string $base64 = null): static
    {
        return $this->state(fn () => [
            'photo_path' => $path ?? 'personnel/' . fake()->uuid() . '.jpg',
            'photo_base64' => $base64 ?? base64_encode('fake-face-image-content'),
        ]);
    }
}
