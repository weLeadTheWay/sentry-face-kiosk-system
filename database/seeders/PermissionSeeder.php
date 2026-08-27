<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['permission_key' => 'dashboard.view', 'permission_name' => 'View Dashboard', 'description' => 'Access main dashboard'],
            ['permission_key' => 'roles.manage', 'permission_name' => 'Manage Roles', 'description' => 'Create, edit, delete roles'],
            ['permission_key' => 'permissions.manage', 'permission_name' => 'Manage Permissions', 'description' => 'Assign permissions to roles'],
            ['permission_key' => 'users.manage', 'permission_name' => 'Manage Users', 'description' => 'Create, edit, delete users'],
            ['permission_key' => 'farms.manage', 'permission_name' => 'Manage Farms', 'description' => 'Create, edit, delete farms'],
            ['permission_key' => 'facilities.manage', 'permission_name' => 'Manage Facilities', 'description' => 'Create, edit, delete facilities and facility aliases'],
            ['permission_key' => 'kiosks.manage', 'permission_name' => 'Manage Kiosks', 'description' => 'Create, edit, delete kiosk devices'],
            ['permission_key' => 'biosecurity.manage', 'permission_name' => 'Manage Biosecurity Rules', 'description' => 'Create, edit, delete biosecurity rules'],
            ['permission_key' => 'identity_types.manage', 'permission_name' => 'Manage Identity Types', 'description' => 'Create, edit, delete identity types'],
            ['permission_key' => 'employee_types.manage', 'permission_name' => 'Manage Employee Types', 'description' => 'Create, edit, delete employee types'],
            ['permission_key' => 'audit_logs.view', 'permission_name' => 'View Audit Logs', 'description' => 'Access audit log records'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['permission_key' => $permission['permission_key']],
                $permission
            );
        }
    }
}
