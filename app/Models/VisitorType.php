<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisitorType extends Model
{
    use HasFactory;

    protected $table = 'visitor_type';
    protected $primaryKey = 'visitor_type_id';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = ['visitor_type_name'];
}
