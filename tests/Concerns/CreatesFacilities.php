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
    /**
     * Defaults is_gs and is_truck to true - most tests using this trait
     * don't care about Gatesale/Truck eligibility and predate both flags'
     * enforcement; only tests specifically covering an eligibility check
     * need to pass false explicitly. This is a test-factory convenience
     * only - the real facility_list.is_gs/is_truck DB columns still default
     * to false in production (see their migrations); a fresh real facility
     * genuinely starts with both self-service processes disabled.
     *
     * Defaults is_break_enabled to true - it preserves the pre-existing
     * multi-break behavior every other test in this codebase already
     * assumes; only tests specifically covering the break restriction need
     * to pass false explicitly.
     */
    protected function createFacility(
        string $code,
        ?string $name = null,
        bool $isGs = true,
        bool $isBreakEnabled = true,
        bool $isTruck = true
    ): FacilityList {
        return FacilityList::firstOrCreate(
            ['facility_code' => $code],
            [
                'facility_name' => $name ?? $code,
                'facility_type_id' => FacilityType::firstOrCreate(['facility_type_name' => 'BVA'])->facility_type_id,
                'facility_category_id' => FacilityCategory::firstOrCreate(['facility_category_name' => 'FARM'])->facility_category_id,
                'is_rtl' => true,
                'is_gs' => $isGs,
                'is_break_enabled' => $isBreakEnabled,
                'is_truck' => $isTruck,
            ]
        );
    }
}
