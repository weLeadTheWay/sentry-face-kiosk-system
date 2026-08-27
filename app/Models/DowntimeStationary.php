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
        'assigned_farm_id',
        'minimum_downtime_hours',
        'max_downtime_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'minimum_downtime_hours' => 'decimal:2',
            'max_downtime_hours' => 'decimal:2',
        ];
    }

    public function assignedFarm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'assigned_farm_id', 'farm_id');
    }
}
