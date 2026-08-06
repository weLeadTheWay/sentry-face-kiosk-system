<?php

namespace App\Services\GoogleSheets;

use App\Models\VisitorEntryLog;
use App\Models\ApiLog;
use Illuminate\Support\Facades\Storage;

class VisitorSheetWriter
{
    public function __construct(private GoogleSheetsClient $client)
    {
    }

    public function appendTimeIn(VisitorEntryLog $log): void
    {
        try {
            $session = $log->session;
            $directory = $session->visitorRequest->directory;
            $visitorRequest = $session->visitorRequest;

            $row = [
                $this->formatDate($session->first_in),
                $this->formatTime($session->first_in),
                $directory->full_name ?? '',
                $this->formatLoginId($directory->login_id ?? ''),
                $visitorRequest->visitor_id ?? '',
                $log->photo ?? '',
                $this->publicPictureUrl($log->photo),
            ];

            $spreadsheetId = config('sentry.google.visitors_spreadsheet_id');
            $this->client->appendRow($spreadsheetId, 'Time In!A:G', $row);

            ApiLog::create([
                'method' => 'POST',
                'endpoint' => 'sheets/appendTimeIn',
                'request_payload' => ['session_id' => $session->visitor_session_id],
                'response' => ['success' => true],
                'status_code' => 200,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to append Time In row: ' . $e->getMessage());
            ApiLog::create([
                'method' => 'POST',
                'endpoint' => 'sheets/appendTimeIn',
                'request_payload' => ['error' => $e->getMessage()],
                'response' => ['error' => $e->getMessage()],
                'status_code' => 500,
                'created_at' => now(),
            ]);
        }
    }

    public function appendTimeOut(VisitorEntryLog $log): void
    {
        try {
            $session = $log->session;
            $directory = $session->visitorRequest->directory;
            $visitorRequest = $session->visitorRequest;

            $row = [
                $this->formatDate($session->last_out),
                $this->formatTime($session->last_out),
                $directory->full_name ?? '',
                $this->formatLoginId($directory->login_id ?? ''),
                $visitorRequest->visitor_id ?? '',
                $log->photo ?? '',
                $this->publicPictureUrl($log->photo),
            ];

            $spreadsheetId = config('sentry.google.visitors_spreadsheet_id');
            $this->client->appendRow($spreadsheetId, 'Time Out!A:G', $row);

            ApiLog::create([
                'method' => 'POST',
                'endpoint' => 'sheets/appendTimeOut',
                'request_payload' => ['session_id' => $session->visitor_session_id],
                'response' => ['success' => true],
                'status_code' => 200,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to append Time Out row: ' . $e->getMessage());
            ApiLog::create([
                'method' => 'POST',
                'endpoint' => 'sheets/appendTimeOut',
                'request_payload' => ['error' => $e->getMessage()],
                'response' => ['error' => $e->getMessage()],
                'status_code' => 500,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * M/DD/YYYY, month never zero-padded (e.g. 8/06/2026).
     */
    private function formatDate($datetime): string
    {
        return $datetime->format('n/d/Y');
    }

    /**
     * 24-hour time (e.g. 14:15:46).
     */
    private function formatTime($datetime): string
    {
        return $datetime->format('H:i:s');
    }

    private function formatLoginId(string $loginId): string
    {
        if ($loginId === '') {
            return '';
        }

        return str_starts_with($loginId, 'SNTRY-') ? $loginId : 'SNTRY-' . $loginId;
    }

    /**
     * Storage::url() WITHOUT an explicit disk resolves against
     * filesystems.default ('local'), which has no configured `url` and
     * silently falls back to a bare relative path with no host - this is
     * why picture_url was being written as "/storage/..." instead of a
     * clickable link. The 'public' disk's `url` is built from APP_URL
     * (config/filesystems.php), so this always yields a fully-qualified,
     * environment-correct URL (localhost in dev, the real domain in prod).
     */
    private function publicPictureUrl(?string $path): string
    {
        return $path ? Storage::disk('public')->url($path) : '';
    }
}
