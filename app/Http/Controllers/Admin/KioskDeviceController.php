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

    public function edit(KioskDevice $kiosk)
    {
        $farms = FarmList::all();
        $kiosk_device = $kiosk;
        return $this->view('admin.kiosks._edit', compact('kiosk_device', 'farms'));
    }

    public function update(UpdateKioskDeviceRequest $request, KioskDevice $kiosk)
    {
        $kiosk->update($request->validated());
        return $this->index();
    }

    public function destroy(KioskDevice $kiosk)
    {
        $kiosk->delete();
        return $this->index();
    }

    public function regenerateToken(KioskDevice $kiosk)
    {
        $kiosk->regenerateToken();
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
