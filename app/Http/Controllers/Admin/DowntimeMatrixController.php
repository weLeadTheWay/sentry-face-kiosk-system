<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeMatrixRequest;
use App\Http\Requests\Admin\UpdateDowntimeMatrixRequest;
use App\Models\DowntimeMatrix;
use App\Models\FarmList;

class DowntimeMatrixController extends Controller
{
    public function index()
    {
        $downtime_matrix_rules = DowntimeMatrix::with('originFarm', 'destinationFarm')->paginate(config('sentry.pagination'));
        return $this->view('admin.downtime-matrix._index', compact('downtime_matrix_rules'));
    }

    public function create()
    {
        $farms = FarmList::all();
        return $this->view('admin.downtime-matrix._create', compact('farms'));
    }

    public function store(StoreDowntimeMatrixRequest $request)
    {
        DowntimeMatrix::create($request->validated());
        return $this->index();
    }

    public function edit(DowntimeMatrix $downtime_matrix)
    {
        $farms = FarmList::all();
        return $this->view('admin.downtime-matrix._edit', compact('downtime_matrix', 'farms'));
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
