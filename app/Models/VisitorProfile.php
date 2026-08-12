<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class VisitorProfile extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_profile';
    protected $primaryKey = 'visitor_profile_id';
    public $incrementing = true;

    protected $fillable = [
        'directory_id',
        'visitor_type_id',
        'company',
        'plate_no',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(UserDirectory::class, 'directory_id', 'directory_id');
    }

    public function visitorType(): BelongsTo
    {
        return $this->belongsTo(VisitorType::class, 'visitor_type_id', 'visitor_type_id');
    }
}
