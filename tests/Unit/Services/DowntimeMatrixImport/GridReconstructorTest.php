<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Services\DowntimeMatrixImport\GridReconstructionException;
use App\Services\DowntimeMatrixImport\GridReconstructor;
use Tests\TestCase;

class GridReconstructorTest extends TestCase
{
    private GridReconstructor $reconstructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconstructor = new GridReconstructor();
    }

    /**
     * A minimal 2-farm x 1-single-destination matrix, laid out with the
     * same coordinate/label conventions confirmed against the real sample
     * PDF: farm origins get Clean/Restricted sub-rows at x=2.25 (name) and
     * x=105.80 (area type); farm destinations get Downtime Area/Dormitory
     * sub-columns; a single-style destination has no sub-split.
     */
    private function minimalFragments(): array
    {
        return [
            ['text' => 'DESTINATION', 'x' => 66.78, 'y' => 310.45],
            ['text' => 'Alpha Farm', 'x' => 263.35, 'y' => 310.45],
            ['text' => 'Outside', 'x' => 1700.13, 'y' => 302.92],
            ['text' => 'ORIGIN', 'x' => 81.03, 'y' => 287.90],
            ['text' => 'Downtime Area', 'x' => 209.33, 'y' => 287.90],
            ['text' => 'Dormitory', 'x' => 315.13, 'y' => 287.90],

            // Alpha Farm origin (Clean/Restricted), values under itself blank (self-pair)
            ['text' => 'Clean Area', 'x' => 105.80, 'y' => 272.87],
            ['text' => 'Alpha Farm', 'x' => 2.25, 'y' => 272.87],
            ['text' => 'Restricted Area', 'x' => 105.80, 'y' => 258.57],

            // Beta Farm origin
            ['text' => 'Clean Area', 'x' => 105.80, 'y' => 244.30],
            ['text' => 'Beta Farm', 'x' => 2.25, 'y' => 244.30],
            ['text' => '12 hrs.', 'x' => 228.83, 'y' => 244.30],
            ['text' => '24 hrs.', 'x' => 322.63, 'y' => 244.30],
            ['text' => 'Restricted Area', 'x' => 105.80, 'y' => 230.02],
            ['text' => '12 hrs.', 'x' => 228.83, 'y' => 230.02],
            ['text' => '24 hrs.', 'x' => 322.63, 'y' => 230.02],
        ];
    }

    public function test_reconstructs_row_and_column_bands_from_a_well_formed_grid(): void
    {
        $result = $this->reconstructor->reconstruct($this->minimalFragments());

        // GridReconstructor's row_bands are raw, one per Clean/Restricted
        // line (4, for 2 farms) - pairing them into origin axis-entries is
        // MatrixGridParser's job, tested separately in MatrixGridParserTest.
        $this->assertCount(4, $result['row_bands']);
        $this->assertCount(3, $result['col_bands']); // Alpha AREA, Alpha DORMITORY, Outside (single)

        $this->assertSame('Alpha Farm', $result['row_bands'][0]['label_text']);
        $this->assertSame('Clean Area', $result['row_bands'][0]['area_type']);
        $this->assertSame('Restricted Area', $result['row_bands'][1]['area_type']);
        $this->assertSame('Beta Farm', $result['row_bands'][2]['label_text']);
        $this->assertSame('Clean Area', $result['row_bands'][2]['area_type']);

        $this->assertSame('Alpha Farm', $result['col_bands'][0]['top_name']);
        $this->assertSame('AREA', $result['col_bands'][0]['downtime_type']);
        $this->assertSame('Alpha Farm', $result['col_bands'][1]['top_name']);
        $this->assertSame('DORMITORY', $result['col_bands'][1]['downtime_type']);
        $this->assertSame('Outside', $result['col_bands'][2]['top_name']);
        $this->assertNull($result['col_bands'][2]['downtime_type']);
    }

    public function test_reconstructs_correctly_even_when_fragments_arrive_out_of_visual_order(): void
    {
        $fragments = $this->minimalFragments();
        shuffle($fragments);

        $result = $this->reconstructor->reconstruct($fragments);

        $this->assertCount(4, $result['row_bands']);
        $this->assertCount(3, $result['col_bands']);
        // Beta Farm's Clean-row Downtime Area cell (row 2, col 0) should read 12.
        $this->assertSame('12 hrs.', $result['grid']['2:0']);
    }

    public function test_throws_when_destination_anchor_is_missing(): void
    {
        $fragments = array_values(array_filter($this->minimalFragments(), fn ($f) => $f['text'] !== 'DESTINATION'));

        $this->expectException(GridReconstructionException::class);
        $this->reconstructor->reconstruct($fragments);
    }

    public function test_throws_when_origin_anchor_is_missing(): void
    {
        $fragments = array_values(array_filter($this->minimalFragments(), fn ($f) => $f['text'] !== 'ORIGIN'));

        $this->expectException(GridReconstructionException::class);
        $this->reconstructor->reconstruct($fragments);
    }

    public function test_throws_when_no_column_labels_exist_below_the_top_header_row(): void
    {
        $fragments = [
            ['text' => 'DESTINATION', 'x' => 66.78, 'y' => 310.45],
            ['text' => 'ORIGIN', 'x' => 81.03, 'y' => 287.90],
        ];

        $this->expectException(GridReconstructionException::class);
        $this->reconstructor->reconstruct($fragments);
    }
}
