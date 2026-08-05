<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('roles.manage');
    }

    public function rules(): array
    {
        return [
            'role_name' => 'required|string|max:100|unique:roles,role_name,' . $this->route('role')->role_id . ',role_id',
            'description' => 'nullable|string',
        ];
    }
}
