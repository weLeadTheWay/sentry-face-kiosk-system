<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDowntimeMatrixImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('downtime_matrix_import.manage');
    }

    public function rules(): array
    {
        return [
            'matrix_type' => 'required|string|in:BFI_BVA',
            'pdf_file' => 'required|file|mimes:pdf|max:20480',
        ];
    }
}
