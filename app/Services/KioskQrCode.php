<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;

/**
 * Renders a member's kiosk code (Member::ensureKioskToken()) as a QR image.
 * The payload is the bare token, not a URL: the kiosk reads it and posts it
 * to the server itself, so a member's phone never has to reach the server.
 * PNG (GD, a required extension) rather than SVG, so the same image works
 * printed, on a phone screen, and as an email attachment.
 */
class KioskQrCode
{
    public function pngDataUri(string $token): string
    {
        return $this->build($token)->getDataUri();
    }

    public function png(string $token): string
    {
        return $this->build($token)->getString();
    }

    private function build(string $token): ResultInterface
    {
        // Medium error correction: still scans from a photo of a slightly
        // creased card or a scratched phone screen.
        return (new Builder(
            writer: new PngWriter,
            data: $token,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 16,
        ))->build();
    }
}
