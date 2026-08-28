<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Services\DowntimeMatrixImport\DowntimeNormalizer;
use App\Services\DowntimeMatrixImport\FacilityImportResolver;
use App\Services\DowntimeMatrixImport\ImportValidator;
use App\Services\DowntimeMatrixImport\ParsedDowntimeValue;
use App\Services\DowntimeMatrixImport\RuleClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class ImportValidatorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private ImportValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ImportValidator(
            new FacilityImportResolver(),
            new RuleClassifier(),
            new DowntimeNormalizer(),
        );
    }

    private function candidate(string $origin, string $destination, string $shape, array $areas): array
    {
        return [
            'origin_raw_label' => $origin,
            'destination_raw_label' => $destination,
            'origin_shape' => $shape,
            'areas' => $areas,
        ];
    }

    public function test_invalid_outranks_warning_and_both_messages_are_preserved(): void
    {
        $this->createFacility('SATURN', 'Saturn');
        // "Madera Farm" only normalizes to a match if a "Madera" facility
        // exists; leave it absent from the DB and instead force a
        // WARNING via a normalized-alias-style match plus an INVALID
        // downtime value, using two facilities that both exist.
        $this->createFacility('VENUS', 'Venus');

        $candidates = [
            $this->candidate('Venus Farm', 'Saturn Farm', 'SINGLE', [
                // "Venus Farm" resolves via NORMALIZED_NAME (WARNING-level finding),
                // and downtime_area is invalid text (INVALID-level finding).
                'downtime_area' => ParsedDowntimeValue::parse('not a number'),
                'dormitory' => ParsedDowntimeValue::blank(),
            ]),
        ];

        $result = $this->validator->validate($candidates);
        $row = $result['rows'][0];

        $this->assertSame('INVALID', $row['resolution_status']);
        $this->assertStringContainsString('normalized match', $row['validation_message']);
        $this->assertStringContainsString('Downtime Area value is present but could not be read as a number', $row['validation_message']);
    }

    public function test_duplicate_origin_destination_pair_is_flagged_warning_on_the_repeat_only(): void
    {
        $this->createFacility('SATURN', 'Saturn');
        $this->createFacility('VENUS', 'Venus');

        $areas = ['downtime_area' => ParsedDowntimeValue::parse('12 hrs.'), 'dormitory' => ParsedDowntimeValue::blank()];

        $candidates = [
            $this->candidate('Saturn', 'Venus', 'SINGLE', $areas),
            $this->candidate('Saturn', 'Venus', 'SINGLE', $areas),
        ];

        $result = $this->validator->validate($candidates);

        $this->assertSame('VALID', $result['rows'][0]['resolution_status']);
        $this->assertSame('WARNING', $result['rows'][1]['resolution_status']);
        $this->assertStringContainsString('Duplicate', $result['rows'][1]['validation_message']);
    }

    public function test_counts_are_aggregated_per_status(): void
    {
        $this->createFacility('SATURN', 'Saturn');

        $candidates = [
            $this->candidate('Saturn', 'NoSuchPlace', 'SINGLE', [
                'downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
                'dormitory' => ParsedDowntimeValue::blank(),
            ]),
        ];

        $result = $this->validator->validate($candidates);

        $this->assertSame(1, $result['counts']['UNMATCHED']);
        $this->assertSame(0, $result['counts']['VALID']);
    }
}
