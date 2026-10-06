<?php

namespace App\Services;

use App\Enums\KioskScanStatus;

/**
 * One kiosk scan's outcome, as the kiosk screen shows it. The message is
 * meant for the member standing at the kiosk, so it never carries a
 * watchlist/ban reason or anything else Door wouldn't say out loud.
 */
final readonly class KioskScanResult
{
    public function __construct(
        public KioskScanStatus $status,
        public string $message,
        public ?string $memberName = null,
    ) {}

    /**
     * @return array{status: string, message: string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'name' => $this->memberName,
        ];
    }
}
