<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class VisitorSession extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_session';
    protected $primaryKey = 'visitor_session_id';
    public $incrementing = true;

    protected $fillable = [
        'visitor_request_id',
        'session_status',
        'first_in',
        'last_out',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function visitorRequest(): BelongsTo
    {
        return $this->belongsTo(VisitorRequest::class, 'visitor_request_id', 'visitor_request_id');
    }

    public function entryLogs(): HasMany
    {
        return $this->hasMany(VisitorEntryLog::class, 'visitor_session_id', 'visitor_session_id');
    }
}
