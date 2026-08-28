<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Classifies a resolved origin/destination pair into one of three
 * categories: FARM_TO_FARM, STATIONARY, or OTHERS. Always returns one of
 * the three - there is no "doesn't fit anywhere" case; OTHERS is that
 * catch-all by design, not an error state.
 *
 * STATIONARY is narrow and specific: it only applies when the origin is
 * the recognized "generic outside-the-system" sentinel (e.g. "Outside")
 * and the destination resolves to a farm - matching downtime_stationary's
 * production shape (one rule per farm, no origin/destination pair;
 * "Outside" is that rule's implicit, unstated origin).
 *
 * FARM_TO_FARM requires the origin to actually be farm-like - a real farm
 * OR a facility group standing in for one (e.g. "LEP, DC" ->
 * DC_WAREHOUSE) - paired with a farm (or group) on the other side. A
 * destination simply being a farm is not enough on its own.
 *
 * Everything else - a non-sentinel, non-farm, non-group origin (e.g.
 * "Organikultura Area", "Fabrication") paired with a farm destination, or
 * any other combination that fits neither rule above - is OTHERS. It's
 * still resolved/classified/validated normally and surfaced for review,
 * never silently dropped or forced into a category it doesn't fit.
 */
class RuleClassifier
{
    public function classify(FacilityResolutionResult $origin, FacilityResolutionResult $destination): string
    {
        $originIsFarm = $origin->facilityCategoryName() === 'FARM';
        $destinationIsFarm = $destination->facilityCategoryName() === 'FARM';

        if ($origin->isStationaryOrigin() && $destinationIsFarm) {
            return 'STATIONARY';
        }

        $originIsFarmOrGroup = $originIsFarm || $origin->isGroup();
        $destinationIsFarmOrGroup = $destinationIsFarm || $destination->isGroup();

        if (($originIsFarmOrGroup && $destinationIsFarm) || ($destinationIsFarmOrGroup && $originIsFarm)) {
            return 'FARM_TO_FARM';
        }

        return 'OTHERS';
    }
}
