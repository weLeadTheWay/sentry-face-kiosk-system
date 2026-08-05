<?php

namespace App\Services;

use App\Models\VisitorRequest;
use App\Models\FaceProfile;
use App\Models\UserDirectory;
use App\Services\Face\FaceMatchingService;
use Illuminate\Support\Facades\Storage;

class VisitorRegistrationService
{
    public function __construct(private FaceMatchingService $faceMatchingService)
    {
    }

    public function completeFaceRegistrationOptionA(
        VisitorRequest $visitorRequest,
        array $descriptor,
        ?string $faceImageBase64 = null
    ): array {
        $existingMatch = $this->faceMatchingService->findMatch($descriptor);

        if ($existingMatch && $existingMatch->directory_id !== $visitorRequest->directory_id) {
            return [
                'status' => 'face_found_different_directory',
                'face_profile_id' => $existingMatch->face_profile_id,
                'message' => 'A matching face was found from a previous visit.',
            ];
        }

        $directory = $visitorRequest->directory;
        $facePath = null;

        if ($faceImageBase64) {
            $facePath = $this->storeFaceImage($visitorRequest->visitor_request_id, $faceImageBase64);
        }

        $faceProfile = FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $descriptor,
            'face_image' => $facePath,
            'face_version' => '1.0',
            'is_active' => true,
        ]);

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
    ): ?array {
        if (!$isConfirmed) {
            return null;
        }

        $visitorRequest->update(['directory_id' => $matchingDirectoryId]);

        return [
            'status' => 'success',
            'directory_id' => $matchingDirectoryId,
        ];
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
        ?string $faceImageBase64 = null
    ): array {
        $directory = UserDirectory::find($selectedDirectoryId);
        if (!$directory) {
            return ['status' => 'error', 'message' => 'Directory not found.'];
        }

        $match = $this->faceMatchingService->findMatch($descriptor, $selectedDirectoryId);

        if (!$match) {
            return [
                'status' => 'face_not_found',
                'message' => 'Face does not match this directory. Please try again.',
            ];
        }

        $visitorRequest->update(['directory_id' => $selectedDirectoryId]);

        return [
            'status' => 'success',
            'directory_id' => $selectedDirectoryId,
            'face_profile_id' => $match->face_profile_id,
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
