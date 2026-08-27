<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('facilities.manage');
    }

    public function rules(): array
    {
        return [
            'alias_text' => 'required|string|max:150|unique:facility_aliases,alias_text',
            'facility_id' => 'required|exists:facility_list,facility_id',
        ];
    }
}
