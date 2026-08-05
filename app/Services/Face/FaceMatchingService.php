<?php

namespace App\Services\Face;

use App\Models\FaceProfile;

class FaceMatchingService
{
    private const DEFAULT_THRESHOLD = 0.6;

    public function findMatch(array $descriptor, ?int $onlyDirectoryId = null, float $threshold = self::DEFAULT_THRESHOLD): ?FaceProfile
    {
        if (!is_array($descriptor) || empty($descriptor)) {
            return null;
        }

        $query = FaceProfile::select('face_profile_id', 'directory_id', 'embedding')
            ->where('is_active', true);

        if ($onlyDirectoryId) {
            $query->where('directory_id', $onlyDirectoryId);
        }

        $profiles = $query->get();

        foreach ($profiles as $profile) {
            $storedEmbedding = $profile->embedding;

            if (!is_array($storedEmbedding) || count($storedEmbedding) !== count($descriptor)) {
                continue;
            }

            $distance = $this->euclideanDistance($descriptor, $storedEmbedding);

            if ($distance <= $threshold) {
                return $profile;
            }
        }

        return null;
    }

    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0;
        for ($i = 0; $i < count($a); $i++) {
            $diff = (float)$a[$i] - (float)$b[$i];
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }
}
