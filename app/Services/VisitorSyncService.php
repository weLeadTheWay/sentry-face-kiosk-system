<?php

namespace App\Services;

use App\Models\VisitorRequest;
use App\Models\UserDirectory;
use App\Models\VisitorProfile;
use App\Models\IdentityType;
use App\Models\VisitorType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisitorSyncService
{
    public function __construct(private FarmResolver $farmResolver)
    {
    }

    public function syncApprovedRequest(array $data): array
    {
        $farm = $this->farmResolver->resolve($data['farm']);
        if (!$farm) {
            return [
                'success' => false,
                'message' => 'Farm not found. Please add an alias in the admin panel.',
            ];
        }

        // Stored EXACTLY as received from AppSheet - visitor_id must never
        // be modified (no prefixing, no normalization). Only Login ID /
        // Logout ID (generated separately, per visitor_session) carry the
        // SNTRY- prefix - see VisitorSheetWriter::formatSentryId().
        $visitorIdKey = $data['visitor_id'] ?? null;
        if ($visitorIdKey) {
            $existing = VisitorRequest::where('visitor_id', $visitorIdKey)->first();
            if ($existing) {
                return [
                    'success' => true,
                    'message' => 'Visitor request already synced.',
                    'registration_token' => $existing->registration_token,
                    'visitor_request' => $existing,
                ];
            }
        }

        $visitorIdentityType = IdentityType::where('identity_type_name', 'Visitor')->first();
        if (!$visitorIdentityType) {
            return [
                'success' => false,
                'message' => 'Visitor identity type not found in system.',
            ];
        }

        $visitorType = VisitorType::where('visitor_type_name', 'Visitor')->first();
        if (!$visitorType) {
            return [
                'success' => false,
                'message' => 'Visitor type not found in system.',
            ];
        }

        $directory = $this->resolveDirectory($data, $visitorIdentityType->identity_type_id, $visitorType->visitor_type_id);

        $registrationToken = 'REG_' . Str::upper(Str::random(8));
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => $visitorIdKey,
            'qr_url' => $data['qr_url'] ?? null,
            'farm_id' => $farm->farm_id,
            'host_name' => $data['host_name'],
            'purpose' => $data['purpose'] ?? null,
            // Explicitly parsed (not left to the model cast) so AppSheet's
            // US-style "8/6/2026 14:00:00" / "08/06/2026 14:00:00" (both
            // paddings) are always interpreted consistently.
            'visit_datetime' => Carbon::parse($data['visit_datetime']),
            'departure_datetime' => isset($data['departure_datetime']) ? Carbon::parse($data['departure_datetime']) : null,
            'registration_token' => $registrationToken,
            'approval_status' => 'Approved',
            'request_status' => 'ACTIVE',
        ]);

        return [
            'success' => true,
            'message' => 'Visitor request synced successfully.',
            'registration_token' => $registrationToken,
            'visitor_request' => $visitorRequest,
        ];
    }

    /**
     * Resolve the UserDirectory for this sync payload.
     *
     * A directory is only reused when BOTH full_name and email match an
     * existing record (case-insensitive, trimmed). Any other combination
     * (same email/different name, same name/different email, both differ)
     * always creates a new directory - identity must never be merged on a
     * single field alone. Face Registration later resolves true duplicates
     * via the face-match confirmation workflow.
     *
     * visitor_type_id is only ever set (on visitor_profile, see below) for a
     * NEWLY created directory - an existing (reused) directory is never
     * modified by this method, so an admin's manual correction elsewhere is
     * never silently overwritten by a later sync call.
     */
    private function resolveDirectory(array $data, int $identityTypeId, int $visitorTypeId): UserDirectory
    {
        $fullName = $this->buildFullName($data);
        $email = trim($data['email']);

        $existing = UserDirectory::whereRaw('LOWER(TRIM(email)) = ?', [Str::lower($email)])
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [Str::lower($fullName)])
            ->first();

        if ($existing) {
            return $existing;
        }

        // visitor_type_id lives on visitor_profile now, not user_directory -
        // both creates are wrapped together so a failure at either step
        // never leaves an orphaned directory with no profile.
        return DB::transaction(function () use ($data, $identityTypeId, $visitorTypeId, $fullName, $email) {
            $directory = UserDirectory::create([
                'identity_type_id' => $identityTypeId,
                'first_name' => $data['first_name'] ?? 'Unknown',
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'] ?? 'Visitor',
                'full_name' => $fullName,
                'email' => $email,
                'person_reference' => $email,
            ]);

            VisitorProfile::create([
                'directory_id' => $directory->directory_id,
                'visitor_type_id' => $visitorTypeId,
            ]);

            return $directory;
        });
    }

    private function buildFullName(array $data): string
    {
        $parts = array_filter([
            trim($data['first_name'] ?? ''),
            trim($data['middle_name'] ?? ''),
            trim($data['last_name'] ?? ''),
        ], fn ($part) => $part !== '');

        if (empty($parts) && !empty($data['full_name'])) {
            return trim($data['full_name']);
        }

        return implode(' ', $parts) ?: 'Unknown Visitor';
    }
}
