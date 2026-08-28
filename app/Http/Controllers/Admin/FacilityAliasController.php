<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacilityAliasRequest;
use App\Http\Requests\Admin\UpdateFacilityAliasRequest;
use App\Models\FacilityAlias;
use App\Models\FacilityList;
use Illuminate\Http\JsonResponse;

class FacilityAliasController extends Controller
{
    use HandlesDataTablesRequest;

    /**
     * Only queries the small facility_list lookup table (to populate the
     * Facility filter dropdown) - never facility_aliases itself. The Data
     * Table stays empty until the admin clicks Filter, which is what
     * actually calls data() below.
     */
    public function index()
    {
        $facilities = FacilityList::query()
            ->select(['facility_id', 'facility_name'])
            ->orderBy('facility_name')
            ->get();

        return $this->view('admin.facility-aliases._index', compact('facilities'));
    }

    /**
     * jQuery DataTables server-side processing endpoint. Returns the
     * {draw, recordsTotal, recordsFiltered, data} shape DataTables.js
     * expects - never Blade/HTML. Only reachable by an explicit Filter
     * click (see _index.blade.php); DataTables never auto-requests this
     * on init because the table isn't turned into a DataTable until then.
     */
    public function data(): JsonResponse
    {
        // facility_list is joined (not with()'d) so facility_name is a real,
        // sortable/filterable column in the same query - avoids an N+1 from
        // resolving ->facility->facility_name per row, and lets ORDER BY
        // reach the joined column directly.
        $base = FacilityAlias::query()
            ->select(['facility_aliases.alias_id', 'facility_aliases.alias_text', 'facility_aliases.facility_id', 'facility_list.facility_name'])
            ->leftJoin('facility_list', 'facility_list.facility_id', '=', 'facility_aliases.facility_id');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $facilityId = request()->query('facility_id');
        if ($facilityId !== null && $facilityId !== '' && $facilityId !== 'ALL') {
            $filtered->where('facility_aliases.facility_id', $facilityId);
        }

        $aliasText = trim((string) request()->query('alias_text', ''));
        if ($aliasText !== '') {
            $filtered->where('facility_aliases.alias_text', 'like', '%' . $aliasText . '%');
        }

        $recordsFiltered = (clone $filtered)->count();

        $orderableColumns = ['facility_aliases.alias_text', 'facility_list.facility_name'];
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
            'data' => $rows->map(fn (FacilityAlias $alias) => [
                'alias_id' => $alias->alias_id,
                'alias_text' => $alias->alias_text,
                'facility_id' => $alias->facility_id,
                'facility_name' => $alias->facility_name,
            ])->all(),
        ]);
    }

    public function create()
    {
        $facilities = FacilityList::all();
        return $this->view('admin.facility-aliases._create', compact('facilities'));
    }

    public function store(StoreFacilityAliasRequest $request)
    {
        FacilityAlias::create($request->validated());
        return $this->index();
    }

    public function edit(FacilityAlias $facility_alias)
    {
        $facilities = FacilityList::all();
        return $this->view('admin.facility-aliases._edit', compact('facility_alias', 'facilities'));
    }

    public function update(UpdateFacilityAliasRequest $request, FacilityAlias $facility_alias)
    {
        $facility_alias->update($request->validated());
        return $this->index();
    }

    public function destroy(FacilityAlias $facility_alias)
    {
        $facility_alias->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.facility-aliases.index', $data);
    }
}
