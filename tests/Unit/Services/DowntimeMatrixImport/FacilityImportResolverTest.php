<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Models\FacilityAlias;
use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use App\Services\DowntimeMatrixImport\FacilityImportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class FacilityImportResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private FacilityImportResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FacilityImportResolver();
    }

    public function test_exact_facility_name_match(): void
    {
        $this->createFacility('MADERA', 'Madera');

        $result = $this->resolver->resolve('Madera');

        $this->assertSame('EXACT_NAME', $result->method);
        $this->assertSame('Madera', $result->facility->facility_name);
    }

    public function test_exact_alias_match(): void
    {
        $facility = $this->createFacility('SANPASCUAL', 'San Pascual');
        FacilityAlias::create(['alias_text' => 'SPLF', 'facility_id' => $facility->facility_id]);

        $result = $this->resolver->resolve('SPLF');

        $this->assertSame('EXACT_ALIAS', $result->method);
        $this->assertSame($facility->facility_id, $result->facility->facility_id);
    }

    public function test_normalized_name_match_strips_trailing_farm_and_parenthetical_qualifier(): void
    {
        $this->createFacility('MADERA', 'Madera');

        $result = $this->resolver->resolve('Madera Farm (Red-Act)');

        $this->assertSame('NORMALIZED_NAME', $result->method);
        $this->assertSame('Madera', $result->facility->facility_name);
    }

    public function test_normalized_alias_match(): void
    {
        $facility = $this->createFacility('SANPASCUAL', 'San Pascual');
        FacilityAlias::create(['alias_text' => 'SAN PASCUAL FARM VARIANT', 'facility_id' => $facility->facility_id]);

        $result = $this->resolver->resolve('San Pascual Farm Variant');

        $this->assertSame('NORMALIZED_ALIAS', $result->method);
        $this->assertSame($facility->facility_id, $result->facility->facility_id);
    }

    public function test_lep_dc_resolves_to_a_facility_group_not_a_single_facility(): void
    {
        $this->createDcWarehouse('DC Plaridel');
        $this->createDcWarehouse('DC Sta. Rosa');
        $this->createFacility('MADERA', 'Madera'); // an unrelated FARM, must not be picked up

        $result = $this->resolver->resolve('LEP, DC');

        $this->assertTrue($result->isGroup());
        $this->assertNull($result->facility);
        $this->assertSame('DC_WAREHOUSE', $result->groupCategory);

        $members = $this->resolver->resolveGroupMembers('DC_WAREHOUSE')->pluck('facility_name')->all();
        $this->assertEqualsCanonicalizing(['DC Plaridel', 'DC Sta. Rosa'], $members);
    }

    public function test_unmatched_when_no_facility_alias_or_group_applies(): void
    {
        $result = $this->resolver->resolve('Organikultura Area');

        $this->assertTrue($result->isUnmatched());
        $this->assertNull($result->facility);
    }

    public function test_outside_resolves_to_the_stationary_origin_sentinel_not_unmatched(): void
    {
        $result = $this->resolver->resolve('Outside (w/o any Farm Contact)');

        $this->assertTrue($result->isStationaryOrigin());
        $this->assertFalse($result->isUnmatched());
        $this->assertNull($result->facility);
    }

    public function test_ambiguous_when_normalized_name_matches_more_than_one_active_facility(): void
    {
        $this->createFacility('MADERA1', 'Madera');
        $this->createFacility('MADERA2', 'MADERA');

        $result = $this->resolver->resolve('Madera Farm');

        $this->assertTrue($result->isAmbiguous());
        $this->assertNull($result->facility);
        $this->assertCount(2, $result->ambiguousMatches);
    }

    public function test_inactive_facility_is_not_matched(): void
    {
        $facility = $this->createFacility('MADERA', 'Madera');
        $facility->update(['is_active' => false]);

        $result = $this->resolver->resolve('Madera');

        $this->assertTrue($result->isUnmatched());
    }

    private function createDcWarehouse(string $name): FacilityList
    {
        return FacilityList::create([
            'facility_code' => strtoupper(str_replace([' ', '.', ','], '', $name)),
            'facility_name' => $name,
            'facility_type_id' => FacilityType::firstOrCreate(['facility_type_name' => 'BVA'])->facility_type_id,
            'facility_category_id' => FacilityCategory::firstOrCreate(['facility_category_name' => 'DC_WAREHOUSE'])->facility_category_id,
            'is_rtl' => false,
            'is_active' => true,
        ]);
    }
}
