<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacilityRequest;
use App\Http\Requests\Admin\UpdateFacilityRequest;
use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;

class FacilityController extends Controller
{
    public function index()
    {
        $facilities = FacilityList::with(['facilityType', 'facilityCategory'])->paginate(config('sentry.pagination'));
        return $this->view('admin.facilities._index', compact('facilities'));
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
