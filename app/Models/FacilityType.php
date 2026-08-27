<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityType extends Model
{
    use HasFactory, Auditable;

    protected $table = 'facility_type';
    protected $primaryKey = 'facility_type_id';
    public $incrementing = true;

    protected $fillable = [
        'facility_type_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(FacilityList::class, 'facility_type_id', 'facility_type_id');
    }
}
