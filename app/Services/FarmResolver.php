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

        // First try exact match on normalized farm name
        $farm = FarmList::whereRaw('UPPER(farm_name) = ?', [$normalized])->first();
        if ($farm) {
            return $farm;
        }

        // Then try LIKE match for variations (e.g., "Madera Farm" matches "MADERA")
        return FarmList::whereRaw('UPPER(farm_name) LIKE ?', ['%' . $normalized . '%'])->first();
    }

    private function normalize(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = preg_replace('/^FARM\s+/', '', $name);
        $name = preg_replace('/^FARMS\s+/', '', $name);
        $name = preg_replace('/\s+$/', '', $name);
        return $name;
    }
}
