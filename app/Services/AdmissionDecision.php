<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;

final readonly class AdmissionDecision
{
    public function __construct(
        public AdmissionOutcome $outcome,
        public string $message,
        public ?string $reason = null,
    ) {}

    public function blocksCheckIn(): bool
    {
        return $this->outcome === AdmissionOutcome::Block;
    }

    public function requiresAcknowledgement(): bool
    {
        return $this->outcome === AdmissionOutcome::Warn;
    }
}
