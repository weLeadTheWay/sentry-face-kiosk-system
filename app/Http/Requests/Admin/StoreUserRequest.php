<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('users.manage');
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|exists:roles,role_id',
            'user_name' => 'required|string|max:100',
            'user_email' => 'required|email|max:255|unique:users,user_email',
            'password' => 'required|string|min:8|confirmed',
            'is_active' => 'boolean',
        ];
    }
}
