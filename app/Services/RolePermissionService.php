<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RolePermissionService
{
    public function assignPermissionsToRole(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
    }

    public function getRolePermissions(Role $role): Collection
    {
        return $role->permissions()->get();
    }

    public function hasPermission(Role $role, string $permissionKey): bool
    {
        return $role->permissions()
            ->where('permission_key', $permissionKey)
            ->exists();
    }
}
