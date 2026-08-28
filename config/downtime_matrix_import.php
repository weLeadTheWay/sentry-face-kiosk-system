<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Non-Farm Axis Labels
    |--------------------------------------------------------------------------
    |
    | Structural: which origin/destination axis labels in the BFI/BVA matrix
    | are single row/column entries (no Clean/Restricted or Downtime Area/
    | Dormitory sub-split), as opposed to a farm (which always has the split).
    | Read by MatrixGridParser to know how to walk the reconstructed grid.
    |
    */

    'non_farm_axis_labels' => [
        'Organikultura Area',
        'Fabrication',
        'LEP, DC',
        'Outside',
    ],

    /*
    |--------------------------------------------------------------------------
    | Facility Groups
    |--------------------------------------------------------------------------
    |
    | Resolution: which axis labels resolve to a dynamic facility GROUP
    | (all active facilities of a given facility_category) rather than a
    | single facility. Read by FacilityImportResolver. A label not listed
    | here still goes through the normal single-facility cascade and lands
    | UNMATCHED if nothing matches (e.g. "Organikultura Area", "Fabrication"
    | are NOT groups - they simply aren't present in facility_list today, so
    | they are expected to resolve UNMATCHED for human review). A
    | facility-group origin/destination is classified FARM_TO_FARM (not
    | STATIONARY) - see 'stationary_origin_labels' below.
    |
    */

    'facility_groups' => [
        [
            'match' => ['LEP, DC', 'LEP,DC', 'LEP DC'],
            'category' => 'DC_WAREHOUSE',
            'label' => 'All active DC Warehouse facilities',
            'display_name' => 'DC Warehouses',
            // Every farm must show a Farm-to-Farm row to this destination,
            // even when the source PDF leaves that cell blank - a blank
            // cell here means "no downtime required to enter a DC
            // Warehouse", not "data is missing". Read by MatrixGridParser,
            // which otherwise only emits a row for a non-blank cell.
            'always_include_as_farm_destination' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stationary Origin Labels
    |--------------------------------------------------------------------------
    |
    | Resolution + classification: a label listed here never resolves to a
    | facility or group (it is not one), but is recognized as the specific
    | "generic outside-the-system" origin that RuleClassifier treats as
    | STATIONARY when paired with a farm destination - matching
    | downtime_stationary's production shape (one rule per farm, no
    | origin/destination pair; "Outside" is that implicit origin). Any
    | other non-farm origin (Organikultura Area, Fabrication, etc.) paired
    | with a farm destination is classified FARM_TO_FARM instead, with its
    | own resolution left UNMATCHED for human review - it does not fit
    | downtime_stationary's one-rule-per-farm shape.
    |
    */

    'stationary_origin_labels' => [
        'Outside',
    ],

];
