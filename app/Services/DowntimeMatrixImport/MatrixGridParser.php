<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Walks GridReconstructor's raw row/column bands and grid, groups them
 * into origin/destination axis entries (pairing a farm's "Clean Area"/
 * "Restricted Area" row bands, and a farm's "Downtime Area"/"Dormitory"
 * column bands, into one logical entry each), and emits one candidate row
 * per origin/destination combination that has at least one non-blank
 * downtime value.
 *
 * Cell emission is value-based, not position-based: every combination is
 * considered, and only genuinely blank cells are skipped. A self-pair or a
 * farm-origin x non-farm-destination cell is only absent from the output
 * because it happens to be blank in the source PDF - if it ever carries a
 * value, it is emitted like any other candidate and resolved/classified/
 * validated on its own merits downstream.
 *
 * One deliberate exception: a destination configured with
 * 'always_include_as_farm_destination' (currently "LEP, DC") always gets a
 * row for every farm origin, even when the cell is blank - a blank cell
 * there means "no downtime required to enter a DC Warehouse", a real
 * business fact worth showing, not missing data to silently omit.
 */
class MatrixGridParser
{
    /**
     * @param array{row_bands: array, col_bands: array, grid: array} $reconstructed
     * @return array<int, array{origin_raw_label: string, destination_raw_label: string, origin_shape: string, areas: array, forced_no_downtime_required?: bool}>
     */
    public function parse(array $reconstructed): array
    {
        $originEntries = $this->buildOriginEntries($reconstructed['row_bands']);
        $destinationEntries = $this->buildDestinationEntries($reconstructed['col_bands']);
        $grid = $reconstructed['grid'];
        $alwaysIncludeLabels = $this->alwaysIncludeAsFarmDestinationLabels();

        $candidates = [];

        foreach ($originEntries as $origin) {
            foreach ($destinationEntries as $destination) {
                $candidate = $this->buildCandidate($origin, $destination, $grid);

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                    continue;
                }

                if ($origin['shape'] === 'DUAL' && $this->labelIsAlwaysIncluded($destination['label'], $alwaysIncludeLabels)) {
                    $candidates[] = $this->forcedNoDowntimeRequiredCandidate($origin, $destination);
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, string>
     */
    private function alwaysIncludeAsFarmDestinationLabels(): array
    {
        $labels = [];

        foreach (config('downtime_matrix_import.facility_groups', []) as $group) {
            if (!empty($group['always_include_as_farm_destination'])) {
                $labels = array_merge($labels, $group['match']);
            }
        }

        return $labels;
    }

    private function labelIsAlwaysIncluded(string $label, array $alwaysIncludeLabels): bool
    {
        foreach ($alwaysIncludeLabels as $candidate) {
            if (strcasecmp(trim($candidate), trim($label)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function forcedNoDowntimeRequiredCandidate(array $origin, array $destination): array
    {
        // Origin is always DUAL (farm) here per the parse() guard - both
        // Clean and Restricted are blank by construction (this candidate is
        // only synthesized when the real cell had nothing in it).
        return [
            'origin_raw_label' => $origin['label'],
            'destination_raw_label' => $destination['label'],
            'origin_shape' => 'DUAL',
            'areas' => [
                'clean_downtime_area' => ParsedDowntimeValue::blank(),
                'clean_dormitory' => ParsedDowntimeValue::blank(),
                'restricted_downtime_area' => ParsedDowntimeValue::blank(),
                'restricted_dormitory' => ParsedDowntimeValue::blank(),
            ],
            'forced_no_downtime_required' => true,
        ];
    }

    /**
     * @return array<int, array{label: string, shape: string, row_indices: array<int,int>}>
     */
    private function buildOriginEntries(array $rowBands): array
    {
        $entries = [];
        $i = 0;
        $n = count($rowBands);

        while ($i < $n) {
            $band = $rowBands[$i];

            if ($band['area_type'] === 'Clean Area') {
                $next = $rowBands[$i + 1] ?? null;
                if ($next === null || $next['area_type'] !== 'Restricted Area') {
                    throw new GridReconstructionException(
                        "Row band labeled 'Clean Area' ({$band['label_text']}) has no matching 'Restricted Area' row immediately after it."
                    );
                }

                $entries[] = [
                    'label' => trim($band['label_text'] . ' ' . $next['label_text']),
                    'shape' => 'DUAL',
                    'row_indices' => [$i, $i + 1],
                ];
                $i += 2;
                continue;
            }

            if ($band['area_type'] === 'Restricted Area') {
                throw new GridReconstructionException(
                    "Row band labeled 'Restricted Area' ({$band['label_text']}) was not preceded by a matching 'Clean Area' row."
                );
            }

            $entries[] = [
                'label' => $band['label_text'],
                'shape' => 'SINGLE',
                'row_indices' => [$i],
            ];
            $i++;
        }

        return $entries;
    }

    /**
     * @return array<int, array{label: string, shape: string, area_col?: int, dormitory_col?: int, value_col?: int}>
     */
    private function buildDestinationEntries(array $colBands): array
    {
        $entries = [];
        $i = 0;
        $n = count($colBands);

        while ($i < $n) {
            $band = $colBands[$i];

            if ($band['downtime_type'] !== null) {
                $next = $colBands[$i + 1] ?? null;
                if ($next === null || $next['top_name'] !== $band['top_name'] || $next['downtime_type'] === null) {
                    throw new GridReconstructionException(
                        "Destination column '{$band['top_name']}' has an incomplete Downtime Area/Dormitory pair."
                    );
                }

                $areaCol = $band['downtime_type'] === 'AREA' ? $i : $i + 1;
                $dormitoryCol = $band['downtime_type'] === 'DORMITORY' ? $i : $i + 1;

                $entries[] = [
                    'label' => $band['top_name'],
                    'shape' => 'DUAL',
                    'area_col' => $areaCol,
                    'dormitory_col' => $dormitoryCol,
                ];
                $i += 2;
                continue;
            }

            $entries[] = [
                'label' => $band['top_name'],
                'shape' => 'SINGLE',
                'value_col' => $i,
            ];
            $i++;
        }

        return $entries;
    }

    private function buildCandidate(array $origin, array $destination, array $grid): ?array
    {
        if ($origin['shape'] === 'DUAL' && $destination['shape'] === 'DUAL') {
            $areas = [
                'clean_downtime_area' => $this->cellValue($grid, $origin['row_indices'][0], $destination['area_col']),
                'clean_dormitory' => $this->cellValue($grid, $origin['row_indices'][0], $destination['dormitory_col']),
                'restricted_downtime_area' => $this->cellValue($grid, $origin['row_indices'][1], $destination['area_col']),
                'restricted_dormitory' => $this->cellValue($grid, $origin['row_indices'][1], $destination['dormitory_col']),
            ];

            return $this->allBlank($areas) ? null : $this->candidate($origin, $destination, 'DUAL', $areas);
        }

        if ($origin['shape'] === 'DUAL' && $destination['shape'] === 'SINGLE') {
            // No Downtime Area/Dormitory split on the destination side -
            // each origin sub-row contributes a single reading, treated as
            // its "downtime area" value; there is no dormitory column to
            // read, so dormitory is always blank in this shape.
            $areas = [
                'clean_downtime_area' => $this->cellValue($grid, $origin['row_indices'][0], $destination['value_col']),
                'clean_dormitory' => ParsedDowntimeValue::blank(),
                'restricted_downtime_area' => $this->cellValue($grid, $origin['row_indices'][1], $destination['value_col']),
                'restricted_dormitory' => ParsedDowntimeValue::blank(),
            ];

            return $this->allBlank($areas) ? null : $this->candidate($origin, $destination, 'DUAL', $areas);
        }

        if ($origin['shape'] === 'SINGLE' && $destination['shape'] === 'DUAL') {
            $areas = [
                'downtime_area' => $this->cellValue($grid, $origin['row_indices'][0], $destination['area_col']),
                'dormitory' => $this->cellValue($grid, $origin['row_indices'][0], $destination['dormitory_col']),
            ];

            return $this->allBlank($areas) ? null : $this->candidate($origin, $destination, 'SINGLE', $areas);
        }

        // SINGLE x SINGLE
        $areas = [
            'downtime_area' => $this->cellValue($grid, $origin['row_indices'][0], $destination['value_col']),
            'dormitory' => ParsedDowntimeValue::blank(),
        ];

        return $this->allBlank($areas) ? null : $this->candidate($origin, $destination, 'SINGLE', $areas);
    }

    private function candidate(array $origin, array $destination, string $shape, array $areas): array
    {
        return [
            'origin_raw_label' => $origin['label'],
            'destination_raw_label' => $destination['label'],
            'origin_shape' => $shape,
            'areas' => $areas,
        ];
    }

    /**
     * @param array<string, ParsedDowntimeValue> $areas
     */
    private function allBlank(array $areas): bool
    {
        foreach ($areas as $value) {
            if ($value->isPresent()) {
                return false;
            }
        }

        return true;
    }

    private function cellValue(array $grid, int $rowIndex, int $colIndex): ParsedDowntimeValue
    {
        $text = $grid[$rowIndex . ':' . $colIndex] ?? '';

        return ParsedDowntimeValue::parse($text);
    }
}
