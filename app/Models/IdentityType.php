<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdentityType extends Model
{
    use HasFactory, Auditable;

    protected $table = 'identity_type';
    protected $primaryKey = 'identity_type_id';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'identity_type_name',
    ];
}
