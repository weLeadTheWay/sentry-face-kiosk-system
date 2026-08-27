<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeStationaryRequest;
use App\Http\Requests\Admin\UpdateDowntimeStationaryRequest;
use App\Models\DowntimeStationary;
use App\Models\FarmList;

class DowntimeStationaryController extends Controller
{
    public function index()
    {
        $downtime_stationary_rules = DowntimeStationary::with('assignedFarm')->paginate(config('sentry.pagination'));
        return $this->view('admin.downtime-stationary._index', compact('downtime_stationary_rules'));
    }

    public function create()
    {
        $farms = FarmList::all();
        return $this->view('admin.downtime-stationary._create', compact('farms'));
    }

    public function store(StoreDowntimeStationaryRequest $request)
    {
        DowntimeStationary::create($request->validated());
        return $this->index();
    }

    public function edit(DowntimeStationary $downtime_stationary)
    {
        $farms = FarmList::all();
        return $this->view('admin.downtime-stationary._edit', compact('downtime_stationary', 'farms'));
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
