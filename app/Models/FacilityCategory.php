<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityCategory extends Model
{
    use HasFactory, Auditable;

    protected $table = 'facility_category';
    protected $primaryKey = 'facility_category_id';
    public $incrementing = true;

    protected $fillable = [
        'facility_category_name',
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
        return $this->hasMany(FacilityList::class, 'facility_category_id', 'facility_category_id');
    }
}
