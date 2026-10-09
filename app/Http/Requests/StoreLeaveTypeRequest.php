<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:64|unique:leave_types,code',
            'max_days_per_year' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'is_carry_forward' => 'nullable|boolean',
            'max_carry_forward_days' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ];
    }
}
