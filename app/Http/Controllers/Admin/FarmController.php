<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFarmRequest;
use App\Http\Requests\Admin\UpdateFarmRequest;
use App\Models\FarmList;

class FarmController extends Controller
{
    public function index()
    {
        $farms = FarmList::paginate(config('sentry.pagination'));
        return $this->view('admin.farms._index', compact('farms'));
    }

    public function create()
    {
        return $this->view('admin.farms._create');
    }

    public function store(StoreFarmRequest $request)
    {
        FarmList::create($request->validated());
        return $this->index();
    }

    public function edit(FarmList $farm)
    {
        return $this->view('admin.farms._edit', compact('farm'));
    }

    public function update(UpdateFarmRequest $request, FarmList $farm)
    {
        $farm->update($request->validated());
        return $this->index();
    }

    public function destroy(FarmList $farm)
    {
        $farm->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.farms.index', $data);
    }
}
