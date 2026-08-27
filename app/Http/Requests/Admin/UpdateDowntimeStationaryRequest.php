<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDowntimeStationaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('biosecurity.manage');
    }

    public function rules(): array
    {
        return [
            'assigned_facility_id' => [
                'required',
                'exists:facility_list,facility_id',
                Rule::unique('downtime_stationary', 'assigned_facility_id')->ignore($this->route('downtime_stationary')),
            ],
            'minimum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'maximum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_facility_id.unique' => 'This facility already has a downtime stationary rule assigned.',
        ];
    }
}
