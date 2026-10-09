<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $leaveType = $this->route('leave_type') ?? $this->route('id') ?? $this->id;
        $id = $leaveType instanceof LeaveType ? $leaveType->id : $leaveType;

        return [
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'code' => 'sometimes|required|string|max:64|unique:leave_types,code,' . $id,
            'max_days_per_year' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'is_carry_forward' => 'nullable|boolean',
            'max_carry_forward_days' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ];
    }
}
