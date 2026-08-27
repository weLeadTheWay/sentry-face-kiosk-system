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
            'origin_farm_id' => [
                'required',
                'exists:farm_list,farm_id',
                Rule::unique('downtime_matrix')->where(
                    fn ($query) => $query->where('destination_farm_id', $this->input('destination_farm_id'))
                ),
            ],
            'destination_farm_id' => 'required|exists:farm_list,farm_id',
            'minimum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'maximum_downtime' => 'nullable|numeric|min:0|max:9999.99',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'origin_farm_id.unique' => 'A downtime rule for this origin/destination farm pair already exists.',
        ];
    }
}
