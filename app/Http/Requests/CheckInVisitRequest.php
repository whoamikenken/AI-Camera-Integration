<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'badge_number' => 'nullable|string|max:64',
            'nda_signed' => 'nullable|boolean',
        ];
    }
}
