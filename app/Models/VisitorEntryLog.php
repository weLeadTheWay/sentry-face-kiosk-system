<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class VisitorEntryLog extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_entry_logs';
    protected $primaryKey = 'visitor_log_id';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'visitor_session_id',
        'kiosk_id',
        'movement_type',
        'action',
        'remarks',
        'photo',
        'datetime',
    ];

    protected function casts(): array
    {
        return [
            'datetime' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VisitorSession::class, 'visitor_session_id', 'visitor_session_id');
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_id', 'kiosk_id');
    }
}
