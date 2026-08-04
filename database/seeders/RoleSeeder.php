<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(
            ['role_name' => 'Administrator'],
            ['description' => 'Full system access']
        );

        Role::firstOrCreate(
            ['role_name' => 'Manager'],
            ['description' => 'Farm and employee management']
        );

        Role::firstOrCreate(
            ['role_name' => 'Supervisor'],
            ['description' => 'Limited farm management']
        );
    }
}
