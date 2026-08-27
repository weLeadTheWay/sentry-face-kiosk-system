<?php

namespace Database\Seeders;

use App\Models\FacilityCategory;
use Illuminate\Database\Seeder;

class FacilityCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = ['FARM', 'PLANT', 'DC_WAREHOUSE', 'OTHER'];

        foreach ($categories as $category) {
            FacilityCategory::firstOrCreate(['facility_category_name' => $category]);
        }
    }
}
