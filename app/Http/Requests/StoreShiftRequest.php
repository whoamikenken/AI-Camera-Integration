<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('shifts')->where(function ($query) {
                    $orgId = $this->input('organization_id');
                    return $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
                }),
            ],
            'shift_start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shift_end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'grace_period_minutes' => 'nullable|integer|min:0',
            'early_out_threshold_minutes' => 'nullable|integer|min:0',
            'half_day_threshold_hours' => 'nullable|numeric|min:0',
            'min_hours_full_day' => 'nullable|numeric|min:0',
            'is_overnight' => 'nullable|boolean',
            'break_duration_minutes' => 'nullable|integer|min:0',
            'is_flexible' => 'nullable|boolean',
            'color' => 'nullable|string|max:32',
            'is_active' => 'nullable|boolean',
        ];
    }
}
