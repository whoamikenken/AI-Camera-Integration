<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required',
            'punch_time' => 'required|date',
            'direction' => 'required|string|in:in,out',
            'reason' => 'required|string|max:255',
        ];
    }
}
