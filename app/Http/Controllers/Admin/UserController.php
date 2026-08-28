<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Models\Role;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    use HandlesDataTablesRequest;

    public function __construct(private AuthService $authService) {}

    public function index()
    {
        $roles = Role::query()->select(['role_id', 'role_name'])->orderBy('role_name')->get();

        return $this->view('admin.users._index', compact('roles'));
    }

    public function data(): JsonResponse
    {
        $base = User::query()
            ->select(['user_id', 'user_name', 'user_email', 'role_id', 'is_active'])
            ->with('role:role_id,role_name');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where(function ($q) use ($search) {
                $q->where('user_name', 'like', '%' . $search . '%')
                    ->orWhere('user_email', 'like', '%' . $search . '%');
            });
        }

        $roleId = request()->query('role_id');
        if ($roleId !== null && $roleId !== '' && $roleId !== 'ALL') {
            $filtered->where('role_id', $roleId);
        }

        $status = request()->query('status');
        if ($status === 'ACTIVE') {
            $filtered->where('is_active', true);
        } elseif ($status === 'INACTIVE') {
            $filtered->where('is_active', false);
        }

        $recordsFiltered = (clone $filtered)->count();

        // JS column positions: 0=user_name, 1=user_email, 2=role_name[non-orderable], 3=status[non], 4=actions[non].
        $orderableColumns = [0 => 'user_name', 1 => 'user_email'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'user_name');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (User $user) => [
                'user_id' => $user->user_id,
                'user_name' => $user->user_name,
                'user_email' => $user->user_email,
                'role_name' => $user->role->role_name ?? null,
                'is_active' => (bool) $user->is_active,
            ])->all(),
        ]);
    }

    public function create()
    {
        $roles = Role::all();
        return $this->view('admin.users._create', compact('roles'));
    }

    public function store(StoreUserRequest $request)
    {
        $this->authService->createUser($request->validated());
        return $this->index();
    }

    public function edit(User $user)
    {
        $roles = Role::all();
        return $this->view('admin.users._edit', compact('user', 'roles'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authService->updateUser($user, $request->validated());
        return $this->index();
    }

    public function destroy(User $user)
    {
        $user->delete();
        return $this->index();
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.users.index', $data);
    }
}
