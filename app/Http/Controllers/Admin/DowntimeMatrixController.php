<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeMatrixRequest;
use App\Http\Requests\Admin\UpdateDowntimeMatrixRequest;
use App\Models\DowntimeMatrix;
use App\Models\FacilityList;
use Illuminate\Http\JsonResponse;

class DowntimeMatrixController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        $facilities = FacilityList::query()->select(['facility_id', 'facility_name'])->orderBy('facility_name')->get();

        return $this->view('admin.downtime-matrix._index', compact('facilities'));
    }

    public function data(): JsonResponse
    {
        $base = DowntimeMatrix::query()
            ->select(['rule_id', 'origin_facility_id', 'destination_facility_id', 'minimum_downtime', 'maximum_downtime', 'is_active'])
            ->with([
                'originFacility:facility_id,facility_name',
                'destinationFacility:facility_id,facility_name',
            ]);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $originId = request()->query('origin_facility_id');
        if ($originId !== null && $originId !== '' && $originId !== 'ALL') {
            $filtered->where('origin_facility_id', $originId);
        }

        $destinationId = request()->query('destination_facility_id');
        if ($destinationId !== null && $destinationId !== '' && $destinationId !== 'ALL') {
            $filtered->where('destination_facility_id', $destinationId);
        }

        $status = request()->query('status');
        if ($status === 'ACTIVE') {
            $filtered->where('is_active', true);
        } elseif ($status === 'INACTIVE') {
            $filtered->where('is_active', false);
        }

        $recordsFiltered = (clone $filtered)->count();

        // Keys are the real JS column position (0=origin, 1=destination -
        // both non-orderable relation columns, 2=minimum_downtime,
        // 3=maximum_downtime, ...), matching what DataTables reports back.
        $orderableColumns = [2 => 'minimum_downtime', 3 => 'maximum_downtime'];
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
            'data' => $rows->map(fn (DowntimeMatrix $rule) => [
                'rule_id' => $rule->rule_id,
                'origin_facility' => $rule->originFacility->facility_name ?? null,
                'destination_facility' => $rule->destinationFacility->facility_name ?? null,
                'minimum_downtime' => $rule->minimum_downtime !== null ? (float) $rule->minimum_downtime : null,
                'maximum_downtime' => $rule->maximum_downtime !== null ? (float) $rule->maximum_downtime : null,
                'is_active' => (bool) $rule->is_active,
            ])->all(),
        ]);
    }

    public function create()
    {
        $facilities = FacilityList::all();
        return $this->view('admin.downtime-matrix._create', compact('facilities'));
    }

    public function store(StoreDowntimeMatrixRequest $request)
    {
        DowntimeMatrix::create($request->validated());
        return $this->index();
    }

    public function edit(DowntimeMatrix $downtime_matrix)
    {
        $facilities = FacilityList::all();
        return $this->view('admin.downtime-matrix._edit', compact('downtime_matrix', 'facilities'));
    }

    public function update(UpdateDowntimeMatrixRequest $request, DowntimeMatrix $downtime_matrix)
    {
        $downtime_matrix->update($request->validated());
        return $this->index();
    }

    public function destroy(DowntimeMatrix $downtime_matrix)
    {
        $downtime_matrix->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.downtime-matrix.index', $data);
    }
}
