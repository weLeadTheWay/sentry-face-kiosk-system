<?php

namespace App\Services\Kiosk;

use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Models\VisitorEntryLog;
use App\Models\KioskDevice;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Support\Facades\Storage;

class VisitorKioskService
{
    public function __construct(private VisitorSheetWriter $sheetWriter)
    {
    }
    public function resolveActiveRequest(int $visitorRequestId): ?array
    {
        $visitorRequest = VisitorRequest::find($visitorRequestId);

        if (!$visitorRequest || $visitorRequest->approval_status !== 'Approved') {
            return null;
        }

        $now = now();
        if ($visitorRequest->visit_datetime > $now) {
            return null;
        }

        if ($visitorRequest->departure_datetime && $visitorRequest->departure_datetime < $now) {
            return null;
        }

        $activeSession = VisitorSession::where('visitor_request_id', $visitorRequestId)
            ->whereIn('session_status', ['OPEN', 'Inside', 'Outside'])
            ->first();

        if (!$activeSession) {
            return [
                'status' => 'no_session',
                'visitor_request_id' => $visitorRequestId,
                'directory' => $visitorRequest->directory,
            ];
        }

        return [
            'status' => $activeSession->session_status,
            'visitor_request_id' => $visitorRequestId,
            'visitor_session_id' => $activeSession->visitor_session_id,
            'directory' => $visitorRequest->directory,
            'first_in' => $activeSession->first_in,
            'last_out' => $activeSession->last_out,
        ];
    }

    public function processEntry(
        int $visitorRequestId,
        string $action,
        KioskDevice $kiosk,
        ?string $photoBase64 = null
    ): array {
        $visitorRequest = VisitorRequest::find($visitorRequestId);

        if (!$visitorRequest) {
            return ['success' => false, 'message' => 'Visitor request not found'];
        }

        $activeSession = VisitorSession::where('visitor_request_id', $visitorRequestId)
            ->whereIn('session_status', ['OPEN', 'Inside', 'Outside'])
            ->first();

        $photoPath = null;
        if ($photoBase64) {
            $photoPath = $this->storePhoto($visitorRequestId, $action, $photoBase64);
        }

        if ($action === 'first_entry' && !$activeSession) {
            $activeSession = VisitorSession::create([
                'visitor_request_id' => $visitorRequestId,
                'session_status' => 'Inside',
                'first_in' => now(),
            ]);

            $entryLog = VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'First Entry',
                'action' => 'IN',
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            $this->sheetWriter->appendTimeIn($entryLog);

            return [
                'success' => true,
                'session_status' => 'Inside',
                'message' => 'Welcome! Enjoy your visit.',
            ];
        }

        if (!$activeSession) {
            return ['success' => false, 'message' => 'No active session found'];
        }

        if ($action === 'temporary_exit') {
            if ($activeSession->session_status !== 'Inside') {
                return ['success' => false, 'message' => 'Invalid action for current status'];
            }

            $activeSession->update(['session_status' => 'Outside']);

            VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'Temporary Exit',
                'action' => 'OUT',
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            return [
                'success' => true,
                'session_status' => 'Outside',
                'message' => 'See you soon!',
            ];
        }

        if ($action === 'return') {
            if ($activeSession->session_status !== 'Outside') {
                return ['success' => false, 'message' => 'Invalid action for current status'];
            }

            $activeSession->update(['session_status' => 'Inside']);

            VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'Return',
                'action' => 'IN',
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            return [
                'success' => true,
                'session_status' => 'Inside',
                'message' => 'Welcome back!',
            ];
        }

        if ($action === 'final_exit') {
            if ($activeSession->session_status === 'Completed') {
                return ['success' => false, 'message' => 'Already checked out'];
            }

            $activeSession->update([
                'session_status' => 'Completed',
                'last_out' => now(),
                'completed_at' => now(),
            ]);

            $exitLog = VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'Final Exit',
                'action' => 'OUT',
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            $this->sheetWriter->appendTimeOut($exitLog);

            return [
                'success' => true,
                'session_status' => 'Completed',
                'message' => 'Thank you for visiting!',
            ];
        }

        return ['success' => false, 'message' => 'Unknown action'];
    }

    private function storePhoto(int $visitorRequestId, string $action, string $base64String): ?string
    {
        try {
            $data = explode(',', $base64String);
            $decodedImage = base64_decode($data[1] ?? $data[0]);

            $path = "kiosk-photos/{$visitorRequestId}/{$action}-" . uniqid() . '.jpg';
            Storage::disk('public')->put($path, $decodedImage);

            return $path;
        } catch (\Exception $e) {
            \Log::error('Failed to store kiosk photo: ' . $e->getMessage());
            return null;
        }
    }
}
