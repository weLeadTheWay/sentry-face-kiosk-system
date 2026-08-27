<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DowntimeStationary extends Model
{
    use HasFactory, Auditable;

    protected $table = 'downtime_stationary';
    protected $primaryKey = 'rule_id';
    public $incrementing = true;

    protected $fillable = [
        'assigned_facility_id',
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

    public function assignedFacility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'assigned_facility_id', 'facility_id');
    }
}
