<?php

namespace Database\Seeders;

use App\Models\EmployeeType;
use Illuminate\Database\Seeder;

class EmployeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['Full-time', 'Part-time', 'Contractor', 'Temporary'];

        foreach ($types as $type) {
            EmployeeType::firstOrCreate(['employee_type_name' => $type]);
        }
    }
}
