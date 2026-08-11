<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use App\Services\Kiosk\VisitorKioskService;
use App\Services\Face\FaceMatchingService;
use Illuminate\Http\Request;

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

            $identityResponse = $this->routeByIdentity($directory);
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
     * Employee/Gatesale/Truck aren't implemented yet - short-circuit them
     * into a placeholder response before any VisitorRequest lookup runs, so
     * zero rows are ever touched for those identities. A null/unrecognized
     * visitor_type_id (e.g. a legacy directory row predating this field)
     * intentionally falls through to the existing Visitor-with-Approval path
     * below, matching current behavior exactly.
     */
    /**
     * Driven by identity_type_name / visitor_type_name rather than the raw
     * foreign key IDs - the IDs are only stable because a seeder inserts
     * Employee/Visitor/Gatesale/Truck in a fixed order in every real
     * environment, but nothing enforces that order at the schema level (a
     * fresh RefreshDatabase test DB with no seeder run, for example, would
     * assign different auto-increment IDs). Names are the actual contract.
     */
    private function routeByIdentity(UserDirectory $directory): ?\Illuminate\Http\JsonResponse
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

        $visitorTypeName = $directory->visitorType?->visitor_type_name;

        if ($visitorTypeName === 'Gatesale') {
            return response()->json([
                'success' => false,
                'type' => 'gatesale_detected',
                'message' => 'Gatesale access flow is not yet available.',
            ]);
        }

        if ($visitorTypeName === 'Truck') {
            return response()->json([
                'success' => false,
                'type' => 'truck_detected',
                'message' => 'Truck / Delivery access flow is not yet available.',
            ]);
        }

        if ($visitorTypeName === 'Visitor' || is_null($directory->visitor_type_id)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'type' => 'visitor_type_not_supported',
            'message' => 'This visitor type is not yet supported.',
        ]);
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
