<?php

namespace Database\Seeders;

use App\Models\FacilityType;
use Illuminate\Database\Seeder;

class FacilityTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['BVA', 'GEFI', 'GEFI-LIVE', 'PS', 'FEEDMILL', 'GP-HY', 'PS-HY', 'IBG'];

        foreach ($types as $type) {
            FacilityType::firstOrCreate(['facility_type_name' => $type]);
        }
    }
}
