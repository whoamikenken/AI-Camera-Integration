<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccessGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:64|unique:access_groups,code',
            'description' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_active' => 'nullable|boolean',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer|exists:devices,id',
            'personnel_ids' => 'nullable|array',
            'personnel_ids.*' => 'integer|exists:personnel,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
        ];
    }
}
