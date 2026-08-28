<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKioskDeviceRequest;
use App\Http\Requests\Admin\UpdateKioskDeviceRequest;
use App\Models\KioskDevice;
use App\Models\FacilityList;
use Illuminate\Http\JsonResponse;

class KioskDeviceController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        $facilities = FacilityList::query()->select(['facility_id', 'facility_name'])->orderBy('facility_name')->get();

        return $this->view('admin.kiosks._index', compact('facilities'));
    }

    public function data(): JsonResponse
    {
        $base = KioskDevice::query()
            ->select(['kiosk_id', 'facility_id', 'device_name', 'serial_number', 'public_ip', 'status'])
            ->with('facility:facility_id,facility_name');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where(function ($q) use ($search) {
                $q->where('device_name', 'like', '%' . $search . '%')
                    ->orWhere('serial_number', 'like', '%' . $search . '%');
            });
        }

        $facilityId = request()->query('facility_id');
        if ($facilityId !== null && $facilityId !== '' && $facilityId !== 'ALL') {
            $filtered->where('facility_id', $facilityId);
        }

        $recordsFiltered = (clone $filtered)->count();

        // Keys are the real position of each column in the JS `columns`
        // array (0=device_name, 1=facility_name[non-orderable], 2=serial_number,
        // ...) - not a compacted "orderable columns only" list. DataTables
        // reports back the real column index, so the keys must match it.
        $orderableColumns = [0 => 'device_name', 2 => 'serial_number'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'device_name');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (KioskDevice $kiosk) => [
                'kiosk_id' => $kiosk->kiosk_id,
                'device_name' => $kiosk->device_name,
                'facility_name' => $kiosk->facility->facility_name ?? null,
                'serial_number' => $kiosk->serial_number,
                'public_ip' => $kiosk->public_ip,
                'status' => $kiosk->status,
            ])->all(),
        ]);
    }

    public function create()
    {
        $facilities = FacilityList::all();
        return $this->view('admin.kiosks._create', compact('facilities'));
    }

    public function store(StoreKioskDeviceRequest $request)
    {
        KioskDevice::create($request->validated());
        return $this->index();
    }

    public function edit(KioskDevice $kiosk)
    {
        $facilities = FacilityList::all();
        $kiosk_device = $kiosk;
        return $this->view('admin.kiosks._edit', compact('kiosk_device', 'facilities'));
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
