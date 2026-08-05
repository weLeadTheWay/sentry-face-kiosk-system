<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('farms.manage');
    }

    public function rules(): array
    {
        return [
            'farm_code' => 'required|string|max:50|unique:farm_list,farm_code,' . $this->route('farm')->farm_id . ',farm_id',
            'farm_name' => 'required|string|max:150',
            'location' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ];
    }
}
