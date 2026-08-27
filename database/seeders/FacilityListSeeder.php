<?php

namespace Database\Seeders;

use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use Illuminate\Database\Seeder;

class FacilityListSeeder extends Seeder
{
    /**
     * facility_code values are not specified in the source classification
     * data - these are generated placeholders (TYPE-SLUG) so the unique
     * constraint is satisfied; rename via the facility admin UI once one
     * exists, whenever the real codes are supplied.
     */
    public function run(): void
    {
        $facilities = [
            // BVA - RTL Farms
            ['name' => 'Saturn', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-SATURN'],
            ['name' => 'Venus', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-VENUS'],
            ['name' => 'Cinnamon', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-CINNAMON'],
            ['name' => 'Mars', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-MARS'],
            ['name' => 'Madera', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-MADERA'],
            ['name' => 'Rosemary', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-ROSEMARY'],
            ['name' => 'San Pascual', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-SANPASCUAL'],
            ['name' => 'Victory', 'type' => 'BVA', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'BVA-VICTORY'],

            // BVA - DC Warehouses
            ['name' => 'DC Plaridel', 'type' => 'BVA', 'category' => 'DC_WAREHOUSE', 'is_rtl' => false, 'code' => 'BVA-DCPLARIDEL'],
            ['name' => 'DC Sta. Rosa', 'type' => 'BVA', 'category' => 'DC_WAREHOUSE', 'is_rtl' => false, 'code' => 'BVA-DCSTAROSA'],

            // BVA - Plants
            ['name' => 'S&B Cebu Plant', 'type' => 'BVA', 'category' => 'PLANT', 'is_rtl' => false, 'code' => 'BVA-SNBCEBU'],
            ['name' => 'Sacobia', 'type' => 'BVA', 'category' => 'PLANT', 'is_rtl' => false, 'code' => 'BVA-SACOBIA'],

            // GEFI
            ['name' => 'GEFI - MCP', 'type' => 'GEFI', 'category' => 'PLANT', 'is_rtl' => false, 'code' => 'GEFI-MCP'],

            // GEFI-LIVE - RTL Farms
            ['name' => 'Buenavista Farm', 'type' => 'GEFI-LIVE', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'GEFILIVE-BUENAVISTA'],
            ['name' => 'Kelsey', 'type' => 'GEFI-LIVE', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'GEFILIVE-KELSEY'],
            ['name' => 'Forestierra', 'type' => 'GEFI-LIVE', 'category' => 'FARM', 'is_rtl' => true, 'code' => 'GEFILIVE-FORESTIERRA'],
        ];

        foreach ($facilities as $facility) {
            $type = FacilityType::where('facility_type_name', $facility['type'])->firstOrFail();
            $category = FacilityCategory::where('facility_category_name', $facility['category'])->firstOrFail();

            FacilityList::firstOrCreate(
                ['facility_name' => $facility['name']],
                [
                    'facility_type_id' => $type->facility_type_id,
                    'facility_category_id' => $category->facility_category_id,
                    'facility_code' => $facility['code'],
                    'is_rtl' => $facility['is_rtl'],
                ]
            );
        }
    }
}
