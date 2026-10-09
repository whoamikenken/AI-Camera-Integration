<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $startDate = now()->addDays(2)->toDateString();
        $endDate = now()->addDays(4)->toDateString();

        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => 3.0,
            'reason' => 'Annual vacation time off',
            'status' => 'pending',
            'approved_by' => null,
            'rejection_reason' => null,
            'approved_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_by' => $approver?->id ?? User::factory(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function rejected(?User $rejector = null, ?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'approved_by' => $rejector?->id ?? User::factory(),
            'rejection_reason' => $reason ?? 'Scheduling conflict during requested period',
            'approved_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function withEmployee(?Employee $employee = null): static
    {
        return $this->state(fn () => [
            'employee_id' => $employee?->id ?? Employee::factory(),
        ]);
    }

    public function withLeaveType(?LeaveType $leaveType = null): static
    {
        return $this->state(fn () => [
            'leave_type_id' => $leaveType?->id ?? LeaveType::factory(),
        ]);
    }
}
