<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreIdentityTypeRequest;
use App\Http\Requests\Admin\UpdateIdentityTypeRequest;
use App\Models\IdentityType;

class IdentityTypeController extends Controller
{
    public function index()
    {
        $identity_types = IdentityType::paginate(config('sentry.pagination'));
        return $this->view('admin.identity-types._index', compact('identity_types'));
    }

    public function create()
    {
        return $this->view('admin.identity-types._create');
    }

    public function store(StoreIdentityTypeRequest $request)
    {
        IdentityType::create($request->validated());
        return $this->index();
    }

    public function edit(IdentityType $identity_type)
    {
        return $this->view('admin.identity-types._edit', compact('identity_type'));
    }

    public function update(UpdateIdentityTypeRequest $request, IdentityType $identity_type)
    {
        $identity_type->update($request->validated());
        return $this->index();
    }

    public function destroy(IdentityType $identity_type)
    {
        $identity_type->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.identity-types.index', $data);
    }
}
