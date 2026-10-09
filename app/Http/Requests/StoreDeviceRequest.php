<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:64|unique:devices,device_id',
            'name' => 'required|string|max:128',
            'scheme' => 'nullable|string|in:http,https',
            'endpoint' => 'nullable|string|max:255',
            'endpoint_url' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'device_type' => 'nullable|integer|in:0,1,2,3',
            'device_role' => 'nullable|string|in:entry,exit,bidirectional,visitor_kiosk',
            'organization_id' => 'nullable|exists:organizations,id',
            'location_id' => 'nullable|exists:locations,id',
            'mqtt_topic' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
        ];
    }
}
