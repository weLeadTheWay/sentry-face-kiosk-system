<?php

namespace Database\Seeders;

use App\Models\IdentityType;
use Illuminate\Database\Seeder;

class IdentityTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['Employee', 'Visitor', 'Contractor', 'Government'];

        foreach ($types as $type) {
            IdentityType::firstOrCreate(['identity_type_name' => $type]);
        }
    }
}
