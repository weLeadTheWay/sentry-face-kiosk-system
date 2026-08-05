<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdentityTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('identity_types.manage');
    }

    public function rules(): array
    {
        return [
            'identity_type_name' => 'required|string|max:100|unique:identity_type,identity_type_name',
        ];
    }
}
