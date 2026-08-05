<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKioskDeviceRequest;
use App\Http\Requests\Admin\UpdateKioskDeviceRequest;
use App\Models\KioskDevice;
use App\Models\FarmList;

class KioskDeviceController extends Controller
{
    public function index()
    {
        $kiosks = KioskDevice::with('farm')->paginate(config('sentry.pagination'));
        return $this->view('admin.kiosks._index', compact('kiosks'));
    }

    public function create()
    {
        $farms = FarmList::all();
        return $this->view('admin.kiosks._create', compact('farms'));
    }

    public function store(StoreKioskDeviceRequest $request)
    {
        KioskDevice::create($request->validated());
        return $this->index();
    }

    public function edit(KioskDevice $kiosk_device)
    {
        $farms = FarmList::all();
        return $this->view('admin.kiosks._edit', compact('kiosk_device', 'farms'));
    }

    public function update(UpdateKioskDeviceRequest $request, KioskDevice $kiosk_device)
    {
        $kiosk_device->update($request->validated());
        return $this->index();
    }

    public function destroy(KioskDevice $kiosk_device)
    {
        $kiosk_device->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.kiosks.index', $data);
    }
}
