<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('role_name', 'Administrator')->first();

        if ($adminRole) {
            User::firstOrCreate(
                ['user_email' => env('ADMIN_EMAIL', 'admin@sentry.local')],
                [
                    'role_id' => $adminRole->role_id,
                    'user_name' => env('ADMIN_NAME', 'Administrator'),
                    'hash_password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                    'is_active' => true,
                ]
            );
        }
    }
}
