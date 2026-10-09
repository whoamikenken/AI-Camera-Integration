<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'required|string|max:64|unique:employees,employee_code',
            'first_name' => 'required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email',
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
