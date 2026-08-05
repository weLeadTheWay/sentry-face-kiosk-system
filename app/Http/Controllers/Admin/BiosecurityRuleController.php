<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBiosecurityRuleRequest;
use App\Http\Requests\Admin\UpdateBiosecurityRuleRequest;
use App\Models\BiosecurityRule;
use App\Models\FarmList;

class BiosecurityRuleController extends Controller
{
    public function index()
    {
        $rules = BiosecurityRule::with('originFarm', 'destinationFarm')->paginate(config('sentry.pagination'));
        return $this->view('admin.biosecurity-rules._index', compact('rules'));
    }

    public function create()
    {
        $farms = FarmList::all();
        return $this->view('admin.biosecurity-rules._create', compact('farms'));
    }

    public function store(StoreBiosecurityRuleRequest $request)
    {
        BiosecurityRule::create($request->validated());
        return $this->index();
    }

    public function edit(BiosecurityRule $biosecurity_rule)
    {
        $farms = FarmList::all();
        return $this->view('admin.biosecurity-rules._edit', compact('biosecurity_rule', 'farms'));
    }

    public function update(UpdateBiosecurityRuleRequest $request, BiosecurityRule $biosecurity_rule)
    {
        $biosecurity_rule->update($request->validated());
        return $this->index();
    }

    public function destroy(BiosecurityRule $biosecurity_rule)
    {
        $biosecurity_rule->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.biosecurity-rules.index', $data);
    }
}
