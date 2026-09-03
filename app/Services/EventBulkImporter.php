<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\User;
use App\Services\Concerns\ParsesSpreadsheetValues;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Bulk-creates events from an uploaded spreadsheet, for standing up a
 * season's worth of events at once. Same "never guess, skip and log"
 * philosophy as PrepayListImporter: an unparseable date, an unmatched event
 * type/showrunner/host, a bad fee, or a row that duplicates an event already
 * on file are all skipped and logged rather than assumed.
 */
class EventBulkImporter
{
    use ParsesSpreadsheetValues;

    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath, User $createdBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $eventDateRaw = trim((string) $sheet->getCell("A{$row}")->getValue());
            $startsAtRaw = trim((string) $sheet->getCell("B{$row}")->getValue());
            $endsAtRaw = trim((string) $sheet->getCell("C{$row}")->getValue());
            $name = trim((string) $sheet->getCell("D{$row}")->getValue());
            $eventTypeRaw = trim((string) $sheet->getCell("E{$row}")->getValue());
            $entryFeeRaw = trim((string) $sheet->getCell("F{$row}")->getValue());
            $poolFeeRaw = trim((string) $sheet->getCell("G{$row}")->getValue());
            $doorPrepayRaw = trim((string) $sheet->getCell("H{$row}")->getValue());
            $showrunnerUsername = trim((string) $sheet->getCell("I{$row}")->getValue());
            $hostUsername = trim((string) $sheet->getCell("J{$row}")->getValue());
            $notes = trim((string) $sheet->getCell("K{$row}")->getValue());

            if ($eventDateRaw === '' && $name === '' && $entryFeeRaw === '' && $poolFeeRaw === '') {
                continue;
            }

            if ($eventDateRaw === '') {
                $log[] = "row {$row}: blank event_date, skipped";

                continue;
            }

            try {
                $eventDate = Carbon::parse($eventDateRaw);
            } catch (Throwable) {
                $log[] = "row {$row}: unparseable event_date '{$eventDateRaw}', skipped";

                continue;
            }

            $startsAt = null;
            if ($startsAtRaw !== '') {
                try {
                    $startsAt = Carbon::parse($startsAtRaw);
                } catch (Throwable) {
                    $log[] = "row {$row}: unparseable starts_at '{$startsAtRaw}', skipped";

                    continue;
                }
            }

            $endsAt = null;
            if ($endsAtRaw !== '') {
                try {
                    $endsAt = Carbon::parse($endsAtRaw);
                } catch (Throwable) {
                    $log[] = "row {$row}: unparseable ends_at '{$endsAtRaw}', skipped";

                    continue;
                }
            }

            if ($startsAt && $endsAt && $endsAt->lessThanOrEqualTo($startsAt)) {
                $log[] = "row {$row}: ends_at is not after starts_at, skipped";

                continue;
            }

            if ($entryFeeRaw === '' || ! is_numeric($entryFeeRaw)) {
                $log[] = "row {$row}: missing or non-numeric entry_fee '{$entryFeeRaw}', skipped";

                continue;
            }

            if ($poolFeeRaw === '' || ! is_numeric($poolFeeRaw)) {
                $log[] = "row {$row}: missing or non-numeric pool_fee '{$poolFeeRaw}', skipped";

                continue;
            }

            $eventTypeId = null;
            if ($eventTypeRaw !== '') {
                $eventType = EventType::whereRaw('LOWER(name) = ?', [mb_strtolower($eventTypeRaw)])->first();

                if (! $eventType) {
                    $log[] = "row {$row}: no event type found named '{$eventTypeRaw}', skipped";

                    continue;
                }

                $eventTypeId = $eventType->id;
            }

            try {
                $doorPrepayEnabled = $this->parseBoolean($doorPrepayRaw, 'door_prepay_enabled', default: false);
            } catch (RuntimeException $exception) {
                $log[] = "row {$row}: {$exception->getMessage()}, skipped";

                continue;
            }

            $showrunnerId = null;
            if ($showrunnerUsername !== '') {
                $showrunner = Member::where('username', $showrunnerUsername)->first();

                if (! $showrunner) {
                    $log[] = "row {$row}: no member found for showrunner_username '{$showrunnerUsername}', skipped";

                    continue;
                }

                $showrunnerId = $showrunner->id;
            }

            $hostId = null;
            if ($hostUsername !== '') {
                $host = Member::where('username', $hostUsername)->first();

                if (! $host) {
                    $log[] = "row {$row}: no member found for host_username '{$hostUsername}', skipped";

                    continue;
                }

                $hostId = $host->id;
            }

            if (Event::whereDate('event_date', $eventDate)->where('name', $name)->exists()) {
                $log[] = "row {$row}: an event already exists for '{$name}' on {$eventDate->toDateString()}, skipped";

                continue;
            }

            Event::create([
                'event_date' => $eventDate,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'name' => $name !== '' ? $name : null,
                'event_type_id' => $eventTypeId,
                'entry_fee' => (float) $entryFeeRaw,
                'pool_fee' => (float) $poolFeeRaw,
                'door_prepay_enabled' => $doorPrepayEnabled,
                'showrunner_id' => $showrunnerId,
                'host_id' => $hostId,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $createdBy->id,
            ]);

            $created++;
        }

        return ['created' => $created, 'log' => $log];
    }
}
