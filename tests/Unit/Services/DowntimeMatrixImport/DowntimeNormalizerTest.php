<?php

namespace Tests\Unit\Services\DowntimeMatrixImport;

use App\Services\DowntimeMatrixImport\DowntimeNormalizer;
use App\Services\DowntimeMatrixImport\ParsedDowntimeValue;
use Tests\TestCase;

class DowntimeNormalizerTest extends TestCase
{
    private DowntimeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new DowntimeNormalizer();
    }

    public function test_stationary_derives_minimum_and_maximum_from_area_and_dormitory(): void
    {
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::parse('48 hrs.'),
            'dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
        ]);

        $this->assertSame(48.0, $result['downtime_area_hours']);
        $this->assertSame(24.0, $result['dormitory_hours']);
        $this->assertSame(48.0, $result['minimum_downtime']);
        $this->assertSame(72.0, $result['maximum_downtime']);
        $this->assertEmpty($result['findings']);
    }

    public function test_stationary_missing_downtime_area_derives_minimum_from_dormitory_alone(): void
    {
        // Downtime Area missing is NOT automatically invalid: Dormitory
        // stands in as the minimum threshold (hours required before the
        // next area may be entered) when it's the only value present.
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::blank(),
            'dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
        ]);

        $this->assertSame(24.0, $result['minimum_downtime']);
        $this->assertNull($result['maximum_downtime']);
        $this->assertNotEmpty($result['findings']);
        $this->assertSame('INFO', $result['findings'][0]['status']);
        $this->assertStringContainsString('Minimum of 24 hours required before entering Restricted Area', $result['findings'][0]['message']);
    }

    public function test_stationary_missing_dormitory_alone_derives_minimum_only_no_maximum(): void
    {
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
            'dormitory' => ParsedDowntimeValue::blank(),
        ]);

        $this->assertSame(12.0, $result['minimum_downtime']);
        $this->assertNull($result['maximum_downtime']);
        $this->assertNotEmpty($result['findings']);
        $this->assertSame('INFO', $result['findings'][0]['status']);
    }

    public function test_stationary_both_blank_derives_nothing_with_no_findings(): void
    {
        // Not itself reachable via the real parse pipeline (a cell this
        // blank is never emitted as a candidate at all), but the normalizer
        // must still behave sanely if called with nothing to work from.
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::blank(),
            'dormitory' => ParsedDowntimeValue::blank(),
        ]);

        $this->assertNull($result['minimum_downtime']);
        $this->assertNull($result['maximum_downtime']);
        $this->assertEmpty($result['findings']);
    }

    public function test_stationary_present_but_unparseable_downtime_area_is_invalid_even_with_a_valid_dormitory(): void
    {
        // Distinguishes "never provided" (fine, derive from the other
        // value) from "provided but garbage" (a real data-quality problem,
        // always flagged) - matches the real PDF's "Cinnamon Farm" stray
        // text glitch, where Dormitory still has a real, usable value.
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::parse('Cinnamon Farm'),
            'dormitory' => ParsedDowntimeValue::parse('12 hrs.'),
        ]);

        $invalid = array_filter($result['findings'], fn ($f) => $f['status'] === 'INVALID');
        $this->assertNotEmpty($invalid, 'A present-but-unparseable Downtime Area value must still be flagged INVALID.');
        // The Dormitory value is still usable, so a best-effort minimum is
        // still surfaced for the admin to see alongside the INVALID flag.
        $this->assertSame(12.0, $result['minimum_downtime']);
        $this->assertNull($result['maximum_downtime']);
    }

    public function test_stationary_negative_downtime_area_is_invalid(): void
    {
        $result = $this->normalizer->normalizeStationary([
            'downtime_area' => ParsedDowntimeValue::parse('-12 hrs.'),
            'dormitory' => ParsedDowntimeValue::blank(),
        ]);

        $this->assertNull($result['minimum_downtime']);
        $this->assertSame('INVALID', $result['findings'][0]['status']);
    }

    public function test_farm_to_farm_consolidates_identical_clean_and_restricted_readings(): void
    {
        $result = $this->normalizer->normalizeFarmToFarm([
            'clean_downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
            'clean_dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
            'restricted_downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
            'restricted_dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
        ]);

        $this->assertSame(12.0, $result['minimum_downtime']);
        $this->assertSame(36.0, $result['maximum_downtime']);
        $this->assertEmpty($result['findings']);
        $this->assertSame(12.0, $result['clean_downtime_area_hours']);
        $this->assertSame(12.0, $result['restricted_downtime_area_hours']);
    }

    public function test_farm_to_farm_never_silently_merges_differing_clean_and_restricted_readings(): void
    {
        $result = $this->normalizer->normalizeFarmToFarm([
            'clean_downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
            'clean_dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
            'restricted_downtime_area' => ParsedDowntimeValue::parse('48 hrs.'),
            'restricted_dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
        ]);

        $this->assertNull($result['minimum_downtime']);
        $this->assertNull($result['maximum_downtime']);
        $this->assertSame(12.0, $result['clean_downtime_area_hours']);
        $this->assertSame(48.0, $result['restricted_downtime_area_hours']);

        $warnings = array_filter($result['findings'], fn ($f) => $f['status'] === 'WARNING');
        $this->assertNotEmpty($warnings);
    }

    public function test_farm_to_farm_uses_the_only_side_with_a_derivable_reading_when_the_other_is_blank(): void
    {
        // Clean is entirely blank (no data at all) while Restricted has a
        // full reading - there's nothing on the Clean side to disagree
        // with, so Restricted's reading wins directly, not left null.
        $result = $this->normalizer->normalizeFarmToFarm([
            'clean_downtime_area' => ParsedDowntimeValue::blank(),
            'clean_dormitory' => ParsedDowntimeValue::blank(),
            'restricted_downtime_area' => ParsedDowntimeValue::parse('12 hrs.'),
            'restricted_dormitory' => ParsedDowntimeValue::parse('24 hrs.'),
        ]);

        $this->assertSame(12.0, $result['minimum_downtime']);
        $this->assertSame(36.0, $result['maximum_downtime']);
        $this->assertEmpty(array_filter($result['findings'], fn ($f) => $f['status'] === 'WARNING'));
    }

    public function test_parsed_downtime_value_flags_non_numeric_text_as_invalid_not_blank(): void
    {
        $value = ParsedDowntimeValue::parse('Cinnamon Farm');

        $this->assertFalse($value->isBlank);
        $this->assertFalse($value->isValid());
        $this->assertTrue($value->isPresent());
    }

    public function test_parsed_downtime_value_treats_empty_string_as_blank(): void
    {
        $value = ParsedDowntimeValue::parse('   ');

        $this->assertTrue($value->isBlank);
        $this->assertFalse($value->isPresent());
    }
}
