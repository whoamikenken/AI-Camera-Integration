<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('employee') ?? $this->route('id') ?? $this->id;
        if ($id instanceof \App\Models\Employee) {
            $id = $id->id;
        }

        return [
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'sometimes|required|string|max:64|unique:employees,employee_code,' . $id,
            'first_name' => 'sometimes|required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email,' . $id,
            'personal_email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'photo_base64' => 'nullable|string',
            'photo_path' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:128',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ];
    }
}
