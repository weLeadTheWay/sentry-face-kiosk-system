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

            $dateIn = $session->first_in->format('m/d/Y');
            $timeIn = $session->first_in->format('h:i:s A');
            $pictureUrl = $log->photo ? Storage::url($log->photo) : '';

            $row = [
                $dateIn,
                $timeIn,
                $directory->full_name ?? '',
                $directory->login_id ?? '',
                $visitorRequest->visitor_id ?? '',
                $log->photo ?? '',
                $pictureUrl,
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

            $dateOut = $session->last_out->format('m/d/Y');
            $timeOut = $session->last_out->format('h:i:s A');
            $pictureUrl = $log->photo ? Storage::url($log->photo) : '';

            $row = [
                $dateOut,
                $timeOut,
                $directory->full_name ?? '',
                $directory->login_id ?? '',
                $visitorRequest->visitor_id ?? '',
                $log->photo ?? '',
                $pictureUrl,
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
}
