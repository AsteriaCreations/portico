<?php

namespace App\Console\Commands;

use App\Services\EmergencyRosterExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes the emergency roster on demand. The nightly backup writes it too
 * when BACKUP_EMERGENCY_ROSTER is on, so this needs no timer of its own.
 */
#[Signature('roster:export')]
#[Description('Write the emergency door roster spreadsheet into the backup folder.')]
class ExportEmergencyRoster extends Command
{
    public function handle(EmergencyRosterExporter $exporter): int
    {
        try {
            $count = $exporter->write();
        } catch (Throwable $exception) {
            $this->error("Can't write the emergency roster to ".EmergencyRosterExporter::path().": {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Wrote {$count} members to ".EmergencyRosterExporter::path());

        return self::SUCCESS;
    }
}
