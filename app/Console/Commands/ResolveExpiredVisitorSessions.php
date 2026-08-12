<?php

namespace App\Console\Commands;

use App\Models\VisitorEntryLog;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResolveExpiredVisitorSessions extends Command
{
    protected $signature = 'visitor:resolve-expired-sessions';

    protected $description = 'Auto-resolve ACTIVE visitor requests whose approved visit period has passed but were never completed at the kiosk';

    public function handle(?VisitorSheetWriter $sheetWriter = null): int
    {
        $now = now();
        $resolved = 0;

        // Filtering to request_status = ACTIVE alone makes this command
        // idempotent - once a request is resolved to INCOMPLETE/COMPLETED_AUTO
        // (or was already COMPLETED), it is never selected again.
        VisitorRequest::where('request_status', 'ACTIVE')
            ->with('directory.visitorProfile.visitorType')
            ->chunkById(100, function ($visitorRequests) use ($now, $sheetWriter, &$resolved) {
                foreach ($visitorRequests as $visitorRequest) {
                    if ($this->resolveIfExpired($visitorRequest, $now, $sheetWriter)) {
                        $resolved++;
                    }
                }
            }, 'visitor_request_id');

        $this->info("Resolved {$resolved} expired visitor session(s).");

        return self::SUCCESS;
    }

    private function resolveIfExpired(VisitorRequest $visitorRequest, $now, ?VisitorSheetWriter $sheetWriter): bool
    {
        // Null departure_datetime = single-day visit, expiring at the end of
        // the visit date - matches VisitorRequest::scopeActiveToday()'s
        // existing convention for the same situation.
        $deadline = $visitorRequest->departure_datetime
            ?? $visitorRequest->visit_datetime->copy()->endOfDay();

        if ($now->lte($deadline)) {
            return false;
        }

        // Defensive against duplicate/historical session rows - always
        // resolve the most recently created one.
        $session = VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)
            ->whereIn('session_status', ['Inside', 'Outside'])
            ->latest('visitor_session_id')
            ->first();

        if (!$session) {
            $this->markIncomplete($visitorRequest, null, 'NO_SESSION');
            return true;
        }

        if ($session->session_status === 'Inside') {
            $this->markIncomplete($visitorRequest, $session, 'INSIDE_EXPIRED');
            return true;
        }

        // session_status === 'Outside'
        $lastOutLog = VisitorEntryLog::where('visitor_session_id', $session->visitor_session_id)
            ->where('action', 'OUT')
            ->orderByDesc('datetime')
            ->first();

        if (!$lastOutLog) {
            // No verified exit time exists to recover - never fabricate one.
            $this->markIncomplete($visitorRequest, $session, 'OUTSIDE_NO_OUT_LOG');
            return true;
        }

        $this->markCompletedAuto($visitorRequest, $session, $lastOutLog, $sheetWriter);
        return true;
    }

    /**
     * CASE 1 (Inside, never left) / no-show / Outside-with-no-OUT-log all
     * resolve the same way: mark INCOMPLETE, touch nothing else. first_in
     * and last_out are deliberately left untouched - the actual exit time
     * is unknown and must never be fabricated. No Google Sheets write.
     */
    private function markIncomplete(VisitorRequest $visitorRequest, ?VisitorSession $session, string $reason): void
    {
        $previousRequestStatus = $visitorRequest->request_status;
        $previousSessionStatus = $session?->session_status;

        DB::transaction(function () use ($visitorRequest, $session) {
            $session?->update(['session_status' => 'INCOMPLETE']);
            $visitorRequest->update(['request_status' => 'INCOMPLETE']);
        });

        $this->logResolution($visitorRequest, $previousRequestStatus, 'INCOMPLETE', $previousSessionStatus, $session?->session_status, $reason);
    }

    /**
     * CASE 2: visitor clicked Temporary Exit and never returned/left-farm.
     * Recovers last_out from the latest real OUT log (never fabricated),
     * generates a fresh logout_id via the exact same helper the manual
     * Final Exit flow uses, then writes Time Out to Sheets as best-effort
     * only after the DB transaction has committed.
     */
    private function markCompletedAuto(
        VisitorRequest $visitorRequest,
        VisitorSession $session,
        VisitorEntryLog $lastOutLog,
        ?VisitorSheetWriter $sheetWriter
    ): void {
        $previousRequestStatus = $visitorRequest->request_status;
        $previousSessionStatus = $session->session_status;

        DB::transaction(function () use ($visitorRequest, $session, $lastOutLog) {
            $session->update([
                'session_status' => 'COMPLETED_AUTO',
                'logout_id' => VisitorSession::generateSessionCode('logout_id'),
                'last_out' => $lastOutLog->datetime,
                'completed_at' => now(),
            ]);

            $visitorRequest->update(['request_status' => 'COMPLETED_AUTO']);
        });

        $this->logResolution($visitorRequest, $previousRequestStatus, 'COMPLETED_AUTO', $previousSessionStatus, 'COMPLETED_AUTO', 'OUTSIDE_AUTO_COMPLETED');

        if (!$sheetWriter || $visitorRequest->isExcludedFromGoogleSheets()) {
            return;
        }

        try {
            // Re-fetched fresh (not the original in-memory $lastOutLog) so
            // its lazily-loaded session relation reflects the just-committed
            // row - reused as-is, no new visitor_entry_logs row is created.
            $freshLog = VisitorEntryLog::find($lastOutLog->visitor_log_id);
            $sheetWriter->appendTimeOut($freshLog);
        } catch (\Throwable $e) {
            Log::error('Auto-resolve: Google Sheets Time Out write failed: ' . $e->getMessage());
        }
    }

    private function logResolution(
        VisitorRequest $visitorRequest,
        string $previousRequestStatus,
        string $newRequestStatus,
        ?string $previousSessionStatus,
        ?string $newSessionStatus,
        string $reason
    ): void {
        Log::info('Auto-resolved expired visitor session', [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'visitor_id' => $visitorRequest->visitor_id,
            'previous_request_status' => $previousRequestStatus,
            'new_request_status' => $newRequestStatus,
            'previous_session_status' => $previousSessionStatus,
            'new_session_status' => $newSessionStatus,
            'reason' => $reason,
        ]);
    }
}
