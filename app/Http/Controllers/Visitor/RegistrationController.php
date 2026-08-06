<?php

namespace App\Http\Controllers\Visitor;

use App\Http\Controllers\Controller;
use App\Models\VisitorRequest;
use App\Services\VisitorRegistrationService;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function __construct(private VisitorRegistrationService $registrationService)
    {
    }

    public function show(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return view('visitor.register', ['error' => 'Missing registration token.']);
        }

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return view('visitor.register', ['error' => 'Invalid or expired registration token.']);
        }

        return view('visitor.register', [
            'visitorRequest' => $visitorRequest,
            'token' => $token,
            'notice' => $request->query('notice'),
        ]);
    }

    public function showSearch(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return view('visitor.search', ['error' => 'Missing registration token.']);
        }

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return view('visitor.search', ['error' => 'Invalid or expired registration token.']);
        }

        return view('visitor.search', ['visitorRequest' => $visitorRequest, 'token' => $token]);
    }

    public function searchName(Request $request)
    {
        $query = $request->query('q');

        if (!$query || strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $this->registrationService->searchByName($query);

        return response()->json(['results' => $results]);
    }

    public function showCapture(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return view('visitor.capture', ['error' => 'Missing registration token.']);
        }

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return view('visitor.capture', ['error' => 'Invalid or expired registration token.']);
        }

        return view('visitor.capture', ['visitorRequest' => $visitorRequest, 'token' => $token]);
    }

    public function captureFace(Request $request)
    {
        $token = $request->input('token');
        $descriptor = $request->input('descriptor');
        $faceImageBase64 = $request->input('face_image');

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 400);
        }

        if (!is_array($descriptor)) {
            return response()->json(['success' => false, 'message' => 'Invalid descriptor'], 400);
        }

        $result = $this->registrationService->completeFaceRegistrationOptionA(
            $visitorRequest,
            $descriptor,
            $faceImageBase64
        );

        // Check if face was found in a different directory
        if ($result['status'] === 'face_found_different_directory') {
            // Get the matched directory details to show who it matched
            $matchedDirectory = \App\Models\UserDirectory::find($result['directory_id']);

            // Ask user: "Is this you?" before linking
            return response()->json([
                'success' => false,
                'status' => 'face_found_different_directory',
                'message' => 'A face matching yours was found. Is this you?',
                'directory_id' => $result['directory_id'],
                'matched_name' => $matchedDirectory ? $matchedDirectory->full_name : 'Unknown',
            ]);
        }

        // Face registration successful (new face or confirmed match)
        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'data' => $result,
        ]);
    }

    public function verifyFace(Request $request)
    {
        $token = $request->input('token');
        $directoryId = $request->input('directory_id');
        $descriptor = $request->input('descriptor');
        $faceImageBase64 = $request->input('face_image');
        $attempt = (int) $request->input('attempt', 1);

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 400);
        }

        if (!$directoryId || !is_array($descriptor)) {
            return response()->json(['success' => false, 'message' => 'Missing directory or descriptor'], 400);
        }

        $result = $this->registrationService->verifyFaceOptionB(
            $visitorRequest,
            (int) $directoryId,
            $descriptor,
            $faceImageBase64,
            $attempt >= 3
        );

        if ($result['status'] === 'success') {
            return response()->json(['success' => true, 'status' => 'success']);
        }

        if ($result['status'] === 'face_not_found') {
            return response()->json(['success' => false, 'status' => 'face_not_found', 'message' => $result['message']], 422);
        }

        if ($result['status'] === 'verification_failed') {
            return response()->json(['success' => true, 'status' => 'verification_failed', 'message' => $result['message']]);
        }

        return response()->json(['success' => false, 'status' => 'error', 'message' => $result['message'] ?? 'Verification failed'], 400);
    }

    public function confirmMatch(Request $request)
    {
        $token = $request->input('token');
        $matchingDirectoryId = $request->input('directory_id');
        $isConfirmed = $request->boolean('confirmed');

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 400);
        }

        $result = $this->registrationService->confirmFaceMatchOptionA($visitorRequest, (int) $matchingDirectoryId, $isConfirmed);

        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'message' => $result['message'] ?? null,
        ]);
    }

    public function success(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return view('visitor.success', ['error' => 'Missing registration token.']);
        }

        $visitorRequest = VisitorRequest::where('registration_token', $token)->first();

        if (!$visitorRequest) {
            return view('visitor.success', ['error' => 'Invalid token.']);
        }

        if ($visitorRequest->face_registration_status === 'PENDING') {
            return redirect()->route('visitor.register', [
                'token' => $token,
                'notice' => 'Please complete your registration first.',
            ]);
        }

        return view('visitor.success', ['visitorRequest' => $visitorRequest, 'token' => $token]);
    }

    public function qrCode(Request $request, \App\Services\Qr\VisitorQrCodeService $qr)
    {
        $token = $request->query('token');
        $visitorRequest = VisitorRequest::where('registration_token', $token)->first();

        if (!$visitorRequest || !$visitorRequest->visitor_id || $visitorRequest->face_registration_status === 'PENDING') {
            abort(404);
        }

        try {
            $png = $qr->generate($visitorRequest->visitor_id);
        } catch (\Throwable $e) {
            \Log::error('QR code generation failed', [
                'token' => $token,
                'visitor_request_id' => $visitorRequest->visitor_request_id,
                'visitor_id' => $visitorRequest->visitor_id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Unable to generate QR code. This has been logged for investigation.');
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $visitorRequest->visitor_id);
        $headers = ['Content-Type' => 'image/png'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="visitor-qr-' . $filename . '.png"';
        }

        return response($png, 200, $headers);
    }
}
