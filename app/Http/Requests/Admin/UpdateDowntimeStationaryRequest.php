<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDowntimeStationaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('biosecurity.manage');
    }

    public function rules(): array
    {
        return [
            'assigned_farm_id' => 'required|exists:farm_list,farm_id',
            'minimum_downtime_hours' => 'nullable|numeric|min:0|max:999.99',
            'max_downtime_hours' => 'nullable|numeric|min:0|max:999.99',
            'is_active' => 'boolean',
        ];
    }
}
