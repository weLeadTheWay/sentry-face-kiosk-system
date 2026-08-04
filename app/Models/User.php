<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, Auditable;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $incrementing = true;

    protected $fillable = [
        'role_id',
        'user_name',
        'user_email',
        'hash_password',
        'is_active',
        'remember_token',
    ];

    protected $hidden = [
        'hash_password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'hash_password';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }

    public function hasPermission(string $permissionKey): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->permissions()
            ->where('permission_key', $permissionKey)
            ->exists();
    }

    public function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }
        return false;
    }
}
