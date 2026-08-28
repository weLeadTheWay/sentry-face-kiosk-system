<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Normalizes raw parsed downtime hour values into final min/max downtime
 * columns, and consolidates the Clean Area / Restricted Area dual readings
 * a FARM_TO_FARM cell carries in the source matrix.
 *
 * Per the matrix's actual business meaning (confirmed against the source
 * PDF's own footnote: downtime-area time is required first, dormitory time
 * is fulfilled afterward), Downtime Area and Dormitory are NOT simply two
 * arithmetic inputs that must both be present:
 *   - Downtime Area alone  -> minimum threshold only, no maximum.
 *   - Dormitory alone      -> ALSO a minimum threshold only (the dormitory
 *                              value stands in as "hours required before
 *                              the next area may be entered"), no maximum.
 *   - Both present         -> minimum = Downtime Area; maximum = Downtime
 *                              Area + Dormitory (the full requirement).
 *   - Neither present      -> nothing derivable for this specific reading.
 * A missing Downtime Area is therefore NOT automatically INVALID - only a
 * value that is PRESENT but unparseable/negative is (a real data-quality
 * problem, distinct from a value that was simply never provided).
 */
class DowntimeNormalizer
{
    /**
     * @param array{downtime_area: ParsedDowntimeValue, dormitory: ParsedDowntimeValue} $areas
     */
    public function normalizeStationary(array $areas): array
    {
        $derived = $this->deriveMinMax($areas['downtime_area'], $areas['dormitory']);

        return [
            'downtime_area_hours' => $derived['downtime_area_hours'],
            'dormitory_hours' => $derived['dormitory_hours'],
            'minimum_downtime' => $derived['minimum'],
            'maximum_downtime' => $derived['maximum'],
            'clean_downtime_area_hours' => null,
            'clean_dormitory_hours' => null,
            'restricted_downtime_area_hours' => null,
            'restricted_dormitory_hours' => null,
            'findings' => $derived['findings'],
        ];
    }

    /**
     * @param array{
     *     clean_downtime_area: ParsedDowntimeValue,
     *     clean_dormitory: ParsedDowntimeValue,
     *     restricted_downtime_area: ParsedDowntimeValue,
     *     restricted_dormitory: ParsedDowntimeValue,
     * } $areas
     */
    public function normalizeFarmToFarm(array $areas): array
    {
        $clean = $this->deriveMinMax($areas['clean_downtime_area'], $areas['clean_dormitory']);
        $restricted = $this->deriveMinMax($areas['restricted_downtime_area'], $areas['restricted_dormitory']);

        $findings = array_merge($clean['findings'], $restricted['findings']);

        $downtimeAreaHours = null;
        $dormitoryHours = null;
        $minimum = null;
        $maximum = null;

        $cleanHasResult = $clean['minimum'] !== null;
        $restrictedHasResult = $restricted['minimum'] !== null;

        if ($cleanHasResult && $restrictedHasResult) {
            $sameMin = abs($clean['minimum'] - $restricted['minimum']) < 0.001;
            $sameMax = $this->numbersMatch($clean['maximum'], $restricted['maximum']);

            if ($sameMin && $sameMax) {
                $downtimeAreaHours = $clean['downtime_area_hours'];
                $dormitoryHours = $clean['dormitory_hours'];
                $minimum = $clean['minimum'];
                $maximum = $clean['maximum'];
            } else {
                $findings[] = [
                    'status' => 'WARNING',
                    'message' => 'Clean Area and Restricted Area downtime values differ for this origin/destination pair - both raw readings preserved, not merged.',
                ];
            }
        } elseif ($cleanHasResult) {
            // Only Clean Area yielded a derivable reading - use it directly,
            // there is nothing on the Restricted side to disagree with.
            $downtimeAreaHours = $clean['downtime_area_hours'];
            $dormitoryHours = $clean['dormitory_hours'];
            $minimum = $clean['minimum'];
            $maximum = $clean['maximum'];
        } elseif ($restrictedHasResult) {
            $downtimeAreaHours = $restricted['downtime_area_hours'];
            $dormitoryHours = $restricted['dormitory_hours'];
            $minimum = $restricted['minimum'];
            $maximum = $restricted['maximum'];
        }

        return [
            'downtime_area_hours' => $downtimeAreaHours,
            'dormitory_hours' => $dormitoryHours,
            'minimum_downtime' => $minimum,
            'maximum_downtime' => $maximum,
            'clean_downtime_area_hours' => $areas['clean_downtime_area']->isValid() ? $areas['clean_downtime_area']->hours : null,
            'clean_dormitory_hours' => $areas['clean_dormitory']->isValid() ? $areas['clean_dormitory']->hours : null,
            'restricted_downtime_area_hours' => $areas['restricted_downtime_area']->isValid() ? $areas['restricted_downtime_area']->hours : null,
            'restricted_dormitory_hours' => $areas['restricted_dormitory']->isValid() ? $areas['restricted_dormitory']->hours : null,
            'findings' => $findings,
        ];
    }

    /**
     * @return array{downtime_area_hours: ?float, dormitory_hours: ?float, minimum: ?float, maximum: ?float, findings: array}
     */
    private function deriveMinMax(ParsedDowntimeValue $downtimeArea, ParsedDowntimeValue $dormitory): array
    {
        $findings = [];

        if ($downtimeArea->isPresent() && !$downtimeArea->isValid()) {
            $findings[] = [
                'status' => 'INVALID',
                'message' => "Downtime Area value is present but could not be read as a number for this cell (found '{$downtimeArea->rawText}').",
            ];
        }

        if ($dormitory->isPresent() && !$dormitory->isValid()) {
            $findings[] = [
                'status' => 'INVALID',
                'message' => "Dormitory value is present but could not be read as a number for this cell (found '{$dormitory->rawText}').",
            ];
        }

        $downtimeAreaUsable = $downtimeArea->isValid();
        $dormitoryUsable = $dormitory->isValid();

        if ($downtimeAreaUsable && $dormitoryUsable) {
            return [
                'downtime_area_hours' => $downtimeArea->hours,
                'dormitory_hours' => $dormitory->hours,
                'minimum' => $downtimeArea->hours,
                'maximum' => $downtimeArea->hours + $dormitory->hours,
                'findings' => $findings,
            ];
        }

        if ($downtimeAreaUsable) {
            // Dormitory not usable (blank or unparseable) - only a minimum
            // threshold is determinable from Downtime Area alone.
            $findings[] = [
                'status' => 'INFO',
                'message' => "Minimum of {$this->formatHours($downtimeArea->hours)} hours confirmed from Downtime Area; no Dormitory value found for this cell, so no maximum threshold could be determined.",
            ];

            return [
                'downtime_area_hours' => $downtimeArea->hours,
                'dormitory_hours' => null,
                'minimum' => $downtimeArea->hours,
                'maximum' => null,
                'findings' => $findings,
            ];
        }

        if ($dormitoryUsable) {
            // Downtime Area not usable (blank or unparseable) - the
            // Dormitory value stands in as the minimum threshold: this many
            // hours are required before the next area may be entered.
            $findings[] = [
                'status' => 'INFO',
                'message' => "Minimum of {$this->formatHours($dormitory->hours)} hours required before entering Restricted Area; no maximum threshold could be determined for this cell.",
            ];

            return [
                'downtime_area_hours' => null,
                'dormitory_hours' => $dormitory->hours,
                'minimum' => $dormitory->hours,
                'maximum' => null,
                'findings' => $findings,
            ];
        }

        // Neither value is usable - nothing derivable for this reading.
        // Not itself flagged INVALID here (the individual present-but-
        // unparseable checks above already cover real data problems; a
        // reading that's simply blank on both sides - e.g. the Clean side
        // of a pair where only Restricted carries data - has nothing wrong
        // with it, there's just nothing to report for this half).
        return [
            'downtime_area_hours' => null,
            'dormitory_hours' => null,
            'minimum' => null,
            'maximum' => null,
            'findings' => $findings,
        ];
    }

    private function numbersMatch(?float $a, ?float $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return abs($a - $b) < 0.001;
    }

    private function formatHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2), '0'), '.');
    }
}
