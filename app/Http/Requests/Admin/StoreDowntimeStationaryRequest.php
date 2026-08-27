<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDowntimeStationaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('biosecurity.manage');
    }

    public function rules(): array
    {
        return [
            'assigned_farm_id' => 'required|exists:farm_list,farm_id|unique:downtime_stationary,assigned_farm_id',
            'minimum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'maximum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_farm_id.unique' => 'This farm already has a downtime stationary rule assigned.',
        ];
    }
}
