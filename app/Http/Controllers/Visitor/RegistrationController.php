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

        return view('visitor.register', ['visitorRequest' => $visitorRequest, 'token' => $token]);
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
            // Ask user: "Is this you?" before linking
            return response()->json([
                'success' => false,
                'status' => 'face_found_different_directory',
                'message' => 'A face matching yours was found. Is this you?',
                'directory_id' => $result['directory_id'],
            ]);
        }

        // Face registration successful (new face or confirmed match)
        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'data' => $result,
        ]);
    }

    public function confirmMatch(Request $request)
    {
        $token = $request->input('token');
        $matchingDirectoryId = $request->input('directory_id');
        $isConfirmed = $request->input('confirmed', false);

        $visitorRequest = VisitorRequest::where('registration_token', $token)
            ->where('approval_status', 'Approved')
            ->first();

        if (!$visitorRequest) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 400);
        }

        if (!$isConfirmed) {
            return response()->json([
                'success' => true,
                'message' => 'Registration cancelled. Please contact administration.',
            ]);
        }

        $this->registrationService->confirmFaceMatchOptionA($visitorRequest, $matchingDirectoryId, true);

        return response()->json(['success' => true]);
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

        return view('visitor.success', ['visitorRequest' => $visitorRequest]);
    }
}
