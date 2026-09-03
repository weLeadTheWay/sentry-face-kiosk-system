<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityList extends Model
{
    use HasFactory, Auditable;

    protected $table = 'facility_list';
    protected $primaryKey = 'facility_id';
    public $incrementing = true;

    protected $fillable = [
        'facility_type_id',
        'facility_category_id',
        'facility_code',
        'facility_name',
        'location',
        'is_rtl',
        'is_active',
        'is_gs',
    ];

    protected function casts(): array
    {
        return [
            'is_rtl' => 'boolean',
            'is_active' => 'boolean',
            'is_gs' => 'boolean',
        ];
    }

    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id', 'facility_type_id');
    }

    public function facilityCategory(): BelongsTo
    {
        return $this->belongsTo(FacilityCategory::class, 'facility_category_id', 'facility_category_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(FacilityAlias::class, 'facility_id', 'facility_id');
    }

    public function kioskDevices(): HasMany
    {
        return $this->hasMany(KioskDevice::class, 'facility_id', 'facility_id');
    }

    public function originDowntimeMatrixRules(): HasMany
    {
        return $this->hasMany(DowntimeMatrix::class, 'origin_facility_id', 'facility_id');
    }

    public function destinationDowntimeMatrixRules(): HasMany
    {
        return $this->hasMany(DowntimeMatrix::class, 'destination_facility_id', 'facility_id');
    }

    public function downtimeStationaryRules(): HasMany
    {
        return $this->hasMany(DowntimeStationary::class, 'assigned_facility_id', 'facility_id');
    }
}
