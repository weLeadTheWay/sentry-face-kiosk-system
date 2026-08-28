<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Represents one grid cell's raw text after an attempt to read a downtime
 * hour value from it. Three distinct states are tracked deliberately:
 * genuinely blank (nothing to parse - skip), present but unparseable/negative
 * (a real problem - flag INVALID, never coerce to 0), and a valid number.
 */
final class ParsedDowntimeValue
{
    private function __construct(
        public readonly bool $isBlank,
        public readonly ?float $hours,
        public readonly string $rawText,
    ) {
    }

    public static function blank(): self
    {
        return new self(true, null, '');
    }

    public static function invalid(string $rawText): self
    {
        return new self(false, null, $rawText);
    }

    public static function of(float $hours, string $rawText): self
    {
        return new self(false, $hours, $rawText);
    }

    public static function parse(string $cellText): self
    {
        $trimmed = trim($cellText);

        if ($trimmed === '') {
            return self::blank();
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)/', $trimmed, $matches)) {
            $value = (float) $matches[1];
            if ($value < 0) {
                return self::invalid($trimmed);
            }
            return self::of($value, $trimmed);
        }

        return self::invalid($trimmed);
    }

    public function isPresent(): bool
    {
        return !$this->isBlank;
    }

    public function isValid(): bool
    {
        return $this->isPresent() && $this->hours !== null;
    }
}
