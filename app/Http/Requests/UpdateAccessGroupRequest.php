<?php

namespace App\Http\Requests;

use App\Models\AccessGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('access_group') ?? $this->route('id') ?? $this->id;
        $id = $group instanceof AccessGroup ? $group->id : $group;

        return [
            'name' => 'sometimes|required|string|max:128',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('access_groups', 'code')->ignore($id),
            ],
            'description' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_active' => 'sometimes|boolean',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer|exists:devices,id',
            'personnel_ids' => 'nullable|array',
            'personnel_ids.*' => 'integer|exists:personnel,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
        ];
    }
}
