<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmAlias extends Model
{
    use HasFactory, Auditable;

    protected $table = 'farm_aliases';
    protected $primaryKey = 'alias_id';
    public $incrementing = true;

    protected $fillable = [
        'alias_text',
        'farm_id',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'farm_id', 'farm_id');
    }
}
