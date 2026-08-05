<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeTypeRequest;
use App\Http\Requests\Admin\UpdateEmployeeTypeRequest;
use App\Models\EmployeeType;

class EmployeeTypeController extends Controller
{
    public function index()
    {
        $employee_types = EmployeeType::paginate(config('sentry.pagination'));
        return $this->view('admin.employee-types._index', compact('employee_types'));
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
