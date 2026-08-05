<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreKioskDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('kiosks.manage');
    }

    public function rules(): array
    {
        return [
            'farm_id' => 'required|exists:farm_list,farm_id',
            'device_name' => 'required|string|max:100',
            'device_type' => 'nullable|string|max:50',
            'serial_number' => 'required|string|max:100|unique:kiosk_device,serial_number',
            'public_ip' => 'nullable|ip',
            'status' => 'nullable|string|max:50',
        ];
    }
}
