<?php

namespace App\Services\GoogleSheets;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsClient
{
    private Sheets $sheetsService;

    public function __construct()
    {
        $client = new Client();
        $credentialsPath = base_path(config('sentry.google.credentials_path'));

        if (file_exists($credentialsPath)) {
            $client->setAuthConfig($credentialsPath);
        }

        $client->addScope(Sheets::SPREADSHEETS);
        $this->sheetsService = new Sheets($client);
    }

    public function appendRow(string $spreadsheetId, string $range, array $values): void
    {
        $valueRange = new ValueRange([
            'values' => [$values],
        ]);

        retry(3, function () use ($spreadsheetId, $range, $valueRange) {
            $this->sheetsService->spreadsheets_values->append(
                $spreadsheetId,
                $range,
                $valueRange,
                [
                    'valueInputOption' => 'USER_ENTERED',
                ]
            );
        }, 1000);
    }
}
