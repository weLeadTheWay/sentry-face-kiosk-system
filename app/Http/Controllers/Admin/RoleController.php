<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Role;
use App\Models\Permission;
use App\Services\RolePermissionService;

class RoleController extends Controller
{
    public function __construct(private RolePermissionService $service) {}

    public function index()
    {
        $roles = Role::paginate(config('sentry.pagination'));
        return $this->view('admin.roles._index', compact('roles'));
    }

    public function create()
    {
        return $this->view('admin.roles._create');
    }

    public function store(StoreRoleRequest $request)
    {
        Role::create($request->validated());
        return $this->index();
    }

    public function edit(Role $role)
    {
        $permissions = Permission::all();
        $rolePermissions = $role->permissions()->pluck('permission_id')->toArray();
        return $this->view('admin.roles._edit', compact('role', 'permissions', 'rolePermissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $role->update($request->validated());
        return $this->index();
    }

    public function permissions(Role $role)
    {
        $permissions = Permission::all();
        return $this->view('admin.roles._permissions', compact('role', 'permissions'));
    }

    public function updatePermissions(Role $role)
    {
        $permissionIds = request()->input('permission_ids', []);
        $this->service->assignPermissionsToRole($role, $permissionIds);
        return $this->index();
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.roles.index', $data);
    }
}
