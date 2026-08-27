<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityAlias extends Model
{
    use HasFactory, Auditable;

    protected $table = 'facility_aliases';
    protected $primaryKey = 'alias_id';
    public $incrementing = true;

    protected $fillable = [
        'alias_text',
        'facility_id',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'facility_id', 'facility_id');
    }
}
