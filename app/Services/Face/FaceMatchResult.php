<?php

namespace App\Services\Face;

use App\Models\FaceProfile;

final class FaceMatchResult
{
    public const MATCH = 'MATCH';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const NO_MATCH = 'NO_MATCH';

    private function __construct(
        public readonly string $status,
        public readonly ?FaceProfile $profile,
        public readonly ?float $distance,
        public readonly ?float $runnerUpDistance,
    ) {
    }

    public static function match(FaceProfile $profile, float $distance, ?float $runnerUpDistance): self
    {
        return new self(self::MATCH, $profile, $distance, $runnerUpDistance);
    }

    public static function ambiguous(FaceProfile $profile, float $distance, float $runnerUpDistance): self
    {
        return new self(self::AMBIGUOUS, $profile, $distance, $runnerUpDistance);
    }

    public static function noMatch(): self
    {
        return new self(self::NO_MATCH, null, null, null);
    }

    public function isMatch(): bool
    {
        return $this->status === self::MATCH;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::AMBIGUOUS;
    }
}
