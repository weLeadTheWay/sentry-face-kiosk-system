<?php

namespace App\Services;

use App\Models\VisitorRequest;
use App\Models\FaceProfile;
use App\Models\FaceProfileEmbedding;
use App\Models\UserDirectory;
use App\Services\Face\FaceMatchingService;
use App\Services\Face\FacePose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VisitorRegistrationService
{
    public function __construct(private FaceMatchingService $faceMatchingService)
    {
    }

    /**
     * $poses is keyed by whichever of FacePose::ALL were actually captured
     * ({descriptor: float[128], face_image?: base64}) - currently always
     * just FRONT until the guided multi-pose capture UX ships, but the
     * write path below never assumes a fixed count: it creates exactly one
     * FaceProfileEmbedding row per pose key actually present, never
     * fabricating LEFT/RIGHT from FRONT.
     */
    public function completeFaceRegistrationOptionA(
        VisitorRequest $visitorRequest,
        array $poses
    ): array {
        $frontDescriptor = $poses[FacePose::FRONT]['descriptor'] ?? null;

        $existingMatch = $this->faceMatchingService->findMatch($frontDescriptor);

        if ($existingMatch && $existingMatch->directory_id === $visitorRequest->directory_id) {
            // Same person, already registered - do not create a duplicate face_profile.
            if ($visitorRequest->face_registration_status !== 'REGISTERED') {
                $visitorRequest->update(['face_registration_status' => 'REGISTERED']);
            }

            return [
                'status' => 'already_registered',
                'message' => 'Your face is already registered.',
                'face_profile_id' => $existingMatch->face_profile_id,
                'directory_id' => $existingMatch->directory_id,
            ];
        }

        if ($existingMatch) {
            return [
                'status' => 'face_found_different_directory',
                'face_profile_id' => $existingMatch->face_profile_id,
                'directory_id' => $existingMatch->directory_id,
                'message' => 'A matching face was found from a previous visit.',
            ];
        }

        $directory = $visitorRequest->directory;

        $faceProfile = DB::transaction(function () use ($directory, $poses, $visitorRequest, $frontDescriptor) {
            $frontImagePath = null;
            $frontImageBase64 = $poses[FacePose::FRONT]['face_image'] ?? null;
            if ($frontImageBase64) {
                $frontImagePath = $this->storeFaceImage($visitorRequest->visitor_request_id, $frontImageBase64);
            }

            $profile = FaceProfile::create([
                'directory_id' => $directory->directory_id,
                'embedding' => $frontDescriptor,
                'face_image' => $frontImagePath,
                'face_version' => '1.0',
                'is_active' => true,
            ]);

            foreach ($poses as $pose => $data) {
                if (!is_array($data['descriptor'] ?? null) || empty($data['descriptor'])) {
                    continue;
                }

                $imagePath = $pose === FacePose::FRONT
                    ? $frontImagePath
                    : (isset($data['face_image']) ? $this->storeFaceImage($visitorRequest->visitor_request_id, $data['face_image']) : null);

                FaceProfileEmbedding::create([
                    'face_profile_id' => $profile->face_profile_id,
                    'pose' => $pose,
                    'embedding' => $data['descriptor'],
                    'face_image' => $imagePath,
                    'face_version' => '1.0',
                ]);
            }

            return $profile;
        });

        $visitorRequest->update(['face_registration_status' => 'REGISTERED']);

        return [
            'status' => 'success',
            'face_profile_id' => $faceProfile->face_profile_id,
            'directory_id' => $directory->directory_id,
        ];
    }

    public function confirmFaceMatchOptionA(
        VisitorRequest $visitorRequest,
        int $matchingDirectoryId,
        bool $isConfirmed
    ): array {
        if ($isConfirmed) {
            $visitorRequest->update([
                'directory_id' => $matchingDirectoryId,
                'face_registration_status' => 'REGISTERED',
            ]);

            return [
                'status' => 'linked',
                'directory_id' => $matchingDirectoryId,
            ];
        }

        return $this->markManualVerificationRequired($visitorRequest, 'failed_match');
    }

    public function searchByName(string $query, int $limit = 10): array
    {
        return UserDirectory::where('full_name', 'like', '%' . $query . '%')
            ->where('is_active', true)
            ->limit($limit)
            ->get(['directory_id', 'full_name', 'email'])
            ->toArray();
    }

    public function verifyFaceOptionB(
        VisitorRequest $visitorRequest,
        int $selectedDirectoryId,
        array $descriptor,
        ?string $faceImageBase64 = null,
        bool $isFinalAttempt = false
    ): array {
        $directory = UserDirectory::find($selectedDirectoryId);
        if (!$directory) {
            return ['status' => 'error', 'message' => 'Directory not found.'];
        }

        $match = $this->faceMatchingService->findMatch($descriptor, $selectedDirectoryId);

        if (!$match) {
            if ($isFinalAttempt) {
                return $this->markManualVerificationRequired($visitorRequest, 'verification_failed');
            }

            return [
                'status' => 'face_not_found',
                'message' => 'Face does not match this directory. Please try again.',
            ];
        }

        $visitorRequest->update([
            'directory_id' => $selectedDirectoryId,
            'face_registration_status' => 'REGISTERED',
        ]);

        return [
            'status' => 'success',
            'directory_id' => $selectedDirectoryId,
            'face_profile_id' => $match->face_profile_id,
        ];
    }

    /**
     * Shared terminal-failure path for both Option A's "No, this is not me"
     * decline and Option B's 3rd failed verification attempt: no face
     * profile is created/linked, the request is flagged for an
     * administrator to resolve, and the visitor can still use their QR
     * code for entry (per the biometric-conflict business rule).
     */
    private function markManualVerificationRequired(VisitorRequest $visitorRequest, string $status): array
    {
        $visitorRequest->update([
            'face_registration_status' => 'FAILED_MATCH',
            'manual_verification_required' => true,
        ]);

        return [
            'status' => $status,
            'message' => 'A biometric conflict has been detected. Please contact the administrator. You may still use your assigned QR Code for entry.',
        ];
    }

    private function storeFaceImage(int $visitorRequestId, string $base64String): string
    {
        $data = explode(',', $base64String);
        $decodedImage = base64_decode($data[1] ?? $data[0]);

        $path = "face-photos/{$visitorRequestId}/" . uniqid() . '.jpg';
        Storage::disk('public')->put($path, $decodedImage);

        return $path;
    }
}
