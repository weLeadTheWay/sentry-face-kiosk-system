<?php

namespace App\Services;

use App\Models\FarmList;
use App\Models\FarmAlias;

class FarmResolver
{
    public function resolve(string $rawName): ?FarmList
    {
        $rawName = trim($rawName);

        // Step 1: Try exact alias match first
        $alias = FarmAlias::where('alias_text', $rawName)->first();
        if ($alias) {
            return $alias->farm;
        }

        // Step 2: Try case-insensitive alias match
        $alias = FarmAlias::whereRaw('UPPER(alias_text) = ?', [strtoupper($rawName)])->first();
        if ($alias) {
            return $alias->farm;
        }

        // Step 3: Normalize and search farm names
        $normalized = $this->normalize($rawName);

        // Try exact match on normalized farm name
        $farm = FarmList::whereRaw('UPPER(farm_name) = ?', [$normalized])->first();
        if ($farm) {
            return $farm;
        }

        // Step 4: Only if exact normalized match fails, return null
        // Do NOT do fuzzy LIKE match - it's too permissive and can match wrong farms
        // User should either:
        // 1. Create a farm_alias for the name variant, or
        // 2. Use the exact farm name from the system
        return null;
    }

    private function normalize(string $name): string
    {
        $name = strtoupper(trim($name));
        // Only strip "FARM " or "FARMS " if it's a prefix (e.g., "FARM ALPHA" → "ALPHA")
        // But NOT for "FARM A" which should remain "FARM A"
        if (preg_match('/^FARM(?:S)?\s+(\w{2,})/', $name, $matches)) {
            $name = $matches[1];
        }
        return trim($name);
    }
}
