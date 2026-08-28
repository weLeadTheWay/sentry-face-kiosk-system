<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreIdentityTypeRequest;
use App\Http\Requests\Admin\UpdateIdentityTypeRequest;
use App\Models\IdentityType;
use Illuminate\Http\JsonResponse;

class IdentityTypeController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        return $this->view('admin.identity-types._index');
    }

    public function data(): JsonResponse
    {
        $base = IdentityType::query()->select(['identity_type_id', 'identity_type_name']);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where('identity_type_name', 'like', '%' . $search . '%');
        }

        $recordsFiltered = (clone $filtered)->count();

        $rows = $filtered
            ->orderBy('identity_type_name', $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (IdentityType $type) => [
                'identity_type_id' => $type->identity_type_id,
                'identity_type_name' => $type->identity_type_name,
            ])->all(),
        ]);
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
