<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\Auditable;

class UserDirectory extends Model
{
    use HasFactory, Auditable;

    protected $table = 'user_directory';
    protected $primaryKey = 'directory_id';
    public $incrementing = true;

    protected $fillable = [
        'identity_type_id',
        'person_reference',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'company',
        'employee_no',
        'visitor_type_id',
        'employee_type_id',
        'login_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booting()
    {
        static::creating(function (self $model) {
            if (!$model->login_id) {
                $model->login_id = self::generateLoginId();
            }
        });
    }

    private static function generateLoginId(): string
    {
        for ($i = 0; $i < 3; $i++) {
            $loginId = Str::upper(Str::random(8));
            if (!self::where('login_id', $loginId)->exists()) {
                return $loginId;
            }
        }
        throw new \Exception('Failed to generate unique login_id after 3 attempts');
    }

    public function identityType(): BelongsTo
    {
        return $this->belongsTo(IdentityType::class, 'identity_type_id', 'identity_type_id');
    }

    public function visitorType(): BelongsTo
    {
        return $this->belongsTo(VisitorType::class, 'visitor_type_id', 'visitor_type_id');
    }

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class, 'employee_type_id', 'employee_type_id');
    }

    public function faceProfiles(): HasMany
    {
        return $this->hasMany(FaceProfile::class, 'directory_id', 'directory_id');
    }

    public function visitorRequests(): HasMany
    {
        return $this->hasMany(VisitorRequest::class, 'directory_id', 'directory_id');
    }
}
