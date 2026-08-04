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

    public function originBiosecurityRules(): HasMany
    {
        return $this->hasMany(BiosecurityRule::class, 'origin_farm_id', 'farm_id');
    }

    public function destinationBiosecurityRules(): HasMany
    {
        return $this->hasMany(BiosecurityRule::class, 'destination_farm_id', 'farm_id');
    }
}
