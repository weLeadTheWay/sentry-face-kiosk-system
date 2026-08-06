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
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'full_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'farm' => 'required|string|max:255',
            'host_name' => 'required|string|max:255',
            'purpose' => 'nullable|string',
            // AppSheet sends US-style dates (e.g. "8/6/2026 14:00:00" or
            // "08/06/2026 14:00:00") - accept any recognizable date string
            // rather than a single strict format, since padding varies.
            'visit_datetime' => 'required|date',
            'departure_datetime' => 'nullable|date',
            'visitor_id' => 'required|string|max:255',
            'qr_url' => 'required|url|max:500',
        ];
    }
}
