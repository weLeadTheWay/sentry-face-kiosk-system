<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Resolves facilities, classifies, normalizes downtime, and assigns a final
 * resolution_status to every candidate row produced by MatrixGridParser.
 *
 * A row can trigger more than one finding at once (e.g. resolved via
 * normalized match AND has a Clean/Restricted mismatch AND is a duplicate).
 * All applicable findings are collected and their messages combined; the
 * final resolution_status is whichever finding ranks highest in this fixed
 * precedence: INVALID > AMBIGUOUS > UNMATCHED > WARNING > VALID > INFO.
 * INFO is not a real problem (e.g. "only a minimum threshold could be
 * derived for this cell") - it never wins the status, but its message is
 * still included alongside whatever the winning status's messages are.
 */
class ImportValidator
{
    private const PRECEDENCE = [
        'INVALID' => 5,
        'AMBIGUOUS' => 4,
        'UNMATCHED' => 3,
        'WARNING' => 2,
        'VALID' => 1,
        'INFO' => 0,
    ];

    public function __construct(
        private readonly FacilityImportResolver $facilityResolver,
        private readonly RuleClassifier $ruleClassifier,
        private readonly DowntimeNormalizer $normalizer,
    ) {
    }

    /**
     * @param array $candidateRows raw candidate rows from MatrixGridParser
     * @return array{rows: array<int, array>, counts: array<string, int>}
     */
    public function validate(array $candidateRows): array
    {
        $rows = [];

        foreach ($candidateRows as $candidate) {
            $rows[] = $this->resolveCandidate($candidate);
        }

        $this->flagDuplicates($rows);

        $counts = ['VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0];

        foreach ($rows as &$row) {
            [$status, $message] = $this->resolveStatus($row['_findings']);
            $row['resolution_status'] = $status;
            $row['validation_message'] = $message;
            unset($row['_findings']);
            $counts[$status]++;
        }
        unset($row);

        return ['rows' => $rows, 'counts' => $counts];
    }

    private function resolveCandidate(array $candidate): array
    {
        $origin = $this->facilityResolver->resolve($candidate['origin_raw_label']);
        $destination = $this->facilityResolver->resolve($candidate['destination_raw_label']);

        $findings = array_merge(
            $this->facilityFindings($origin),
            $this->facilityFindings($destination),
        );

        $ruleType = $this->ruleClassifier->classify($origin, $destination);

        if ($origin->isSingleFacility() && $destination->isSingleFacility()
            && $origin->facility->facility_id === $destination->facility->facility_id) {
            $findings[] = [
                'status' => 'INVALID',
                'message' => 'Origin and destination resolve to the same facility.',
            ];
        }

        $normalized = $candidate['origin_shape'] === 'DUAL'
            ? $this->normalizer->normalizeFarmToFarm($candidate['areas'])
            : $this->normalizer->normalizeStationary($candidate['areas']);

        $findings = array_merge($findings, $normalized['findings']);

        if (!empty($candidate['forced_no_downtime_required'])) {
            // MatrixGridParser synthesized this row even though the source
            // cell was blank (e.g. every farm -> "LEP, DC") - here, blank
            // means "no downtime required", a real business fact, not
            // missing data, so it's surfaced as INFO rather than left to
            // look like an unremarked absence.
            $findings[] = [
                'status' => 'INFO',
                'message' => 'No downtime required for this cell.',
            ];
        }

        return [
            'rule_type' => $ruleType,
            'origin_raw_label' => $candidate['origin_raw_label'],
            'destination_raw_label' => $candidate['destination_raw_label'],
            'origin_facility_id' => $origin->facility?->facility_id,
            'destination_facility_id' => $destination->facility?->facility_id,
            'origin_resolution_method' => $origin->method,
            'destination_resolution_method' => $destination->method,
            'origin_facility_group_category' => $origin->isGroup() ? $origin->groupCategory : null,
            'destination_facility_group_category' => $destination->isGroup() ? $destination->groupCategory : null,
            'downtime_area_hours' => $normalized['downtime_area_hours'],
            'dormitory_hours' => $normalized['dormitory_hours'],
            'minimum_downtime' => $normalized['minimum_downtime'],
            'maximum_downtime' => $normalized['maximum_downtime'],
            'clean_downtime_area_hours' => $normalized['clean_downtime_area_hours'],
            'clean_dormitory_hours' => $normalized['clean_dormitory_hours'],
            'restricted_downtime_area_hours' => $normalized['restricted_downtime_area_hours'],
            'restricted_dormitory_hours' => $normalized['restricted_dormitory_hours'],
            '_findings' => $findings,
        ];
    }

    private function facilityFindings(FacilityResolutionResult $result): array
    {
        if ($result->isAmbiguous()) {
            return [[
                'status' => 'AMBIGUOUS',
                'message' => "'{$result->rawLabel}' matched more than one facility (" . implode(', ', $result->ambiguousMatches) . ') - cannot resolve automatically.',
            ]];
        }

        if ($result->isUnmatched()) {
            return [[
                'status' => 'UNMATCHED',
                'message' => "No facility, alias, or facility group matched '{$result->rawLabel}' - add a facility_alias or correct the source, then re-upload.",
            ]];
        }

        if ($result->isNormalizedMatch()) {
            return [[
                'status' => 'WARNING',
                'message' => "Resolved '{$result->rawLabel}' via normalized match - verify this is the intended facility.",
            ]];
        }

        return [];
    }

    private function flagDuplicates(array &$rows): void
    {
        $seen = [];

        foreach ($rows as &$row) {
            $originKey = $row['origin_facility_id'] ?? $row['origin_facility_group_category'] ?? strtoupper(trim($row['origin_raw_label']));
            $destinationKey = $row['destination_facility_id'] ?? $row['destination_facility_group_category'] ?? strtoupper(trim($row['destination_raw_label']));
            $key = $originKey . '|' . $destinationKey;

            if (isset($seen[$key])) {
                $row['_findings'][] = [
                    'status' => 'WARNING',
                    'message' => 'Duplicate of another row in this import (origin/destination pair repeated).',
                ];
            }
            $seen[$key] = true;
        }
        unset($row);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveStatus(array $findings): array
    {
        if (empty($findings)) {
            return ['VALID', null];
        }

        $bestStatus = 'VALID';
        $bestRank = self::PRECEDENCE['VALID'];

        foreach ($findings as $finding) {
            $rank = self::PRECEDENCE[$finding['status']];
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestStatus = $finding['status'];
            }
        }

        $messages = array_values(array_unique(array_column($findings, 'message')));

        return [$bestStatus, implode(' ', $messages)];
    }
}
