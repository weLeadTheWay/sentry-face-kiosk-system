<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeType extends Model
{
    use HasFactory, Auditable;

    protected $table = 'employee_type';
    protected $primaryKey = 'employee_type_id';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'employee_type_name',
    ];
}
