<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('role_name', 'Administrator')->first();

        if ($adminRole) {
            $allPermissions = Permission::pluck('permission_id')->toArray();
            $adminRole->permissions()->sync($allPermissions);
        }
    }
}
