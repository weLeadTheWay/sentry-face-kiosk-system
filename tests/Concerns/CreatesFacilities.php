<?php

namespace Tests\Concerns;

use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;

/**
 * facility_list requires facility_type_id/facility_category_id (both NOT
 * NULL) - this centralizes the BVA/FARM lookup-or-create so every test that
 * used to build a bare FarmList row doesn't have to repeat it.
 */
trait CreatesFacilities
{
    protected function createFacility(string $code, ?string $name = null): FacilityList
    {
        return FacilityList::firstOrCreate(
            ['facility_code' => $code],
            [
                'facility_name' => $name ?? $code,
                'facility_type_id' => FacilityType::firstOrCreate(['facility_type_name' => 'BVA'])->facility_type_id,
                'facility_category_id' => FacilityCategory::firstOrCreate(['facility_category_name' => 'FARM'])->facility_category_id,
                'is_rtl' => true,
            ]
        );
    }
}
