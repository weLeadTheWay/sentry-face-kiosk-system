<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\FaceProfile;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorProfile;
use App\Models\VisitorRequest;
use App\Models\VisitorType;
use App\Services\Kiosk\VisitorKioskService;
use App\Services\Face\FaceMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KioskController extends Controller
{
    public function __construct(
        private VisitorKioskService $kioskService,
        private FaceMatchingService $faceMatchingService
    ) {
    }

    public function show(KioskDevice $kiosk)
    {
        return view('kiosk.show', ['kiosk' => $kiosk]);
    }

    /**
     * Lightweight check the kiosk JS calls BEFORE showing the recognition
     * UI or touching the webcam. Actual enforcement is the kiosk.auth
     * middleware (401 on a missing/invalid token) - this action only runs
     * at all once that middleware has already let the request through.
     */
    public function verifyToken()
    {
        return response()->json(['valid' => true]);
    }

    public function recognize(Request $request)
    {
        $kiosk = $request->attributes->get('kiosk');
        $descriptor = $request->input('descriptor');
        $qrValue = $request->input('qr_value');

        if ($descriptor) {
            $match = $this->faceMatchingService->findMatch($descriptor);
            if (!$match) {
                return response()->json([
                    'success' => false,
                    'type' => 'face_not_found',
                    'message' => 'Face not recognized. Try scanning QR code.',
                ], 404);
            }

            $directory = $match->directory;
            $directory->loadMissing('visitorProfile.visitorType');

            $identityResponse = $this->routeByIdentity($directory, $kiosk);
            if ($identityResponse !== null) {
                return $identityResponse;
            }

            $visitorRequest = $this->pickBestActiveRequest(
                VisitorRequest::where('directory_id', $directory->directory_id)->activeToday(),
                $kiosk->farm_id
            );

            if (!$visitorRequest) {
                return response()->json([
                    'success' => false,
                    'type' => 'face_found_no_active_request',
                    'message' => 'Face recognized. No approved visitor request found for today.',
                ], 404);
            }

            return $this->buildRecognitionResponse($visitorRequest, 'face_match', $directory, $kiosk);
        }

        if ($qrValue) {
            $visitorRequest = $this->pickBestActiveRequest(
                VisitorRequest::where('visitor_id', $qrValue)->activeToday(),
                $kiosk->farm_id
            );

            if (!$visitorRequest) {
                $exists = VisitorRequest::where('visitor_id', $qrValue)->exists();

                return response()->json([
                    'success' => false,
                    'type' => $exists ? 'qr_found_no_active_request' : 'qr_not_found',
                    'message' => $exists
                        ? 'QR code recognized. No approved visitor request found for today.'
                        : 'QR code not recognized.',
                ], 404);
            }

            return $this->buildRecognitionResponse($visitorRequest, 'qr_match', $visitorRequest->directory, $kiosk);
        }

        return response()->json([
            'success' => false,
            'message' => 'Missing descriptor or QR value',
        ], 400);
    }

    /**
     * Employee isn't implemented yet - short-circuited into a placeholder
     * response before any VisitorRequest lookup runs, so zero rows are ever
     * touched. Gatesale and Truck are fully implemented (both funnel into
     * handleSelfServiceRecognition() - one shared implementation, not two).
     * A null/unrecognized visitor_type_id (e.g. a legacy directory row
     * predating this field) intentionally falls through to the existing
     * Visitor-with-Approval path below, matching current behavior exactly.
     *
     * Driven by identity_type_name / visitor_type_name rather than the raw
     * foreign key IDs - the IDs are only stable because a seeder inserts
     * Employee/Visitor/Gatesale/Truck in a fixed order in every real
     * environment, but nothing enforces that order at the schema level (a
     * fresh RefreshDatabase test DB with no seeder run, for example, would
     * assign different auto-increment IDs). Names are the actual contract.
     */
    private function routeByIdentity(UserDirectory $directory, KioskDevice $kiosk): ?\Illuminate\Http\JsonResponse
    {
        $identityTypeName = $directory->identityType?->identity_type_name;

        if ($identityTypeName === 'Employee') {
            return response()->json([
                'success' => false,
                'type' => 'employee_detected',
                'message' => 'Employee access is not yet available.',
            ]);
        }

        if ($identityTypeName !== 'Visitor') {
            return response()->json([
                'success' => false,
                'type' => 'identity_not_supported',
                'message' => 'This access type is not yet supported.',
            ]);
        }

        $visitorTypeName = $directory->visitorProfile?->visitorType?->visitor_type_name;

        if ($visitorTypeName === 'Gatesale' || $visitorTypeName === 'Truck') {
            return $this->handleSelfServiceRecognition($directory, $kiosk);
        }

        if ($visitorTypeName === 'Visitor' || is_null($directory->visitorProfile?->visitor_type_id)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'type' => 'visitor_type_not_supported',
            'message' => 'This visitor type is not yet supported.',
        ]);
    }

    /**
     * A directory can only ever have ONE ACTIVE Gatesale/Truck request at a
     * time, across ALL farms - not scoped to a farm or a date, since ACTIVE
     * is itself the authoritative "currently ongoing" signal (the expired-
     * session scheduler is what's responsible for closing out anything
     * stale). Shared by both the recognition-time check and
     * createGatesaleVisit()'s transactional dedup check.
     */
    private function activeGatesaleRequestQuery(int $directoryId)
    {
        return VisitorRequest::where('directory_id', $directoryId)
            ->where('request_status', 'ACTIVE')
            ->orderByDesc('visitor_request_id');
    }

    /**
     * Shared entry point for BOTH self-service visitor types (Gatesale and
     * Truck) - one implementation, not two, since the flow is identical and
     * only the identity data (Plate No. for Truck) differs. If an ACTIVE
     * request already exists for this directory at THIS farm, resume it
     * directly (same success shape as Visitor-with-Approval - Go Out /
     * Leave Farm) with no re-confirmation step. If it exists at a DIFFERENT
     * farm, the visitor cannot enter here at all - a person cannot be
     * physically present at two farms simultaneously, and must complete
     * (Leave Farm) the other visit first. Only when no ACTIVE request
     * exists anywhere does the visitor go through "Is this you?".
     */
    private function handleSelfServiceRecognition(UserDirectory $directory, KioskDevice $kiosk): \Illuminate\Http\JsonResponse
    {
        $activeRequest = $this->activeGatesaleRequestQuery($directory->directory_id)->first();

        if ($activeRequest) {
            if ($activeRequest->farm_id !== $kiosk->farm_id) {
                return $this->gatesaleActiveElsewhereResponse($activeRequest);
            }

            return $this->buildRecognitionResponse($activeRequest, 'gatesale_match', $directory, $kiosk);
        }

        return response()->json([
            'success' => false,
            'type' => 'gatesale_confirm_identity',
            'directory' => $this->selfServiceDirectoryPayload($directory),
        ]);
    }

    /**
     * Shared directory payload shape returned by every self-service
     * response (confirm-identity, update-details, register-identity) - one
     * place, so the three never drift out of sync with each other.
     * plate_no is included unconditionally (null for Gatesale); the
     * frontend keys off `visitor_type` to decide whether to show the field.
     */
    private function selfServiceDirectoryPayload(UserDirectory $directory): array
    {
        return [
            'directory_id' => $directory->directory_id,
            'full_name' => $directory->full_name,
            'phone' => $directory->phone,
            'email' => $directory->email,
            'company' => $directory->visitorProfile?->company,
            'plate_no' => $directory->visitorProfile?->plate_no,
            'visitor_type' => $directory->visitorProfile?->visitorType?->visitor_type_name,
        ];
    }

    private function gatesaleActiveElsewhereResponse(VisitorRequest $activeRequest): \Illuminate\Http\JsonResponse
    {
        $activeFarmName = $activeRequest->farm->farm_name ?? 'another farm';

        return response()->json([
            'success' => false,
            'type' => 'gatesale_active_elsewhere',
            'message' => "This visitor is currently active at {$activeFarmName} and cannot enter here until that visit ends.",
        ], 403);
    }

    /**
     * gatesaleUpdateDetails/gatesaleCreateVisit/gatesaleRegisterIdentity all
     * accept a client-supplied directory_id and must not trust it -
     * independent of route name or frontend assumptions. Accepts either
     * self-service type (Gatesale or Truck).
     */
    private function resolveSelfServiceDirectoryOrFail(int $directoryId): UserDirectory
    {
        $directory = UserDirectory::with('visitorProfile.visitorType')->find($directoryId);

        if (
            !$directory
            || !$directory->is_active
            || $directory->identityType?->identity_type_name !== 'Visitor'
            || !in_array($directory->visitorProfile?->visitorType?->visitor_type_name, ['Gatesale', 'Truck'], true)
        ) {
            abort(422, 'Identity could not be confirmed. Please contact the administrator.');
        }

        return $directory;
    }

    /**
     * Closes the gap-lock hole a plain lockForUpdate() re-check can't cover
     * (it can only lock rows that already exist, so two concurrent calls
     * that both see zero matching rows could both insert). Cache::lock() is
     * keyed by directory ONLY (not directory+farm) - two kiosks at
     * different farms racing to create a visit for the same directory must
     * serialize against each other too, or the cross-farm check below could
     * itself be raced. This app already has a working database-backed
     * cache/lock store (CACHE_STORE=database, cache_locks table), so this
     * needs no schema change and cannot affect any other visitor type.
     */
    private function createGatesaleVisit(UserDirectory $directory, ?string $hostName, ?string $origin, ?string $purpose, ?string $photoBase64, KioskDevice $kiosk): VisitorRequest
    {
        return Cache::lock("gatesale-visit-{$directory->directory_id}", 30)
            ->block(10, function () use ($directory, $hostName, $origin, $purpose, $photoBase64, $kiosk) {
                $visitorRequest = DB::transaction(function () use ($directory, $hostName, $origin, $purpose, $kiosk) {
                    // Retained as a second layer of defense on top of the
                    // Cache::lock above, which is what actually closes the
                    // insert race.
                    $existing = $this->activeGatesaleRequestQuery($directory->directory_id)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        if ($existing->farm_id !== $kiosk->farm_id) {
                            abort(403, 'This visitor is currently active at a different farm and cannot enter here until that visit ends.');
                        }

                        return $existing;
                    }

                    if (!$hostName || !$origin || !$purpose) {
                        abort(422, 'Host Name, Origin, and Purpose are required.');
                    }

                    // The directory's face was necessarily already registered
                    // by this point (either just now, or on an earlier visit) -
                    // this column must never be left at its PENDING default.
                    $now = now();

                    return VisitorRequest::create([
                        'directory_id' => $directory->directory_id,
                        'farm_id' => $kiosk->farm_id,
                        'host_name' => $hostName,
                        'origin' => $origin,
                        'purpose' => $purpose,
                        'visit_datetime' => $now,
                        // Gatesale is always a same-day visit - fixed at
                        // creation rather than left null, so the expired-
                        // session scheduler's deadline is 23:00 (an hour
                        // before it actually runs at midnight) instead of
                        // the generic 23:59:59 end-of-day fallback.
                        'departure_datetime' => $now->copy()->setTime(23, 0, 0),
                        'registration_token' => null,
                        'face_registration_status' => 'REGISTERED',
                    ]);
                });

                // Only run first_entry for a request this call actually just
                // created - a reused ACTIVE request already has its own
                // session and must not get a second entry log.
                if ($visitorRequest->wasRecentlyCreated) {
                    $this->kioskService->processEntry($visitorRequest->visitor_request_id, 'first_entry', $kiosk, $photoBase64, 'FACE');
                }

                return $visitorRequest->fresh();
            });
    }

    /**
     * EDIT DETAILS save. Only the visitor's own contact/profile fields are
     * editable here - directory_id/identity_type_id/visitor_type_id can
     * never be changed through this endpoint. Plate No. is required (and
     * editable) only for a Truck directory; a Gatesale directory ignores it
     * entirely. Never creates a request or session; the frontend re-shows
     * "Is this you?" from the response and still requires an explicit YES.
     */
    public function gatesaleUpdateDetails(Request $request)
    {
        $directory = $this->resolveSelfServiceDirectoryOrFail((int) $request->input('directory_id'));

        $directory->update([
            'full_name' => $request->input('full_name', $directory->full_name),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
        ]);

        $profileUpdates = [
            'company' => $request->input('company'),
        ];

        if ($directory->visitorProfile?->visitorType?->visitor_type_name === 'Truck') {
            $plateNo = $request->input('plate_no');

            if (!$plateNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plate No. is required.',
                ], 422);
            }

            $profileUpdates['plate_no'] = $plateNo;
        }

        $directory->visitorProfile?->update($profileUpdates);

        return response()->json([
            'success' => true,
            'directory' => $this->selfServiceDirectoryPayload($directory),
        ]);
    }

    /**
     * Called when the visitor presses YES on "Is this you?" or Continues
     * past a fresh registration's visit-details step - both cases already
     * know their directory_id and just need Host/Origin/Purpose (+ photo).
     * An ACTIVE request is re-verified server-side regardless of what the
     * client believes (defense against a double-submit race).
     */
    public function gatesaleCreateVisit(Request $request)
    {
        $kiosk = $request->attributes->get('kiosk');
        $directory = $this->resolveSelfServiceDirectoryOrFail((int) $request->input('directory_id'));

        $visitorRequest = $this->createGatesaleVisit(
            $directory,
            $request->input('host_name'),
            $request->input('origin'),
            $request->input('purpose'),
            $request->input('photo'),
            $kiosk
        );

        return $this->buildRecognitionResponse($visitorRequest, 'gatesale_match', $directory, $kiosk);
    }

    /**
     * Identity registration ONLY - no visitor_request/session/entry_log is
     * created here. Host/Origin/Purpose belong to the separate Visit
     * Details step (gatesaleCreateVisit) that follows a successful
     * registration. Plate No. is required only when Truck is selected -
     * validated server-side, not just by the frontend. Re-checks
     * FaceMatchingService first so a genuine re-scan (flaky first attempt,
     * etc) never creates a duplicate directory/face_profile -
     * directory+face_profile creation is one transaction so a failure at
     * either step leaves zero partial rows.
     */
    public function gatesaleRegisterIdentity(Request $request)
    {
        $kiosk = $request->attributes->get('kiosk');
        $visitorType = $request->input('visitor_type');
        $fullName = $request->input('full_name');
        $company = $request->input('company');
        $descriptor = $request->input('descriptor');
        $email = $request->input('email');
        $phone = $request->input('phone');
        $plateNo = $request->input('plate_no');

        if (!in_array($visitorType, ['Gatesale', 'Truck'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This visitor type is not yet available.',
            ], 422);
        }

        if (!$fullName || !$company || !is_array($descriptor) || empty($descriptor)) {
            return response()->json([
                'success' => false,
                'message' => 'Full Name, Company, and a face capture are required.',
            ], 422);
        }

        if ($visitorType === 'Truck' && !$plateNo) {
            return response()->json([
                'success' => false,
                'message' => 'Plate No. is required for Truck registration.',
            ], 422);
        }

        $match = $this->faceMatchingService->findMatch($descriptor);
        if ($match) {
            $matchedDirectory = $match->directory;
            $matchedDirectory->loadMissing('visitorProfile.visitorType');
            $matchedVisitorType = $matchedDirectory->visitorProfile?->visitorType?->visitor_type_name;

            if ($matchedDirectory->identityType?->identity_type_name === 'Visitor' && in_array($matchedVisitorType, ['Gatesale', 'Truck'], true)) {
                return $this->handleSelfServiceRecognition($matchedDirectory, $kiosk);
            }

            return response()->json([
                'success' => false,
                'type' => 'identity_not_supported',
                'message' => 'Identity could not be confirmed. Please contact the administrator.',
            ], 422);
        }

        return DB::transaction(function () use ($visitorType, $fullName, $email, $phone, $company, $plateNo, $descriptor) {
            $directory = UserDirectory::create([
                'identity_type_id' => IdentityType::where('identity_type_name', 'Visitor')->value('identity_type_id'),
                'person_reference' => $email ?: ($phone ?: (strtoupper($visitorType) . '-' . Str::upper(Str::random(8)))),
                'first_name' => $fullName,
                'last_name' => '',
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
            ]);

            VisitorProfile::create([
                'directory_id' => $directory->directory_id,
                'visitor_type_id' => VisitorType::where('visitor_type_name', $visitorType)->value('visitor_type_id'),
                'company' => $company,
                'plate_no' => $visitorType === 'Truck' ? $plateNo : null,
            ]);

            FaceProfile::create([
                'directory_id' => $directory->directory_id,
                'embedding' => $descriptor,
                'face_version' => '1.0',
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'directory' => $this->selfServiceDirectoryPayload($directory),
            ]);
        });
    }

    /**
     * A person (or a QR's visitor_id) can have more than one visitor_request
     * that falls within today's window - e.g. an older COMPLETED visit and a
     * newer ACTIVE one, or requests for different farms.
     *
     * Priority: non-completed FIRST, then farm match, then most recent.
     * Completed-status must outrank farm match - otherwise a stale
     * COMPLETED request for the kiosk's own farm would be preferred over a
     * genuinely ACTIVE request for a different farm, and the visitor would
     * incorrectly see "already completed" instead of the correct "wrong
     * farm" message (which the QR path - a direct visitor_id lookup with no
     * candidate selection at all - always got right, exposing this bug).
     * Only falls back to a farm-mismatched or completed request if that's
     * genuinely the only match today - which still needs to surface the
     * specific reason ("different farm" / "already completed") rather than
     * a generic "not found".
     */
    private function pickBestActiveRequest($query, int $kioskFarmId): ?VisitorRequest
    {
        return $query
            ->orderByRaw("request_status = 'COMPLETED'")
            ->orderByRaw('farm_id != ?', [$kioskFarmId])
            ->orderByDesc('visitor_request_id')
            ->first();
    }

    /**
     * Identical validation/response shape for both the face and QR
     * authentication paths - QR is strictly an alternate identification
     * method, never a shortcut around any check the face path enforces.
     */
    private function buildRecognitionResponse(VisitorRequest $visitorRequest, string $matchType, $directory, KioskDevice $kiosk)
    {
        if ($visitorRequest->farm_id !== $kiosk->farm_id) {
            $approvedFarmName = $visitorRequest->farm->farm_name ?? 'a different farm';

            return response()->json([
                'success' => false,
                'type' => 'wrong_farm',
                'message' => "Recognized successfully, but this visitor is approved for {$approvedFarmName}, not this location. Please proceed to the assigned farm kiosk.",
            ], 403);
        }

        $sessionState = $this->kioskService->resolveActiveRequest($visitorRequest->visitor_request_id);

        // isCompleted() is the single source of truth for "terminal" - covers
        // COMPLETED (manual Final Exit), COMPLETED_AUTO and INCOMPLETE (both
        // auto-resolved by the expired-session scheduler). The kiosk keeps
        // showing one generic message/type for all three (no frontend
        // change needed), but the real value is exposed via request_status
        // for API accuracy / future use.
        if ($visitorRequest->isCompleted()) {
            return response()->json([
                'success' => false,
                'type' => 'request_completed',
                'request_status' => $sessionState['status'] ?? $visitorRequest->request_status,
                'message' => 'This visit has already been completed. A new approved request is required.',
                'visitor_request_id' => $visitorRequest->visitor_request_id,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'type' => $matchType,
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'session_state' => $sessionState,
            'directory' => $directory,
        ]);
    }

    public function entry(Request $request)
    {
        $kiosk = $request->attributes->get('kiosk');
        $visitorRequestId = $request->input('visitor_request_id');
        $action = $request->input('action');
        $photoBase64 = $request->input('photo');
        $authenticationMethod = in_array($request->input('authentication_method'), ['FACE', 'QR'], true)
            ? $request->input('authentication_method')
            : 'FACE';

        if (!$visitorRequestId || !$action) {
            return response()->json([
                'success' => false,
                'message' => 'Missing visitor_request_id or action',
            ], 400);
        }

        if (!$kiosk) {
            return response()->json([
                'success' => false,
                'message' => 'Kiosk authentication failed',
            ], 401);
        }

        $result = $this->kioskService->processEntry($visitorRequestId, $action, $kiosk, $photoBase64, $authenticationMethod);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
