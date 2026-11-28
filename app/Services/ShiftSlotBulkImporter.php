<?php

namespace App\Services;

use App\Enums\ShiftKind;
use App\Models\Event;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Bulk-adds Setup and extra shifts across many events from an uploaded
 * spreadsheet, with the "Add setup or extra shift" form's own rules: a
 * scheduled shift role, 1 to 50 people, an end after the start, a label up
 * to 40 characters and notes up to 255. Standard shifts (Open and Close,
 * or Whole night) aren't uploaded: they're set per event through "Set
 * standard shifts", which keeps their handover and headcount rules.
 * "Never guess, skip and log", like the other importers.
 *
 * Columns (row 1 is the header): event date, event name (needed only when
 * two events share the date), shift role, kind (setup or extra; blank is
 * extra), label, starts at, ends at, people needed, notes. Names match
 * case-insensitively. A shift exactly matching one already on the event
 * (same role, kind, label, start and end) is skipped, so re-uploading a
 * file never adds anything twice.
 */
class ShiftSlotBulkImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $result = DB::transaction(fn (): array => $this->process($filePath, true));

        return ['created' => $result['created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with the event it matched (if any) and its
     * problem, or null when it would be added.
     *
     * @return list<array{row: int, event_date: string, event_name: string, role: string, kind: string, label: string, starts_at: string, ends_at: string, headcount: string, notes: string, event: ?string, problem: ?string}>
     */
    public function check(string $filePath): array
    {
        return $this->process($filePath, false)['rows'];
    }

    /**
     * @return array{created: int, log: string[], rows: list<array<string, mixed>>}
     */
    private function process(string $filePath, bool $write): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $roles = ShiftRole::query()->scheduled()->get()->keyBy(fn (ShiftRole $role): string => mb_strtolower($role->name));

        $created = 0;
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $cells = [];

            foreach (['event_date' => 'A', 'event_name' => 'B', 'role' => 'C', 'kind' => 'D', 'label' => 'E', 'starts_at' => 'F', 'ends_at' => 'G', 'headcount' => 'H', 'notes' => 'I'] as $field => $column) {
                $cells[$field] = trim((string) $sheet->getCell("{$column}{$row}")->getValue());
            }

            if (implode('', $cells) === '') {
                continue;
            }

            $event = null;
            $problem = null;
            $startsAt = $this->parseDateTime($cells['starts_at']);
            $endsAt = $this->parseDateTime($cells['ends_at']);
            $role = $roles->get(mb_strtolower($cells['role']));
            $kind = match (mb_strtolower($cells['kind'])) {
                '', 'extra' => ShiftKind::Extra,
                'setup' => ShiftKind::Setup,
                default => null,
            };

            $eventDate = $this->parseDateTime($cells['event_date']);

            if ($cells['event_date'] === '') {
                $problem = 'blank event date';
            } elseif ($eventDate === null) {
                $problem = "unparseable event date '{$cells['event_date']}'";
            } else {
                [$event, $problem] = $this->findEvent($eventDate, $cells['event_name']);
            }

            $problem ??= match (true) {
                $cells['role'] === '' => 'blank shift role',
                $role === null => "no scheduled shift role named '{$cells['role']}'",
                $kind === null => "kind '{$cells['kind']}' must be setup or extra (standard shifts are set on the event's Staffing)",
                mb_strlen($cells['label']) > 40 => 'label longer than 40 characters',
                $cells['starts_at'] === '' => 'blank starts at',
                $startsAt === null => "unparseable starts at '{$cells['starts_at']}'",
                $cells['ends_at'] === '' => 'blank ends at',
                $endsAt === null => "unparseable ends at '{$cells['ends_at']}'",
                ! $endsAt->greaterThan($startsAt) => 'ends at is not after starts at',
                ! preg_match('/^\d+$/', $cells['headcount']) || (int) $cells['headcount'] < 1 || (int) $cells['headcount'] > 50 => "people needed '{$cells['headcount']}' must be a whole number from 1 to 50",
                mb_strlen($cells['notes']) > 255 => 'notes longer than 255 characters',
                default => null,
            };

            if ($problem === null) {
                $label = $cells['label'] === '' ? null : $cells['label'];
                $key = implode('|', [$event->id, $role->id, $kind->value, mb_strtolower((string) $label), $startsAt->toDateTimeString(), $endsAt->toDateTimeString()]);

                $exists = $event->shiftSlots()
                    ->where('shift_role_id', $role->id)
                    ->where('kind', $kind)
                    ->where('starts_at', $startsAt)
                    ->where('ends_at', $endsAt)
                    ->get()
                    ->contains(fn (ShiftSlot $slot): bool => mb_strtolower((string) $slot->label) === mb_strtolower((string) $label));

                if (isset($seen[$key])) {
                    $problem = "same shift as row {$seen[$key]}";
                } elseif ($exists) {
                    $problem = 'this shift is already on the event';
                } else {
                    $seen[$key] = $row;
                }
            }

            $rows[] = [
                'row' => $row,
                ...$cells,
                'event' => $event ? $event->name.' ('.$event->event_date->toDateString().')' : null,
                'problem' => $problem,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if ($write) {
                $event->shiftSlots()->create([
                    'shift_role_id' => $role->id,
                    'kind' => $kind,
                    'label' => $label,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'headcount' => (int) $cells['headcount'],
                    'notes' => $cells['notes'] === '' ? null : $cells['notes'],
                ]);

                $created++;
            }
        }

        return ['created' => $created, 'log' => $log, 'rows' => $rows];
    }

    /**
     * The one unarchived event on $date, by name when two share it.
     *
     * @return array{0: ?Event, 1: ?string}
     */
    private function findEvent(CarbonInterface $date, string $name): array
    {
        $events = Event::whereDate('event_date', $date->toDateString())->whereNull('archived_at')->get();

        if ($name !== '') {
            $events = $events->filter(fn (Event $event): bool => mb_strtolower((string) $event->name) === mb_strtolower($name));
        }

        return match ($events->count()) {
            1 => [$events->first(), null],
            0 => [null, $name === ''
                ? "no event on {$date->toDateString()}"
                : "no event named '{$name}' on {$date->toDateString()}"],
            default => [null, "{$events->count()} events on {$date->toDateString()}; give the event's name"],
        };
    }

    /**
     * A text date-time ("2026-12-05 18:00"), or a date/time cell, which a
     * spreadsheet stores as a number of days. That fraction can land a
     * second short (18:29:59), so a cell is rounded to the minute, the
     * form's own precision.
     */
    private function parseDateTime(string $raw): ?CarbonInterface
    {
        if ($raw === '') {
            return null;
        }

        try {
            return is_numeric($raw)
                ? Carbon::instance(Date::excelToDateTimeObject($raw))->shiftTimezone(config('app.timezone'))->roundMinute()
                : Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
