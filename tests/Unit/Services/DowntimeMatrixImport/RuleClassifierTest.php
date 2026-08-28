<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Models\FacilityCategory;
use App\Models\FacilityType;
use App\Services\DowntimeMatrixImport\FacilityResolutionResult;
use App\Services\DowntimeMatrixImport\RuleClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class RuleClassifierTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private RuleClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new RuleClassifier();
    }

    public function test_farm_to_farm_when_both_sides_resolve_to_a_farm(): void
    {
        $origin = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');
        $destination = FacilityResolutionResult::forFacility('Venus Farm', $this->createFacility('VENUS', 'Venus'), 'EXACT_NAME');

        $this->assertSame('FARM_TO_FARM', $this->classifier->classify($origin, $destination));
    }

    public function test_stationary_when_origin_is_the_recognized_outside_sentinel_and_destination_is_a_farm(): void
    {
        $origin = FacilityResolutionResult::forStationaryOrigin('Outside');
        $destination = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');

        $this->assertSame('STATIONARY', $this->classifier->classify($origin, $destination));
    }

    public function test_others_when_origin_is_a_non_sentinel_unmatched_label_and_destination_is_a_farm(): void
    {
        // "Organikultura Area"/"Fabrication" are non-farm origins but NOT
        // the recognized Stationary sentinel, and not a farm/group either -
        // they land in Others, not Farm-to-Farm and not Stationary.
        $origin = FacilityResolutionResult::forUnmatched('Organikultura Area');
        $destination = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');

        $this->assertSame('OTHERS', $this->classifier->classify($origin, $destination));
    }

    public function test_farm_to_farm_when_origin_is_a_facility_group_and_destination_is_a_farm(): void
    {
        // "LEP, DC" (a DC_WAREHOUSE group) belongs to Farm-to-Farm.
        $origin = FacilityResolutionResult::forGroup('LEP, DC', 'DC_WAREHOUSE', 'All active DC Warehouse facilities', 'DC Warehouses');
        $destination = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');

        $this->assertSame('FARM_TO_FARM', $this->classifier->classify($origin, $destination));
    }

    public function test_farm_to_farm_when_destination_is_a_facility_group_and_origin_is_a_farm(): void
    {
        $origin = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');
        $destination = FacilityResolutionResult::forGroup('LEP, DC', 'DC_WAREHOUSE', 'All active DC Warehouse facilities', 'DC Warehouses');

        $this->assertSame('FARM_TO_FARM', $this->classifier->classify($origin, $destination));
    }

    public function test_others_when_neither_side_is_a_farm_or_group_and_origin_is_not_the_outside_sentinel(): void
    {
        $origin = FacilityResolutionResult::forUnmatched('Organikultura Area');
        $destination = FacilityResolutionResult::forUnmatched('Fabrication');

        $this->assertSame('OTHERS', $this->classifier->classify($origin, $destination));
    }

    public function test_others_when_the_outside_sentinel_origin_pairs_with_a_non_farm_destination(): void
    {
        // "Outside" only triggers STATIONARY when paired with a real farm
        // destination - not with a group or another non-farm entity.
        $origin = FacilityResolutionResult::forStationaryOrigin('Outside');
        $destination = FacilityResolutionResult::forGroup('LEP, DC', 'DC_WAREHOUSE', 'All active DC Warehouse facilities', 'DC Warehouses');

        $this->assertSame('OTHERS', $this->classifier->classify($origin, $destination));
    }

    public function test_others_when_a_resolved_facility_is_not_a_farm_on_either_side(): void
    {
        $plant = \App\Models\FacilityList::create([
            'facility_code' => 'PLANT1',
            'facility_name' => 'Sacobia',
            'facility_type_id' => FacilityType::firstOrCreate(['facility_type_name' => 'BVA'])->facility_type_id,
            'facility_category_id' => FacilityCategory::firstOrCreate(['facility_category_name' => 'PLANT'])->facility_category_id,
            'is_rtl' => false,
        ]);

        $origin = FacilityResolutionResult::forFacility('Sacobia', $plant, 'EXACT_NAME');
        $destination = FacilityResolutionResult::forUnmatched('Organikultura Area');

        $this->assertSame('OTHERS', $this->classifier->classify($origin, $destination));
    }

    public function test_others_when_only_the_destination_is_a_farm_but_origin_is_not_farm_or_group(): void
    {
        // A destination being a farm is not, on its own, enough for
        // Farm-to-Farm - the origin must actually be farm-like too.
        $origin = FacilityResolutionResult::forUnmatched('Fabrication');
        $destination = FacilityResolutionResult::forFacility('Saturn Farm', $this->createFacility('SATURN', 'Saturn'), 'EXACT_NAME');

        $this->assertSame('OTHERS', $this->classifier->classify($origin, $destination));
    }
}
