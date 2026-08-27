<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DowntimeMatrix extends Model
{
    use HasFactory, Auditable;

    protected $table = 'downtime_matrix';
    protected $primaryKey = 'rule_id';
    public $incrementing = true;

    protected $fillable = [
        'origin_facility_id',
        'destination_facility_id',
        'minimum_downtime',
        'maximum_downtime',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'minimum_downtime' => 'decimal:2',
            'maximum_downtime' => 'decimal:2',
        ];
    }

    public function originFacility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'origin_facility_id', 'facility_id');
    }

    public function destinationFacility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'destination_facility_id', 'facility_id');
    }
}
