<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => 'nullable|exists:organizations,id',
            'first_name' => 'required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:128',
            'id_type' => 'nullable|string|max:32',
            'id_number' => 'nullable|string|max:64',
            'photo_path' => 'nullable|string|max:255',
            'is_blocked' => 'nullable|boolean',
            'block_reason' => 'nullable|string|max:500',
        ];
    }
}
