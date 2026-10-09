<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'personnel_id' => null,
            'user_id' => null,
            'organization_id' => null,
            'department_id' => null,
            'designation_id' => null,
            'location_id' => null,
            'reporting_manager_id' => null,
            'shift_id' => null,
            'employee_code' => strtoupper('EMP-' . fake()->unique()->numerify('#####')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'employment_type' => 'full-time',
            'employment_status' => 'active',
            'date_of_joining' => now()->subYears(1)->toDateString(),
            'date_of_leaving' => null,
            'work_email' => fake()->unique()->safeEmail(),
            'personal_email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'avatar' => null,
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => fake()->phoneNumber(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'employment_status' => 'active',
            'date_of_leaving' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'employment_status' => 'inactive',
            'date_of_leaving' => now()->toDateString(),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn () => [
            'employment_status' => 'terminated',
            'date_of_leaving' => now()->toDateString(),
        ]);
    }

    public function onLeave(): static
    {
        return $this->state(fn () => [
            'employment_status' => 'on_leave',
        ]);
    }

    public function withShift(?Shift $shift = null): static
    {
        return $this->state(fn () => [
            'shift_id' => $shift?->id ?? Shift::factory(),
        ]);
    }

    public function withDepartment(?Department $department = null): static
    {
        return $this->state(function (array $attributes) use ($department) {
            $dept = $department ?? Department::factory()->create();

            return [
                'department_id' => $dept->id,
                'organization_id' => $dept->organization_id,
            ];
        });
    }

    public function withPersonnel(?Personnel $personnel = null): static
    {
        return $this->state(function (array $attributes) use ($personnel) {
            $person = $personnel ?? Personnel::factory()->create([
                'name' => trim(($attributes['first_name'] ?? 'John') . ' ' . ($attributes['last_name'] ?? 'Doe')),
            ]);

            return [
                'personnel_id' => $person->id,
            ];
        });
    }

    public function withUser(?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function withLocation(?Location $location = null): static
    {
        return $this->state(fn () => [
            'location_id' => $location?->id ?? Location::factory(),
        ]);
    }

    public function withOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }
}
