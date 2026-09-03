<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('facilities.manage');
    }

    public function rules(): array
    {
        return [
            'facility_code' => 'required|string|max:50|unique:facility_list,facility_code,' . $this->route('facility')->facility_id . ',facility_id',
            'facility_name' => 'required|string|max:150',
            'facility_type_id' => 'required|exists:facility_type,facility_type_id',
            'facility_category_id' => 'required|exists:facility_category,facility_category_id',
            'location' => 'nullable|string|max:255',
            'is_rtl' => 'boolean',
            'is_active' => 'boolean',
            'is_gs' => 'boolean',
        ];
    }
}
