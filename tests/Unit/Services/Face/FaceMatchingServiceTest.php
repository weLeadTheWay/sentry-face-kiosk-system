<?php

namespace Tests\Unit\Services\Face;

use App\Models\FaceProfile;
use App\Models\FaceProfileEmbedding;
use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Services\Face\FaceMatchResult;
use App\Services\Face\FaceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SQRT_128 = 11.313708498984761; // sqrt(128), for deriving exact Euclidean distances from a uniform offset

    private FaceMatchingService $service;
    private IdentityType $identityType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FaceMatchingService();
        $this->identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
    }

    private function descriptor(float $seed): array
    {
        return array_fill(0, 128, $seed);
    }

    /** Euclidean distance between two array_fill(0,128,$seed) descriptors is offset * sqrt(128). */
    private function distanceForOffset(float $offset): float
    {
        return $offset * self::SQRT_128;
    }

    private function makeDirectory(): UserDirectory
    {
        $email = 'facetest+' . uniqid() . '@example.com';

        return UserDirectory::create([
            'identity_type_id' => $this->identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'full_name' => 'Test Person',
            'email' => $email,
        ]);
    }

    private function makeLegacyProfile(float $embeddingSeed): FaceProfile
    {
        return FaceProfile::create([
            'directory_id' => $this->makeDirectory()->directory_id,
            'embedding' => $this->descriptor($embeddingSeed),
            'is_active' => true,
        ]);
    }

    private function makeMultiPoseProfile(array $poseSeeds): FaceProfile
    {
        $profile = FaceProfile::create([
            'directory_id' => $this->makeDirectory()->directory_id,
            'embedding' => $this->descriptor($poseSeeds['FRONT'] ?? reset($poseSeeds)),
            'is_active' => true,
        ]);

        foreach ($poseSeeds as $pose => $seed) {
            FaceProfileEmbedding::create([
                'face_profile_id' => $profile->face_profile_id,
                'pose' => $pose,
                'embedding' => $this->descriptor($seed),
            ]);
        }

        return $profile;
    }

    public function test_legacy_profile_with_no_pose_children_still_matches_via_its_own_embedding_column(): void
    {
        $profile = $this->makeLegacyProfile(1.0);

        $result = $this->service->match($this->descriptor(1.03)); // distance ~0.34, well under default 0.6 threshold

        $this->assertTrue($result->isMatch());
        $this->assertSame($profile->face_profile_id, $result->profile->face_profile_id);
    }

    public function test_no_match_returns_no_match_status(): void
    {
        $this->makeLegacyProfile(1.0);

        $result = $this->service->match($this->descriptor(9.0)); // distance ~90, far beyond threshold

        $this->assertSame(FaceMatchResult::NO_MATCH, $result->status);
        $this->assertNull($result->profile);
    }

    public function test_multi_pose_profile_matches_via_minimum_distance_across_poses(): void
    {
        // FRONT and RIGHT are deliberately far from the incoming descriptor;
        // only LEFT is close - min-distance scoring must still find it.
        $profile = $this->makeMultiPoseProfile([
            'FRONT' => 9.0,
            'LEFT' => 2.0,
            'RIGHT' => 9.5,
        ]);

        $result = $this->service->match($this->descriptor(2.03));

        $this->assertTrue($result->isMatch());
        $this->assertSame($profile->face_profile_id, $result->profile->face_profile_id);
        $this->assertEqualsWithDelta($this->distanceForOffset(0.03), $result->distance, 0.001);
    }

    public function test_profile_with_pose_children_ignores_its_own_legacy_embedding_column(): void
    {
        // The legacy `embedding` column is close to the incoming descriptor,
        // but every pose child is far - once children exist they are the
        // sole source of truth, so this must NOT match.
        $profile = FaceProfile::create([
            'directory_id' => $this->makeDirectory()->directory_id,
            'embedding' => $this->descriptor(2.0), // close to the incoming descriptor below
            'is_active' => true,
        ]);

        FaceProfileEmbedding::create([
            'face_profile_id' => $profile->face_profile_id,
            'pose' => 'FRONT',
            'embedding' => $this->descriptor(9.0), // far
        ]);

        $result = $this->service->match($this->descriptor(2.03));

        $this->assertSame(FaceMatchResult::NO_MATCH, $result->status);
    }

    public function test_two_close_profiles_are_flagged_ambiguous(): void
    {
        $closer = $this->makeLegacyProfile(1.0);
        $runnerUp = $this->makeLegacyProfile(0.995); // offset 0.005 further from the incoming descriptor than $closer -> well within default margin

        // Incoming descriptor sits closer to $closer (offset 0.03) than to
        // $runnerUp (offset 0.035); distance gap is ~0.057, under the
        // default 0.08 ambiguity margin.
        $result = $this->service->match($this->descriptor(1.03));

        $this->assertTrue($result->isAmbiguous());
        $this->assertSame($closer->face_profile_id, $result->profile->face_profile_id);
        $this->assertNotNull($result->runnerUpDistance);
    }

    public function test_find_match_collapses_ambiguous_to_the_top_candidate_for_backward_compatibility(): void
    {
        $closer = $this->makeLegacyProfile(1.0);
        $this->makeLegacyProfile(0.995);

        $matched = $this->service->findMatch($this->descriptor(1.03));

        $this->assertNotNull($matched);
        $this->assertSame($closer->face_profile_id, $matched->face_profile_id);
    }

    public function test_clearly_separated_profiles_are_not_ambiguous(): void
    {
        $closer = $this->makeLegacyProfile(1.0);
        $this->makeLegacyProfile(6.0); // distance ~56.5, nowhere near threshold or margin

        $result = $this->service->match($this->descriptor(1.03));

        $this->assertTrue($result->isMatch());
        $this->assertSame($closer->face_profile_id, $result->profile->face_profile_id);
    }

    public function test_scoped_lookup_by_directory_never_produces_ambiguity_even_with_close_internal_poses(): void
    {
        // Both poses on the SAME profile are close to each other and to the
        // incoming descriptor - ambiguity is a cross-profile concept only,
        // so a directory-scoped lookup (at most one profile) must always
        // resolve to a plain MATCH.
        $directory = $this->makeDirectory();
        $profile = FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $this->descriptor(2.0),
            'is_active' => true,
        ]);
        FaceProfileEmbedding::create([
            'face_profile_id' => $profile->face_profile_id,
            'pose' => 'FRONT',
            'embedding' => $this->descriptor(2.0),
        ]);
        FaceProfileEmbedding::create([
            'face_profile_id' => $profile->face_profile_id,
            'pose' => 'LEFT',
            'embedding' => $this->descriptor(2.03),
        ]);

        $result = $this->service->match($this->descriptor(2.01), $directory->directory_id);

        $this->assertTrue($result->isMatch());
        $this->assertFalse($result->isAmbiguous());
    }

    public function test_threshold_override_can_reject_a_distance_that_would_otherwise_match(): void
    {
        $this->makeLegacyProfile(1.0);

        $result = $this->service->match($this->descriptor(1.03), null, 0.2); // distance ~0.34 > 0.2 override

        $this->assertSame(FaceMatchResult::NO_MATCH, $result->status);
    }

    public function test_ambiguity_margin_override_can_turn_an_ambiguous_pair_into_a_plain_match(): void
    {
        $closer = $this->makeLegacyProfile(1.0);
        $this->makeLegacyProfile(0.995);

        $result = $this->service->match($this->descriptor(1.03), null, null, 0.01); // gap ~0.057 > 0.01 margin

        $this->assertTrue($result->isMatch());
        $this->assertFalse($result->isAmbiguous());
        $this->assertSame($closer->face_profile_id, $result->profile->face_profile_id);
    }

    public function test_empty_descriptor_returns_no_match(): void
    {
        $this->makeLegacyProfile(1.0);

        $result = $this->service->match([]);

        $this->assertSame(FaceMatchResult::NO_MATCH, $result->status);
    }

    public function test_inactive_profiles_are_never_matched(): void
    {
        FaceProfile::create([
            'directory_id' => $this->makeDirectory()->directory_id,
            'embedding' => $this->descriptor(1.0),
            'is_active' => false,
        ]);

        $result = $this->service->match($this->descriptor(1.03));

        $this->assertSame(FaceMatchResult::NO_MATCH, $result->status);
    }
}
