<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('farms.manage');
    }

    public function rules(): array
    {
        return [
            'alias_text' => 'required|string|max:150|unique:farm_aliases,alias_text,' . $this->route('farm_alias')->alias_id . ',alias_id',
            'farm_id' => 'required|exists:farm_list,farm_id',
        ];
    }
}
