<?php

namespace App\Services;

use App\Models\VisitorRequest;
use App\Models\UserDirectory;
use App\Models\IdentityType;
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

        $directory = UserDirectory::firstOrCreate(
            ['email' => $data['email']],
            [
                'identity_type_id' => $visitorIdentityType->identity_type_id,
                'first_name' => $data['first_name'] ?? 'Unknown',
                'last_name' => $data['last_name'] ?? 'Visitor',
                'full_name' => $data['full_name'] ?? ($data['first_name'] ?? 'Unknown') . ' ' . ($data['last_name'] ?? 'Visitor'),
                'person_reference' => $data['email'],
            ]
        );

        $registrationToken = 'REG_' . Str::upper(Str::random(8));
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => $data['visitor_id'] ?? null,
            'qr_url' => $data['qr_url'] ?? null,
            'farm_id' => $farm->farm_id,
            'host_name' => $data['host_name'],
            'purpose' => $data['purpose'] ?? null,
            'visit_datetime' => $data['visit_datetime'],
            'departure_datetime' => $data['departure_datetime'] ?? null,
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
}
