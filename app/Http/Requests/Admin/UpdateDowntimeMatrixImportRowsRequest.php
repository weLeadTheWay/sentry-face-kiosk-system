<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDowntimeMatrixImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('downtime_matrix_import.manage');
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array',
            'rows.*.origin_facility_id' => 'nullable|integer|exists:facility_list,facility_id',
            'rows.*.destination_facility_id' => 'nullable|integer|exists:facility_list,facility_id',
            'rows.*.minimum_downtime' => 'nullable|numeric|min:0',
            'rows.*.maximum_downtime' => 'nullable|numeric|min:0',
        ];
    }
}
