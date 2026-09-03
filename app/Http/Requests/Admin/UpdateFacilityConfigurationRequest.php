<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('facilities.manage');
    }

    public function rules(): array
    {
        return [
            'field' => 'required|string|in:is_gs,is_break_enabled,is_truck',
            'value' => 'required|boolean',
        ];
    }
}
