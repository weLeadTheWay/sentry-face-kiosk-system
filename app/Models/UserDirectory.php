<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'plate_no',
        'employee_no',
        'visitor_type_id',
        'employee_type_id',
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

    /**
     * New normalized visitor-specific data (Step 1 of the ERD restructure).
     * This is initially just a synchronized copy - visitor_type_id/company/
     * plate_no on this model remain the application's source of truth until
     * a later step migrates each read/write path over to visitor_profile.
     */
    public function visitorProfile(): HasOne
    {
        return $this->hasOne(VisitorProfile::class, 'directory_id', 'directory_id');
    }
}
