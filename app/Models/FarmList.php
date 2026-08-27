<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmList extends Model
{
    use HasFactory, Auditable;

    protected $table = 'farm_list';
    protected $primaryKey = 'farm_id';
    public $incrementing = true;

    protected $fillable = [
        'farm_code',
        'farm_name',
        'location',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function kioskDevices(): HasMany
    {
        return $this->hasMany(KioskDevice::class, 'farm_id', 'farm_id');
    }

    public function originDowntimeMatrixRules(): HasMany
    {
        return $this->hasMany(DowntimeMatrix::class, 'origin_farm_id', 'farm_id');
    }

    public function destinationDowntimeMatrixRules(): HasMany
    {
        return $this->hasMany(DowntimeMatrix::class, 'destination_farm_id', 'farm_id');
    }

    public function downtimeStationaryRules(): HasMany
    {
        return $this->hasMany(DowntimeStationary::class, 'assigned_farm_id', 'farm_id');
    }
}
