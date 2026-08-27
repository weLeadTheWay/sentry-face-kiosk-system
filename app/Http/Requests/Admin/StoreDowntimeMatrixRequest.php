<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDowntimeMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('biosecurity.manage');
    }

    public function rules(): array
    {
        return [
            'origin_facility_id' => [
                'required',
                'exists:facility_list,facility_id',
                Rule::unique('downtime_matrix')->where(
                    fn ($query) => $query->where('destination_facility_id', $this->input('destination_facility_id'))
                ),
            ],
            'destination_facility_id' => 'required|exists:facility_list,facility_id',
            'minimum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'maximum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'origin_facility_id.unique' => 'A downtime rule for this origin/destination facility pair already exists.',
        ];
    }
}
