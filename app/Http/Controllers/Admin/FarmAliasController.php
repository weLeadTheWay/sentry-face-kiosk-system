<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFarmAliasRequest;
use App\Http\Requests\Admin\UpdateFarmAliasRequest;
use App\Models\FarmAlias;
use App\Models\FarmList;

class FarmAliasController extends Controller
{
    public function index()
    {
        $aliases = FarmAlias::with('farm')->paginate(config('sentry.pagination'));
        return $this->view('admin.farm-aliases._index', compact('aliases'));
    }

    public function create()
    {
        $farms = FarmList::all();
        return $this->view('admin.farm-aliases._create', compact('farms'));
    }

    public function store(StoreFarmAliasRequest $request)
    {
        FarmAlias::create($request->validated());
        return $this->index();
    }

    public function edit(FarmAlias $farm_alias)
    {
        $farms = FarmList::all();
        return $this->view('admin.farm-aliases._edit', compact('farm_alias', 'farms'));
    }

    public function update(UpdateFarmAliasRequest $request, FarmAlias $farm_alias)
    {
        $farm_alias->update($request->validated());
        return $this->index();
    }

    public function destroy(FarmAlias $farm_alias)
    {
        $farm_alias->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.farm-aliases.index', $data);
    }
}
