<?php

namespace App\Services\Qr;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class VisitorQrCodeService
{
    /**
     * Generate a QR code PNG encoding exactly $payload (the visitor_id).
     * This is the single local source of truth the kiosk's QR scanner
     * decodes against - it must never diverge from visitor_request.visitor_id.
     */
    public function generate(string $payload, int $size = 300): string
    {
        $result = (new Builder(writer: new PngWriter()))->build(
            data: $payload,
            size: $size,
            margin: 10,
        );

        return $result->getString();
    }
}
