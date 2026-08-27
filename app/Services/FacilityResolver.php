<?php

namespace App\Services;

use App\Models\FacilityList;
use App\Models\FacilityAlias;

class FacilityResolver
{
    public function resolve(string $rawName): ?FacilityList
    {
        $rawName = trim($rawName);

        // Step 1: Try exact alias match first
        $alias = FacilityAlias::where('alias_text', $rawName)->first();
        if ($alias) {
            return $alias->facility;
        }

        // Step 2: Try case-insensitive alias match
        $alias = FacilityAlias::whereRaw('UPPER(alias_text) = ?', [strtoupper($rawName)])->first();
        if ($alias) {
            return $alias->facility;
        }

        // Step 3: Normalize and search facility names
        $normalized = $this->normalize($rawName);

        // Try exact match on normalized facility name
        $facility = FacilityList::whereRaw('UPPER(facility_name) = ?', [$normalized])->first();
        if ($facility) {
            return $facility;
        }

        // Step 4: Only if exact normalized match fails, return null
        // Do NOT do fuzzy LIKE match - it's too permissive and can match wrong facilities
        // User should either:
        // 1. Create a facility_alias for the name variant, or
        // 2. Use the exact facility name from the system
        return null;
    }

    private function normalize(string $name): string
    {
        $name = strtoupper(trim($name));
        // Only strip "FARM " or "FARMS " if it's a prefix (e.g., "FARM ALPHA" → "ALPHA")
        // But NOT for "FARM A" which should remain "FARM A" - this pattern comes from
        // AppSheet's raw text (still farm-specific wording, unrelated to the underlying
        // facility_list/facility_aliases tables it now resolves against).
        if (preg_match('/^FARM(?:S)?\s+(\w{2,})/', $name, $matches)) {
            $name = $matches[1];
        }
        return trim($name);
    }
}
