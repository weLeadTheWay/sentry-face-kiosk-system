<?php

namespace App\Services\DowntimeMatrixImport;

use App\Models\FacilityList;

class FacilityResolutionResult
{
    private function __construct(
        public readonly string $rawLabel,
        public readonly ?FacilityList $facility,
        public readonly ?string $method,
        public readonly ?string $groupCategory,
        public readonly ?string $groupLabel,
        public readonly ?string $groupDisplayName,
        public readonly array $ambiguousMatches,
    ) {
    }

    public static function forFacility(string $rawLabel, FacilityList $facility, string $method): self
    {
        return new self($rawLabel, $facility, $method, null, null, null, []);
    }

    public static function forGroup(string $rawLabel, string $groupCategory, string $groupLabel, ?string $groupDisplayName = null): self
    {
        return new self($rawLabel, null, 'FACILITY_GROUP', $groupCategory, $groupLabel, $groupDisplayName ?? $groupLabel, []);
    }

    public static function forUnmatched(string $rawLabel): self
    {
        return new self($rawLabel, null, 'UNMATCHED', null, null, null, []);
    }

    public static function forAmbiguous(string $rawLabel, array $matchedFacilityNames): self
    {
        return new self($rawLabel, null, null, null, null, null, $matchedFacilityNames);
    }

    /**
     * A label recognized as the specific "generic outside-the-system"
     * origin (e.g. "Outside") - never resolves to a facility or group, but
     * is distinct from UNMATCHED: it's not a resolution failure, it's the
     * expected shape of a Stationary rule's implicit origin.
     */
    public static function forStationaryOrigin(string $rawLabel): self
    {
        return new self($rawLabel, null, 'STATIONARY_ORIGIN', null, null, null, []);
    }

    public function isAmbiguous(): bool
    {
        return !empty($this->ambiguousMatches);
    }

    public function isUnmatched(): bool
    {
        return $this->method === 'UNMATCHED';
    }

    public function isGroup(): bool
    {
        return $this->method === 'FACILITY_GROUP';
    }

    public function isStationaryOrigin(): bool
    {
        return $this->method === 'STATIONARY_ORIGIN';
    }

    public function isSingleFacility(): bool
    {
        return $this->facility !== null;
    }

    public function isNormalizedMatch(): bool
    {
        return in_array($this->method, ['NORMALIZED_NAME', 'NORMALIZED_ALIAS'], true);
    }

    public function facilityCategoryName(): ?string
    {
        if ($this->isGroup()) {
            return $this->groupCategory;
        }

        return $this->facility?->facilityCategory?->facility_category_name;
    }
}
