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
    public function __construct(private ?VisitorSheetWriter $sheetWriter = null)
    {
    }
    public function resolveActiveRequest(int $visitorRequestId): ?array
    {
        $visitorRequest = VisitorRequest::find($visitorRequestId);

        if (!$visitorRequest || $visitorRequest->approval_status !== 'Approved') {
            return null;
        }

        // Completed requests are checked FIRST, before the session lookup -
        // this is what makes a finished visit distinguishable from a
        // brand-new one (a Completed session is otherwise excluded from the
        // whereIn() below and would look identical to "never visited").
        // The real request_status (COMPLETED / COMPLETED_AUTO / INCOMPLETE)
        // is returned as-is rather than a synthesized generic string, so
        // callers get accurate data even though the kiosk currently shows
        // the same message for all three.
        if ($visitorRequest->isCompleted()) {
            return [
                'status' => $visitorRequest->request_status,
                'visitor_request_id' => $visitorRequestId,
                'directory' => $visitorRequest->directory,
            ];
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
        ?string $photoBase64 = null,
        string $authenticationMethod = 'FACE'
    ): array {
        $visitorRequest = VisitorRequest::find($visitorRequestId);

        if (!$visitorRequest) {
            return ['success' => false, 'message' => 'Visitor request not found'];
        }

        if ($visitorRequest->isCompleted()) {
            return ['success' => false, 'message' => 'This visit has already been completed. A new approved request is required.'];
        }

        // Defense-in-depth: recognize() already rejects a farm mismatch
        // before the kiosk ever shows an action button, but this guard
        // guarantees no session/log/sheet write can ever happen for the
        // wrong farm even if this endpoint were reached some other way.
        if ($visitorRequest->farm_id !== $kiosk->farm_id) {
            return ['success' => false, 'message' => 'This visitor is approved for a different farm and cannot be authenticated at this kiosk.'];
        }

        $activeSession = VisitorSession::where('visitor_request_id', $visitorRequestId)
            ->whereIn('session_status', ['OPEN', 'Inside', 'Outside'])
            ->first();

        $photoPath = null;
        if ($photoBase64) {
            $photoPath = $this->storePhoto($visitorRequestId, $action, $photoBase64);
        }

        if ($action === 'first_entry' && !$activeSession) {
            // Login ID is generated fresh per visit (not per person) and
            // saved before the Time In sheet write, per business rule.
            $activeSession = VisitorSession::create([
                'visitor_request_id' => $visitorRequestId,
                'session_status' => 'Inside',
                'login_id' => VisitorSession::generateSessionCode('login_id'),
                'first_in' => now(),
            ]);

            $entryLog = VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'First Entry',
                'action' => 'IN',
                'authentication_method' => $authenticationMethod,
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            if ($this->sheetWriter && !$visitorRequest->isExcludedFromGoogleSheets()) {
                try {
                    $this->sheetWriter->appendTimeIn($entryLog);
                } catch (\Throwable $e) {
                    \Log::error('Google Sheets Time In write failed: ' . $e->getMessage());
                }
            }

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
                'authentication_method' => $authenticationMethod,
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
                'authentication_method' => $authenticationMethod,
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

            // Logout ID is generated fresh per visit, independently of
            // Login ID - the two must never be assumed to match.
            $activeSession->update([
                'session_status' => 'Completed',
                'logout_id' => VisitorSession::generateSessionCode('logout_id'),
                'last_out' => now(),
                'completed_at' => now(),
            ]);

            $visitorRequest->update(['request_status' => 'COMPLETED']);

            $exitLog = VisitorEntryLog::create([
                'visitor_session_id' => $activeSession->visitor_session_id,
                'kiosk_id' => $kiosk->kiosk_id,
                'movement_type' => 'Final Exit',
                'action' => 'OUT',
                'authentication_method' => $authenticationMethod,
                'photo' => $photoPath,
                'datetime' => now(),
            ]);

            if ($this->sheetWriter && !$visitorRequest->isExcludedFromGoogleSheets()) {
                try {
                    $this->sheetWriter->appendTimeOut($exitLog);
                } catch (\Throwable $e) {
                    \Log::error('Google Sheets Time Out write failed: ' . $e->getMessage());
                }
            }

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
