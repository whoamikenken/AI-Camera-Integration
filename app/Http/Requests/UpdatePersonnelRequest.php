<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:64',
            'person_type' => 'required|integer|in:0,1',
            'gender' => 'nullable|integer|in:0,1',
            'id_card' => 'nullable|string|max:32',
            'tel_num' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:128',
            'birthday' => 'nullable|date',
            'temp_valid' => 'nullable|integer|in:0,1',
            'valid_begin' => 'nullable|date',
            'valid_end' => 'nullable|date',
            'effect_number' => 'nullable|integer',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240',
            'photo_base64' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'photo_path' => 'nullable|string',
        ];
    }
}
