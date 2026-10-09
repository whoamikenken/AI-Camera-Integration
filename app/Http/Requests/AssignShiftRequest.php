<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => 'sometimes|required|exists:shifts,id',
            'employee_ids' => 'sometimes|required|array',
            'employee_ids.*' => 'exists:employees,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ];
    }
}
