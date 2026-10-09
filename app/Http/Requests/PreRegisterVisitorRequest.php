<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreRegisterVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visitor_id' => 'required',
            'host_employee_id' => 'nullable',
            'purpose' => 'nullable|string|max:64',
            'purpose_detail' => 'nullable|string|max:500',
            'expected_arrival' => 'nullable|date',
        ];
    }
}
