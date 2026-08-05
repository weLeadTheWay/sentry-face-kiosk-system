<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
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

    public function recognize(Request $request)
    {
        $descriptor = $request->input('descriptor');
        $qrValue = $request->input('qr_value');

        if ($descriptor) {
            $match = $this->faceMatchingService->findMatch($descriptor);
            if ($match) {
                $directory = $match->directory;
                $visitorRequest = VisitorRequest::where('directory_id', $directory->directory_id)
                    ->where('approval_status', 'Approved')
                    ->whereDate('visit_datetime', today())
                    ->first();

                if ($visitorRequest) {
                    $sessionState = $this->kioskService->resolveActiveRequest($visitorRequest->visitor_request_id);
                    return response()->json([
                        'success' => true,
                        'type' => 'face_match',
                        'visitor_request_id' => $visitorRequest->visitor_request_id,
                        'session_state' => $sessionState,
                        'directory' => $directory,
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'type' => 'face_not_found',
                'message' => 'Face not recognized. Try scanning QR code.',
            ], 404);
        }

        if ($qrValue) {
            $visitorRequest = VisitorRequest::where('visitor_id', $qrValue)
                ->where('approval_status', 'Approved')
                ->whereDate('visit_datetime', today())
                ->first();

            if ($visitorRequest) {
                $sessionState = $this->kioskService->resolveActiveRequest($visitorRequest->visitor_request_id);
                return response()->json([
                    'success' => true,
                    'type' => 'qr_match',
                    'visitor_request_id' => $visitorRequest->visitor_request_id,
                    'session_state' => $sessionState,
                    'directory' => $visitorRequest->directory,
                ]);
            }

            return response()->json([
                'success' => false,
                'type' => 'qr_not_found',
                'message' => 'QR code not recognized.',
            ], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'Missing descriptor or QR value',
        ], 400);
    }

    public function entry(Request $request)
    {
        $kiosk = $request->attributes->get('kiosk');
        $visitorRequestId = $request->input('visitor_request_id');
        $action = $request->input('action');
        $photoBase64 = $request->input('photo');

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

        $result = $this->kioskService->processEntry($visitorRequestId, $action, $kiosk, $photoBase64);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
