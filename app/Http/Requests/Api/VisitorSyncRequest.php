<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VisitorSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'full_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'farm' => 'required|string|max:255',
            'host_name' => 'required|string|max:255',
            'purpose' => 'nullable|string',
            'visit_datetime' => 'required|date_format:Y-m-d H:i:s',
            'departure_datetime' => 'nullable|date_format:Y-m-d H:i:s',
            'visitor_id' => 'required|string|max:255',
            'qr_url' => 'required|url|max:500',
        ];
    }
}
