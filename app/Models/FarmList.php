<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FarmList extends Model
{
    use HasFactory, Auditable;

    protected $table = 'farm_list';
    protected $primaryKey = 'farm_id';
    public $incrementing = true;

    protected $fillable = [
        'farm_code',
        'farm_name',
        'location',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
