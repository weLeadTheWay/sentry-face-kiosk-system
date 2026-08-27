<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('facilities.manage');
    }

    public function rules(): array
    {
        return [
            'alias_text' => 'required|string|max:150|unique:facility_aliases,alias_text,' . $this->route('facility_alias')->alias_id . ',alias_id',
            'facility_id' => 'required|exists:facility_list,facility_id',
        ];
    }
}
