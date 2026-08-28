<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Reconstructs the BFI/BVA downtime matrix's 2-D grid from a flat list of
 * positioned text fragments (see PdfTextExtractor).
 *
 * The matrix is a cross-tab: origin rows (farms, each split into "Clean
 * Area"/"Restricted Area" sub-rows; plus a handful of non-farm single
 * rows) against destination columns (farms, each split into "Downtime
 * Area"/"Dormitory" sub-columns; plus a handful of non-farm single
 * columns). Text-run order in the PDF's content stream does not follow
 * visual/grid order, so row and column membership is derived purely from
 * each fragment's (x, y) position, anchored on the literal "DESTINATION"/
 * "ORIGIN" header labels.
 *
 * If the header anchors or expected tier structure can't be found, this
 * throws GridReconstructionException rather than emitting a
 * plausible-but-wrong grid.
 */
class GridReconstructor
{
    /** Y-distance within which fragments are considered the same row/tier. */
    private const Y_TOLERANCE = 3.0;

    /** Fragments left of this X are the origin row's name/label text. */
    private const ORIGIN_LABEL_MAX_X = 100.0;

    /** Fragments left of this X are the origin row's name or area-type text (not data). */
    private const ORIGIN_AREA_TYPE_MAX_X = 200.0;

    /**
     * @param array<int, array{text: string, x: float, y: float}> $fragments
     * @return array{row_bands: array, col_bands: array, grid: array<string, string>}
     */
    public function reconstruct(array $fragments): array
    {
        $destinationAnchor = $this->findFragment($fragments, 'DESTINATION');
        $originAnchor = $this->findFragment($fragments, 'ORIGIN');

        if ($destinationAnchor === null || $originAnchor === null) {
            throw new GridReconstructionException(
                'Could not locate the "DESTINATION"/"ORIGIN" header anchors in the PDF - unable to reconstruct the matrix grid.'
            );
        }

        $headerMinY = $originAnchor['y'] - self::Y_TOLERANCE;

        $headerFragments = array_values(array_filter($fragments, fn ($f) => $f['y'] >= $headerMinY));
        $dataFragments = array_values(array_filter($fragments, fn ($f) => $f['y'] < $headerMinY));

        $colBands = $this->buildColumnBands($headerFragments);
        $rowBands = $this->buildRowBands($dataFragments);
        $grid = $this->buildGrid($dataFragments, $rowBands, $colBands);

        return [
            'row_bands' => $rowBands,
            'col_bands' => $colBands,
            'grid' => $grid,
        ];
    }

    /**
     * @return array<int, array{x: float, top_name: string, downtime_type: ?string}>
     */
    private function buildColumnBands(array $headerFragments): array
    {
        $tiers = $this->clusterByY($headerFragments);

        if (count($tiers) < 2) {
            throw new GridReconstructionException(
                'Header region has an unexpected structure (fewer than 2 header tiers found) - unable to reconstruct column bands.'
            );
        }

        // The topmost tier holds "DESTINATION" plus the top-level
        // destination names. Every other header tier holds finest-grain
        // ("leaf") column labels - either a farm's "Downtime Area"/
        // "Dormitory" sub-label, or a single-style destination's own name.
        $topTier = array_shift($tiers);
        $topLevelNames = array_values(array_filter($topTier, fn ($f) => $f['text'] !== 'DESTINATION'));

        $leafFragments = [];
        foreach ($tiers as $tier) {
            foreach ($tier as $f) {
                if ($f['text'] === 'ORIGIN') {
                    continue;
                }
                $leafFragments[] = $f;
            }
        }

        if (empty($leafFragments)) {
            throw new GridReconstructionException(
                'No destination column labels were found below the top header row - unable to reconstruct column bands.'
            );
        }

        usort($leafFragments, fn ($a, $b) => $a['x'] <=> $b['x']);

        $colBands = [];
        foreach ($leafFragments as $leaf) {
            $isAreaSplit = in_array($leaf['text'], ['Downtime Area', 'Dormitory'], true);

            if ($isAreaSplit) {
                $nearest = $this->nearestByX($topLevelNames, $leaf['x']);
                if ($nearest === null) {
                    throw new GridReconstructionException(
                        "Could not associate destination sub-column '{$leaf['text']}' with a top-level destination name."
                    );
                }
                $topName = trim($nearest['text']);
                $downtimeType = $leaf['text'] === 'Downtime Area' ? 'AREA' : 'DORMITORY';
            } else {
                $topName = trim($leaf['text']);
                $downtimeType = null;
            }

            $colBands[] = [
                'x' => $leaf['x'],
                'top_name' => $topName,
                'downtime_type' => $downtimeType,
            ];
        }

        return $colBands;
    }

