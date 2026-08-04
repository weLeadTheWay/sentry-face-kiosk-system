<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskDevice extends Model
{
    use HasFactory, Auditable;

    protected $table = 'kiosk_device';
    protected $primaryKey = 'kiosk_id';
    public $incrementing = true;

    protected $fillable = [
        'farm_id',
        'device_name',
        'device_type',
        'serial_number',
        'public_ip',
        'status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'farm_id', 'farm_id');
    }
}
