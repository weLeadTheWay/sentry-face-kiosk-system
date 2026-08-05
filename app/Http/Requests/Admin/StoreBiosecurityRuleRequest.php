<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBiosecurityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('biosecurity.manage');
    }

    public function rules(): array
    {
        return [
            'origin_farm_id' => 'required|exists:farm_list,farm_id',
            'destination_farm_id' => 'required|exists:farm_list,farm_id',
            'area_type' => 'nullable|string|max:100',
            'minimum_downtime' => 'nullable|integer|min:0',
            'maximum_downtime' => 'nullable|integer|min:0',
            'access_level' => 'required|string|max:50',
            'is_active' => 'boolean',
        ];
    }
}
