<?php

namespace App\Services\DowntimeMatrixImport;

use App\Models\FacilityAlias;
use App\Models\FacilityList;
use Illuminate\Support\Collection;

/**
 * Resolves a raw origin/destination label extracted from a Downtime Matrix
 * PDF against facility_list/facility_aliases (never farm_list/farm_aliases).
 *
 * Kept separate from App\Services\FacilityResolver: that service is live
 * behind Visitor Sync with a ?FacilityList return type and prefix-only
 * normalization tuned for AppSheet's text. This resolver needs a richer
 * result (single facility | facility group | UNMATCHED | AMBIGUOUS) and
 * suffix/parenthetical-aware normalization tuned for the PDF's own naming
 * convention, so the two are deliberately independent implementations.
 *
 * facility_list/facility_aliases are small reference tables (on the order
 * of tens of rows), but a single import calls resolve() twice per parsed
 * cell (origin + destination) - for the real sample PDF, ~90 candidates
 * means ~180 calls. Querying per call (up to 4 queries per resolve(), plus
 * the group-lookup query) turns that into hundreds of round trips, which
 * measurably matters once per-query latency is non-trivial. Both tables
 * are instead loaded once per resolver instance and every subsequent call
 * resolves against the in-memory copy - correctness is unaffected since
 * nothing else can write to facility_list/facility_aliases mid-import.
 */
class FacilityImportResolver
{
    private ?Collection $facilitiesCache = null;
    private ?Collection $aliasesCache = null;

    public function resolve(string $rawLabel): FacilityResolutionResult
    {
        $rawLabel = trim($rawLabel);

        // 1. Exact name match
        $facility = $this->facilities()->first(fn (FacilityList $f) => $f->facility_name === $rawLabel);
        if ($facility) {
            return FacilityResolutionResult::forFacility($rawLabel, $facility, 'EXACT_NAME');
        }

        // 2. Exact alias match
        $alias = $this->aliases()->first(fn (FacilityAlias $a) => $a->alias_text === $rawLabel);
        if ($alias) {
            return FacilityResolutionResult::forFacility($rawLabel, $alias->facility, 'EXACT_ALIAS');
        }

        // Normalization is always attempted next, never skipped, per the
        // approved resolution order - it is not optional even when the
        // exact steps above find nothing.
        $normalized = $this->normalize($rawLabel);

        // 3. Normalized name match
        $normalizedMatches = $this->facilities()
            ->filter(fn (FacilityList $f) => strtoupper($f->facility_name) === $normalized)
            ->values();
        if ($normalizedMatches->count() === 1) {
            return FacilityResolutionResult::forFacility($rawLabel, $normalizedMatches->first(), 'NORMALIZED_NAME');
        }
        if ($normalizedMatches->count() > 1) {
            return FacilityResolutionResult::forAmbiguous($rawLabel, $normalizedMatches->pluck('facility_name')->all());
        }

        // 4. Normalized alias match
        $normalizedAliasMatches = $this->aliases()
            ->filter(fn (FacilityAlias $a) => strtoupper($a->alias_text) === $normalized)
            ->values();
        if ($normalizedAliasMatches->count() === 1) {
            return FacilityResolutionResult::forFacility($rawLabel, $normalizedAliasMatches->first()->facility, 'NORMALIZED_ALIAS');
        }
        if ($normalizedAliasMatches->count() > 1) {
            $names = $normalizedAliasMatches->map(fn (FacilityAlias $a) => $a->facility->facility_name)->unique()->values()->all();
            return FacilityResolutionResult::forAmbiguous($rawLabel, $names);
        }

        // 5. Facility group match (e.g. "LEP, DC" -> all active DC_WAREHOUSE facilities)
        foreach (config('downtime_matrix_import.facility_groups', []) as $group) {
            foreach ($group['match'] as $candidate) {
                if ($this->labelsMatch($candidate, $rawLabel) || $this->labelsMatch($candidate, $normalized)) {
                    return FacilityResolutionResult::forGroup($rawLabel, $group['category'], $group['label'], $group['display_name'] ?? null);
                }
            }
        }

        // 6. Stationary-origin sentinel (e.g. "Outside") - never resolves to
        // a facility, but is recognized so RuleClassifier can treat
        // "Outside -> Farm" as STATIONARY without this registering as an
        // UNMATCHED finding (it's the expected shape, not a failure).
        foreach (config('downtime_matrix_import.stationary_origin_labels', []) as $candidate) {
            if ($this->labelsMatch($candidate, $rawLabel) || $this->labelsMatch($candidate, $normalized)) {
                return FacilityResolutionResult::forStationaryOrigin($rawLabel);
            }
        }

        // 7. No fuzzy/similarity matching beyond this point - UNMATCHED.
        return FacilityResolutionResult::forUnmatched($rawLabel);
    }

    /**
     * Dynamically resolves the active facilities belonging to a facility
     * group category, from the same cached snapshot resolve() uses -
     * membership still reflects facility_list as of when this resolver
     * instance was first used, not a hardcoded list.
     */
    public function resolveGroupMembers(string $groupCategory): Collection
    {
        return $this->facilities()
            ->filter(fn (FacilityList $f) => $f->facilityCategory?->facility_category_name === $groupCategory)
            ->values();
    }

    private function facilities(): Collection
    {
        return $this->facilitiesCache ??= FacilityList::with('facilityCategory')
            ->where('is_active', true)
            ->get();
    }

    private function aliases(): Collection
    {
        return $this->aliasesCache ??= FacilityAlias::with(['facility.facilityCategory'])
            ->get()
            ->filter(fn (FacilityAlias $a) => $a->facility && $a->facility->is_active)
            ->values();
    }

    private function labelsMatch(string $a, string $b): bool
    {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    private function normalize(string $name): string
    {
        $name = trim($name);

        // Strip a trailing parenthetical qualifier, e.g. "(Red-Act)", "(Green)",
        // "(w/o any Farm Contact)".
        $name = preg_replace('/\s*\([^)]*\)\s*$/', '', $name);
        $name = trim($name);

        // Strip a trailing "Farm"/"Farms" suffix (the PDF's naming convention -
        // "Madera Farm" -> "Madera" - the opposite of FacilityResolver's
        // prefix-based normalization, which is tuned for AppSheet's text).
        $name = preg_replace('/\s+Farms?$/i', '', $name);

        return strtoupper(trim($name));
    }
}
