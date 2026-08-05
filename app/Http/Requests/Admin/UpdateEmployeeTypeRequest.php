<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('employee_types.manage');
    }

    public function rules(): array
    {
        return [
            'employee_type_name' => 'required|string|max:100|unique:employee_type,employee_type_name,' . $this->route('employee_type')->employee_type_id . ',employee_type_id',
        ];
    }
}