    /**
     * @return array<int, array{y: float, label_text: string, area_type: ?string}>
     */
    private function buildRowBands(array $dataFragments): array
    {
        $clusters = $this->clusterByY($dataFragments);

        $rowBands = [];
        foreach ($clusters as $cluster) {
            $labelFragments = array_values(array_filter($cluster, fn ($f) => $f['x'] < self::ORIGIN_LABEL_MAX_X));
            $areaTypeFragments = array_values(array_filter(
                $cluster,
                fn ($f) => $f['x'] >= self::ORIGIN_LABEL_MAX_X
                    && $f['x'] < self::ORIGIN_AREA_TYPE_MAX_X
                    && in_array($f['text'], ['Clean Area', 'Restricted Area'], true)
            ));

            usort($labelFragments, fn ($a, $b) => $a['y'] <=> $b['y']);
            $labelText = trim(implode(' ', array_map(fn ($f) => trim($f['text']), $labelFragments)));

            $y = array_sum(array_column($cluster, 'y')) / max(count($cluster), 1);

            $rowBands[] = [
                'y' => $y,
                'label_text' => $labelText,
                'area_type' => $areaTypeFragments[0]['text'] ?? null,
            ];
        }

        usort($rowBands, fn ($a, $b) => $b['y'] <=> $a['y']);

        return $rowBands;
    }

    /**
     * @return array<string, string> keyed "rowIndex:colIndex"
     */
    private function buildGrid(array $dataFragments, array $rowBands, array $colBands): array
    {
        $rowCenters = array_column($rowBands, 'y');
        $colCenters = array_column($colBands, 'x');

        $grid = [];

        foreach ($dataFragments as $f) {
            if ($f['x'] < self::ORIGIN_AREA_TYPE_MAX_X) {
                // Already consumed as a row's name/area-type label above.
                continue;
            }

            $rowIndex = $this->nearestIndex($rowCenters, $f['y']);
            $colIndex = $this->nearestIndex($colCenters, $f['x']);

            if ($rowIndex === null || $colIndex === null) {
                continue;
            }

            $key = $rowIndex . ':' . $colIndex;
            $grid[$key] = trim(($grid[$key] ?? '') . ' ' . $f['text']);
        }

        return $grid;
    }

    /**
     * Groups fragments into bands of mutually-close Y values (within
     * Y_TOLERANCE of the band's first member), sorted top-to-bottom.
     */
    private function clusterByY(array $fragments): array
    {
        $sorted = $fragments;
        usort($sorted, fn ($a, $b) => $b['y'] <=> $a['y']);

        $clusters = [];
        $current = [];
        $anchorY = null;

        foreach ($sorted as $f) {
            if ($anchorY === null || abs($f['y'] - $anchorY) > self::Y_TOLERANCE) {
                if (!empty($current)) {
                    $clusters[] = $current;
                }
                $current = [];
                $anchorY = $f['y'];
            }
            $current[] = $f;
        }

        if (!empty($current)) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    private function findFragment(array $fragments, string $text): ?array
    {
        foreach ($fragments as $f) {
            if (trim($f['text']) === $text) {
                return $f;
            }
        }

        return null;
    }

    private function nearestByX(array $fragments, float $x): ?array
    {
        return $this->nearestByAxis($fragments, $x, 'x');
    }

    private function nearestByAxis(array $fragments, float $value, string $axis): ?array
    {
        $best = null;
        $bestDiff = null;

        foreach ($fragments as $f) {
            $diff = abs($f[$axis] - $value);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $f;
            }
        }

        return $best;
    }

    private function nearestIndex(array $centers, float $value): ?int
    {
        $bestIndex = null;
        $bestDiff = null;

        foreach ($centers as $i => $c) {
            $diff = abs($c - $value);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }
}
