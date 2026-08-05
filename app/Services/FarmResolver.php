<?php

namespace App\Services;

use App\Models\FarmList;
use App\Models\FarmAlias;

class FarmResolver
{
    public function resolve(string $rawName): ?FarmList
    {
        $rawName = trim($rawName);

        $alias = FarmAlias::where('alias_text', $rawName)->first();
        if ($alias) {
            return $alias->farm;
        }

        $normalized = $this->normalize($rawName);

        return FarmList::where('farm_name', $rawName)
            ->orWhere('farm_name', 'like', '%' . $normalized . '%')
            ->first();
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
