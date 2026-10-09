<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeletePersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personnel_ids' => 'required|array|min:1',
            'personnel_ids.*' => 'integer|exists:personnel,id',
        ];
    }
}
