<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeTypeRequest;
use App\Http\Requests\Admin\UpdateEmployeeTypeRequest;
use App\Models\EmployeeType;
use Illuminate\Http\JsonResponse;

class EmployeeTypeController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        return $this->view('admin.employee-types._index');
    }

    public function data(): JsonResponse
    {
        $base = EmployeeType::query()->select(['employee_type_id', 'employee_type_name']);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where('employee_type_name', 'like', '%' . $search . '%');
        }

        $recordsFiltered = (clone $filtered)->count();

        $rows = $filtered
            ->orderBy('employee_type_name', $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (EmployeeType $type) => [
                'employee_type_id' => $type->employee_type_id,
                'employee_type_name' => $type->employee_type_name,
            ])->all(),
        ]);
    }

    public function create()
    {
        return $this->view('admin.employee-types._create');
    }

    public function store(StoreEmployeeTypeRequest $request)
    {
        EmployeeType::create($request->validated());
        return $this->index();
    }

    public function edit(EmployeeType $employee_type)
    {
        return $this->view('admin.employee-types._edit', compact('employee_type'));
    }

    public function update(UpdateEmployeeTypeRequest $request, EmployeeType $employee_type)
    {
        $employee_type->update($request->validated());
        return $this->index();
    }

    public function destroy(EmployeeType $employee_type)
    {
        $employee_type->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.employee-types.index', $data);
    }
}
