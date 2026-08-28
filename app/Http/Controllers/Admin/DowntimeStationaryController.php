<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeStationaryRequest;
use App\Http\Requests\Admin\UpdateDowntimeStationaryRequest;
use App\Models\DowntimeStationary;
use App\Models\FacilityList;
use Illuminate\Http\JsonResponse;

class DowntimeStationaryController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        $facilities = FacilityList::query()->select(['facility_id', 'facility_name'])->orderBy('facility_name')->get();

        return $this->view('admin.downtime-stationary._index', compact('facilities'));
    }

    public function data(): JsonResponse
    {
        $base = DowntimeStationary::query()
            ->select(['rule_id', 'assigned_facility_id', 'minimum_downtime', 'maximum_downtime', 'is_active'])
            ->with('assignedFacility:facility_id,facility_name');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $facilityId = request()->query('assigned_facility_id');
        if ($facilityId !== null && $facilityId !== '' && $facilityId !== 'ALL') {
            $filtered->where('assigned_facility_id', $facilityId);
        }

        $status = request()->query('status');
        if ($status === 'ACTIVE') {
            $filtered->where('is_active', true);
        } elseif ($status === 'INACTIVE') {
            $filtered->where('is_active', false);
        }

        $recordsFiltered = (clone $filtered)->count();

        // Keys are the real JS column position (0=assigned_facility, a
        // non-orderable relation column, 1=minimum_downtime,
        // 2=maximum_downtime, ...), matching what DataTables reports back.
        $orderableColumns = [1 => 'minimum_downtime', 2 => 'maximum_downtime'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'rule_id');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (DowntimeStationary $rule) => [
                'rule_id' => $rule->rule_id,
                'assigned_facility' => $rule->assignedFacility->facility_name ?? null,
                'minimum_downtime' => $rule->minimum_downtime !== null ? (float) $rule->minimum_downtime : null,
                'maximum_downtime' => $rule->maximum_downtime !== null ? (float) $rule->maximum_downtime : null,
                'is_active' => (bool) $rule->is_active,
            ])->all(),
        ]);
    }

    public function create()
    {
        $facilities = FacilityList::all();
        return $this->view('admin.downtime-stationary._create', compact('facilities'));
    }

    public function store(StoreDowntimeStationaryRequest $request)
    {
        DowntimeStationary::create($request->validated());
        return $this->index();
    }

    public function edit(DowntimeStationary $downtime_stationary)
    {
        $facilities = FacilityList::all();
        return $this->view('admin.downtime-stationary._edit', compact('downtime_stationary', 'facilities'));
    }

    public function update(UpdateDowntimeStationaryRequest $request, DowntimeStationary $downtime_stationary)
    {
        $downtime_stationary->update($request->validated());
        return $this->index();
    }

    public function destroy(DowntimeStationary $downtime_stationary)
    {
        $downtime_stationary->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.downtime-stationary.index', $data);
    }
}
