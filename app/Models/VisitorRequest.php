<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class VisitorRequest extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_request';
    protected $primaryKey = 'visitor_request_id';
    public $incrementing = true;

    protected $fillable = [
        'directory_id',
        'visitor_id',
        'qr_url',
        'farm_id',
        'host_name',
        'purpose',
        'visit_datetime',
        'departure_datetime',
        'registration_token',
        'approval_status',
        'request_status',
    ];

    protected function casts(): array
    {
        return [
            'visit_datetime' => 'datetime',
            'departure_datetime' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(UserDirectory::class, 'directory_id', 'directory_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'farm_id', 'farm_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'visitor_request_id', 'visitor_request_id');
    }
}
