<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiosecurityRule extends Model
{
    use HasFactory, Auditable;

    protected $table = 'biosecurity_rules';
    protected $primaryKey = 'rule_id';
    public $incrementing = true;

    protected $fillable = [
        'origin_farm_id',
        'destination_farm_id',
        'area_type',
        'minimum_downtime',
        'maximum_downtime',
        'access_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function originFarm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'origin_farm_id', 'farm_id');
    }

    public function destinationFarm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'destination_farm_id', 'farm_id');
    }
}
