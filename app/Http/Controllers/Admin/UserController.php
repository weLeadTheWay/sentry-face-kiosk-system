<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Models\Role;
use App\Services\AuthService;

class UserController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function index()
    {
        $users = User::with('role')->paginate(config('sentry.pagination'));
        return $this->view('admin.users._index', compact('users'));
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
