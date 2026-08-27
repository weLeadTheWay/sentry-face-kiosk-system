<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacilityAliasRequest;
use App\Http\Requests\Admin\UpdateFacilityAliasRequest;
use App\Models\FacilityAlias;
use App\Models\FacilityList;

class FacilityAliasController extends Controller
{
    public function index()
    {
        $facility_aliases = FacilityAlias::with('facility')->paginate(config('sentry.pagination'));
        return $this->view('admin.facility-aliases._index', compact('facility_aliases'));
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
