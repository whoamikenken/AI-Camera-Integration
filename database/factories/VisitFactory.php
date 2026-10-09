<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Personnel;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'host_employee_id' => null,
            'personnel_id' => null,
            'purpose' => 'meeting',
            'purpose_detail' => 'Business meeting with client',
            'expected_arrival' => now()->addHour(),
            'check_in_time' => null,
            'check_out_time' => null,
            'badge_number' => null,
            'nda_signed' => false,
            'status' => 'expected',
        ];
    }

    public function expected(): static
    {
        return $this->state(fn () => [
            'status' => 'expected',
            'expected_arrival' => now()->addHour(),
            'check_in_time' => null,
            'check_out_time' => null,
        ]);
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => [
            'status' => 'checked_in',
            'check_in_time' => now(),
            'badge_number' => 'B-' . fake()->numerify('####'),
            'nda_signed' => true,
        ]);
    }

    public function checkedOut(): static
    {
        return $this->state(fn () => [
            'status' => 'checked_out',
            'check_in_time' => now()->subHours(2),
            'check_out_time' => now(),
            'badge_number' => 'B-' . fake()->numerify('####'),
            'nda_signed' => true,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'check_in_time' => null,
            'check_out_time' => null,
        ]);
    }

    public function overstayed(): static
    {
        return $this->state(fn () => [
            'status' => 'checked_in',
            'expected_arrival' => now()->subHours(8),
            'check_in_time' => now()->subHours(7),
            'check_out_time' => null,
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'status' => 'expected',
            'expected_arrival' => now()->subDays(1),
            'check_in_time' => null,
        ]);
    }

    public function withVisitor(?Visitor $visitor = null): static
    {
        return $this->state(fn () => [
            'visitor_id' => $visitor?->id ?? Visitor::factory(),
        ]);
    }

    public function withHost(?Employee $host = null): static
    {
        return $this->state(fn () => [
            'host_employee_id' => $host?->id ?? Employee::factory(),
        ]);
    }

    public function withPersonnel(?Personnel $personnel = null): static
    {
        return $this->state(fn () => [
            'personnel_id' => $personnel?->id ?? Personnel::factory(),
        ]);
    }
}
