<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKioskDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->hasPermission('kiosks.manage');
    }

    public function rules(): array
    {
        return [
            'facility_id' => 'required|exists:facility_list,facility_id',
            'device_name' => 'required|string|max:100',
            'device_type' => 'nullable|string|max:50',
            'serial_number' => 'required|string|max:100|unique:kiosk_device,serial_number,' . $this->route('kiosk')->kiosk_id . ',kiosk_id',
            'public_ip' => 'nullable|ip',
            'status' => 'nullable|string|max:50',
        ];
    }
}
