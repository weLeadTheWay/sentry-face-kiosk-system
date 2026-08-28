<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Services\DowntimeMatrixImport\MatrixGridParser;
use Tests\TestCase;

class MatrixGridParserTest extends TestCase
{
    private MatrixGridParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MatrixGridParser();
    }

    /**
     * Two farms (Alpha, Beta) x one single-style destination (Outside).
     * Alpha's self-pair and Alpha->Outside are left blank (as the real
     * sample PDF does); Beta->Alpha carries real values.
     */
    private function reconstructed(array $gridOverrides = []): array
    {
        $rowBands = [
            ['y' => 272.87, 'label_text' => 'Alpha Farm', 'area_type' => 'Clean Area'],
            ['y' => 258.57, 'label_text' => '(Green)', 'area_type' => 'Restricted Area'],
            ['y' => 244.30, 'label_text' => 'Beta Farm', 'area_type' => 'Clean Area'],
            ['y' => 230.02, 'label_text' => '(Red-Act)', 'area_type' => 'Restricted Area'],
        ];

        $colBands = [
            ['x' => 209.33, 'top_name' => 'Alpha Farm', 'downtime_type' => 'AREA'],
            ['x' => 315.13, 'top_name' => 'Alpha Farm', 'downtime_type' => 'DORMITORY'],
            ['x' => 1700.13, 'top_name' => 'Outside', 'downtime_type' => null],
        ];

        $grid = array_merge([
            // Beta (rows 2,3) -> Alpha (cols 0,1): populated
            '2:0' => '12 hrs.',
            '2:1' => '24 hrs.',
            '3:0' => '12 hrs.',
            '3:1' => '24 hrs.',
        ], $gridOverrides);

        return ['row_bands' => $rowBands, 'col_bands' => $colBands, 'grid' => $grid];
    }

    public function test_populated_cells_produce_candidate_rows(): void
    {
        $candidates = $this->parser->parse($this->reconstructed());

        $betaToAlpha = array_values(array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Beta') && $c['destination_raw_label'] === 'Alpha Farm'
        ));

        $this->assertCount(1, $betaToAlpha);
        $this->assertSame('DUAL', $betaToAlpha[0]['origin_shape']);
        $this->assertSame(12.0, $betaToAlpha[0]['areas']['clean_downtime_area']->hours);
    }

    public function test_blank_self_pair_is_skipped_entirely(): void
    {
        $candidates = $this->parser->parse($this->reconstructed());

        $selfPair = array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Alpha') && $c['destination_raw_label'] === 'Alpha Farm'
        );

        $this->assertEmpty($selfPair, 'A blank self-pair cell must not produce a candidate row at all.');
    }

    public function test_blank_farm_origin_to_non_farm_destination_is_skipped(): void
    {
        $candidates = $this->parser->parse($this->reconstructed());

        $alphaToOutside = array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Alpha') && $c['destination_raw_label'] === 'Outside'
        );

        $this->assertEmpty($alphaToOutside);
    }

    public function test_a_populated_self_pair_is_still_emitted_not_silently_dropped(): void
    {
        // Same structural position as the blank self-pair above, but this
        // time carrying real data - cell emission is value-based, not
        // position-based, so this must still surface as a candidate row.
        $candidates = $this->parser->parse($this->reconstructed(['0:0' => '12 hrs.']));

        $selfPair = array_values(array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Alpha') && $c['destination_raw_label'] === 'Alpha Farm'
        ));

        $this->assertCount(1, $selfPair, 'A populated cell in a normally-blank position must still be emitted, never silently dropped.');
    }

    public function test_a_populated_farm_to_non_farm_destination_is_still_emitted(): void
    {
        $candidates = $this->parser->parse($this->reconstructed(['0:2' => '12 hrs.']));

        $alphaToOutside = array_values(array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Alpha') && $c['destination_raw_label'] === 'Outside'
        ));

        $this->assertCount(1, $alphaToOutside);
        $this->assertSame('DUAL', $alphaToOutside[0]['origin_shape']);
        $this->assertSame(12.0, $alphaToOutside[0]['areas']['clean_downtime_area']->hours);
        $this->assertTrue($alphaToOutside[0]['areas']['clean_dormitory']->isBlank, 'A single-style destination column has no dormitory reading to carry.');
    }

    public function test_every_farm_gets_a_blank_row_to_a_forced_inclusion_destination_like_lep_dc(): void
    {
        $reconstructed = $this->reconstructed();
        // Add a third destination column configured as "always include" -
        // matches the real config's "LEP, DC" entry.
        $reconstructed['col_bands'][] = ['x' => 1900.0, 'top_name' => 'LEP, DC', 'downtime_type' => null];
        // Left blank in the grid entirely - both Alpha and Beta to LEP, DC.

        $candidates = $this->parser->parse($reconstructed);

        foreach (['Alpha', 'Beta'] as $farm) {
            $toLepDc = array_values(array_filter(
                $candidates,
                fn ($c) => str_starts_with($c['origin_raw_label'], $farm) && $c['destination_raw_label'] === 'LEP, DC'
            ));

            $this->assertCount(1, $toLepDc, "expected a forced-inclusion row for {$farm} -> LEP, DC even though blank");
            $this->assertTrue($toLepDc[0]['forced_no_downtime_required'] ?? false);
            $this->assertTrue($toLepDc[0]['areas']['clean_downtime_area']->isBlank);
        }

        // "Outside" is NOT configured for forced inclusion - stays skipped when blank.
        $alphaToOutside = array_filter(
            $candidates,
            fn ($c) => str_starts_with($c['origin_raw_label'], 'Alpha') && $c['destination_raw_label'] === 'Outside'
        );
        $this->assertEmpty($alphaToOutside);
    }
}
