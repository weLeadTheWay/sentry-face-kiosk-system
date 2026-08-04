<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function authenticate(string $email, string $password): ?User
    {
        $user = User::where('user_email', $email)
            ->where('is_active', true)
            ->first();

        if ($user && Hash::check($password, $user->hash_password)) {
            return $user;
        }

        return null;
    }

    public function createUser(array $data): User
    {
        return User::create([
            'role_id' => $data['role_id'],
            'user_name' => $data['user_name'],
            'user_email' => $data['user_email'],
            'hash_password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function updateUser(User $user, array $data): User
    {
        $updateData = [
            'role_id' => $data['role_id'] ?? $user->role_id,
            'user_name' => $data['user_name'] ?? $user->user_name,
            'user_email' => $data['user_email'] ?? $user->user_email,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ];

        if (isset($data['password'])) {
            $updateData['hash_password'] = Hash::make($data['password']);
        }

        $user->update($updateData);
        return $user;
    }
}
