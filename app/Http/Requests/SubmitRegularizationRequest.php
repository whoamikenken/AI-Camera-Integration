<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRegularizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required',
            'date' => 'required|date',
            'requested_in' => 'nullable|date',
            'requested_out' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ];
    }
}
