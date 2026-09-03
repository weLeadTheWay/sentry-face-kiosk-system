<?php

namespace App\Services\Face;

use App\Models\FaceProfile;

class FaceMatchingService
{
    /**
     * Richer matching entry point: ranks every active profile by the
     * minimum Euclidean distance across its available pose embeddings (a
     * profile with no face_profile_embedding rows is matched via its own
     * legacy `embedding` column as an implicit single pose), then flags
     * MATCH vs AMBIGUOUS based on how close the best candidate is to the
     * runner-up (a different profile) relative to $ambiguityMargin.
     */
    public function match(
        array $descriptor,
        ?int $onlyDirectoryId = null,
        ?float $threshold = null,
        ?float $ambiguityMargin = null
    ): FaceMatchResult {
        if (empty($descriptor)) {
            return FaceMatchResult::noMatch();
        }

        $threshold ??= (float) config('sentry.face.match_threshold', 0.6);
        $ambiguityMargin ??= (float) config('sentry.face.ambiguity_margin', 0.08);

        $query = FaceProfile::select('face_profile_id', 'directory_id', 'embedding')
            ->where('is_active', true)
            ->with(['embeddings:face_profile_embedding_id,face_profile_id,pose,embedding']);

        if ($onlyDirectoryId) {
            $query->where('directory_id', $onlyDirectoryId);
        }

        $best = null;
        $runnerUp = null;

        foreach ($query->get() as $profile) {
            $distance = $this->bestDistanceForProfile($profile, $descriptor);

            if ($distance === null) {
                continue;
            }

            if ($best === null || $distance < $best['distance']) {
                $runnerUp = $best;
                $best = ['profile' => $profile, 'distance' => $distance];
            } elseif ($runnerUp === null || $distance < $runnerUp['distance']) {
                $runnerUp = ['profile' => $profile, 'distance' => $distance];
            }
        }

        if ($best === null || $best['distance'] > $threshold) {
            return FaceMatchResult::noMatch();
        }

        if ($runnerUp !== null && ($runnerUp['distance'] - $best['distance']) < $ambiguityMargin) {
            return FaceMatchResult::ambiguous($best['profile'], $best['distance'], $runnerUp['distance']);
        }

        return FaceMatchResult::match($best['profile'], $best['distance'], $runnerUp['distance'] ?? null);
    }

    /**
     * Backward-compatible wrapper preserving the pre-multi-pose contract:
     * both MATCH and AMBIGUOUS resolve to "return the top candidate" -
     * existing callers that haven't been updated to read AMBIGUOUS
     * explicitly keep their exact current behavior.
     */
    public function findMatch(array $descriptor, ?int $onlyDirectoryId = null, ?float $threshold = null): ?FaceProfile
    {
        $result = $this->match($descriptor, $onlyDirectoryId, $threshold);

        return in_array($result->status, [FaceMatchResult::MATCH, FaceMatchResult::AMBIGUOUS], true)
            ? $result->profile
            : null;
    }

    /**
     * Minimum distance across a profile's pose embeddings (or its own
     * legacy `embedding` column when it has no face_profile_embedding
     * rows yet) - null if nothing comparable is stored.
     */
    private function bestDistanceForProfile(FaceProfile $profile, array $descriptor): ?float
    {
        $poseEmbeddings = $profile->embeddings->pluck('embedding')->all();

        if (empty($poseEmbeddings)) {
            $poseEmbeddings = [$profile->embedding];
        }

        $best = null;

        foreach ($poseEmbeddings as $storedEmbedding) {
            if (!is_array($storedEmbedding) || count($storedEmbedding) !== count($descriptor)) {
                continue;
            }

            $distance = $this->euclideanDistance($descriptor, $storedEmbedding);

            if ($best === null || $distance < $best) {
                $best = $distance;
            }
        }

        return $best;
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
