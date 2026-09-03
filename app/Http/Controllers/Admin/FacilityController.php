<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacilityRequest;
use App\Http\Requests\Admin\UpdateFacilityRequest;
use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use Illuminate\Http\JsonResponse;

class FacilityController extends Controller
{
    use HandlesDataTablesRequest;

    /**
     * Only queries the small facility_type/facility_category lookup tables
     * (to populate the filter dropdowns) - never facility_list itself. The
     * Data Table stays empty until the admin clicks Filter.
     */
    public function index()
    {
        $facility_types = FacilityType::query()->select(['facility_type_id', 'facility_type_name'])->orderBy('facility_type_name')->get();
        $facility_categories = FacilityCategory::query()->select(['facility_category_id', 'facility_category_name'])->orderBy('facility_category_name')->get();

        return $this->view('admin.facilities._index', compact('facility_types', 'facility_categories'));
    }

    public function data(): JsonResponse
    {
        $base = FacilityList::query()
            ->select(['facility_id', 'facility_type_id', 'facility_category_id', 'facility_code', 'facility_name', 'location', 'is_rtl', 'is_active', 'is_gs'])
            ->with([
                'facilityType:facility_type_id,facility_type_name',
                'facilityCategory:facility_category_id,facility_category_name',
            ]);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where(function ($q) use ($search) {
                $q->where('facility_code', 'like', '%' . $search . '%')
                    ->orWhere('facility_name', 'like', '%' . $search . '%');
            });
        }

        $facilityTypeId = request()->query('facility_type_id');
        if ($facilityTypeId !== null && $facilityTypeId !== '' && $facilityTypeId !== 'ALL') {
            $filtered->where('facility_type_id', $facilityTypeId);
        }

        $facilityCategoryId = request()->query('facility_category_id');
        if ($facilityCategoryId !== null && $facilityCategoryId !== '' && $facilityCategoryId !== 'ALL') {
            $filtered->where('facility_category_id', $facilityCategoryId);
        }

        $status = request()->query('status');
        if ($status === 'ACTIVE') {
            $filtered->where('is_active', true);
        } elseif ($status === 'INACTIVE') {
            $filtered->where('is_active', false);
        }

        $recordsFiltered = (clone $filtered)->count();

        $orderableColumns = ['facility_code', 'facility_name'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, $orderableColumns[0]);

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (FacilityList $facility) => [
                'facility_id' => $facility->facility_id,
                'facility_code' => $facility->facility_code,
                'facility_name' => $facility->facility_name,
                'facility_type' => $facility->facilityType->facility_type_name ?? null,
                'facility_category' => $facility->facilityCategory->facility_category_name ?? null,
                'is_rtl' => (bool) $facility->is_rtl,
                'location' => $facility->location,
                'is_active' => (bool) $facility->is_active,
                'is_gs' => (bool) $facility->is_gs,
            ])->all(),
        ]);
    }

    public function create()
    {
        $facility_types = FacilityType::all();
        $facility_categories = FacilityCategory::all();
        return $this->view('admin.facilities._create', compact('facility_types', 'facility_categories'));
    }

    public function store(StoreFacilityRequest $request)
    {
        FacilityList::create($request->validated());
        return $this->index();
    }

    public function edit(FacilityList $facility)
    {
        $facility_types = FacilityType::all();
        $facility_categories = FacilityCategory::all();
        return $this->view('admin.facilities._edit', compact('facility', 'facility_types', 'facility_categories'));
    }

    public function update(UpdateFacilityRequest $request, FacilityList $facility)
    {
        $facility->update($request->validated());
        return $this->index();
    }

    public function destroy(FacilityList $facility)
    {
        $facility->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.facilities.index', $data);
    }
}
