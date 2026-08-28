<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Role;
use App\Models\Permission;
use App\Services\RolePermissionService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    use HandlesDataTablesRequest;

    public function __construct(private RolePermissionService $service) {}

    public function index()
    {
        return $this->view('admin.roles._index');
    }

    public function data(): JsonResponse
    {
        $base = Role::query()->select(['role_id', 'role_name', 'description']);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where('role_name', 'like', '%' . $search . '%');
        }

        $recordsFiltered = (clone $filtered)->count();

        // JS column positions: 0=role_name, 1=description, 2=actions[non-orderable].
        $orderableColumns = [0 => 'role_name', 1 => 'description'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'role_name');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (Role $role) => [
                'role_id' => $role->role_id,
                'role_name' => $role->role_name,
                'description' => $role->description,
            ])->all(),
        ]);
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
        $rolePermissions = $role->permissions()->pluck('permissions.permission_id')->toArray();
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
