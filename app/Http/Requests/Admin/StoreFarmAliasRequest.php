<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFarmAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('farms.manage');
    }

    public function rules(): array
    {
        return [
            'alias_text' => 'required|string|max:150|unique:farm_aliases,alias_text',
            'farm_id' => 'required|exists:farm_list,farm_id',
        ];
    }
}
