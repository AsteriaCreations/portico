<?php

namespace App\Services;

use App\Models\AttendanceAddOn;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-adds members to an event's Comp List from an uploaded spreadsheet,
 * with the same rules as the Comp List's "Add to comp list" form: the
 * member isn't already on this event's list, AdmissionPolicy doesn't block
 * them, the reason is an active comp reason, and the building has room.
 * Each row is priced through PricingService::applyEventComp(), exactly as
 * the form does. "Never guess, skip and log", like the other importers.
 *
 * Columns (row 1 is the header): member number or username, comp reason
 * name (case-insensitive), optional notes. A re-upload skips everyone
 * already on the list, so it never adds anyone twice.
 *
 * check() runs the same rules without writing anything, so a file can be
 * validated before it's uploaded for real.
 */
class CompListImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(Event $event, string $filePath, User $recordedBy): array
    {
        if ($event->isArchived()) {
            return ['created' => 0, 'log' => ['event is archived, nothing imported']];
        }

        $result = DB::transaction(function () use ($event, $filePath, $recordedBy): array {
            // Same lock the desk takes, so a check-in at the door can't
            // squeeze past the capacity count this upload is spending.
            app(CapacityService::class)->lockForAdmission();

            return $this->process($event, $filePath, $recordedBy);
        });

        return ['created' => $result['created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with what an upload would do with it: the member
     * it matched (if any) and its problem, or null when it would be added.
     *
     * @return list<array{row: int, identifier: string, reason: string, notes: string, member: ?string, problem: ?string}>
     */
    public function check(Event $event, string $filePath): array
    {
        return $this->process($event, $filePath, null)['rows'];
    }

    /**
     * @return array{created: int, log: string[], rows: list<array{row: int, identifier: string, reason: string, notes: string, member: ?string, problem: ?string}>}
     */
    private function process(Event $event, string $filePath, ?User $recordedBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $capacityService = app(CapacityService::class);
        $pricing = app(PricingService::class);

        $reasons = CompReason::where('active', true)->get()->keyBy(fn (CompReason $reason): string => mb_strtolower($reason->name));
        $capacity = $capacityService->capacity();
        $room = $capacity === null ? null : $capacity - $capacityService->occupancy($event->event_date);

        $created = 0;
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $identifier = trim((string) $sheet->getCell("A{$row}")->getValue());
            $reasonName = trim((string) $sheet->getCell("B{$row}")->getValue());
            $notes = trim((string) $sheet->getCell("C{$row}")->getValue());

            if ($identifier === '' && $reasonName === '' && $notes === '') {
                continue;
            }

            $member = $identifier === '' ? null : (ctype_digit($identifier)
                ? Member::where('member_number', (int) $identifier)->first()
                : Member::where('username', $identifier)->first());
            $reason = $reasons->get(mb_strtolower($reasonName));

            $problem = match (true) {
                $identifier === '' => 'blank member identifier',
                $member === null => "no member found for identifier '{$identifier}'",
                isset($seen[$member->id]) => "{$member->username} is already in this file at row {$seen[$member->id]}",
                $event->attendance()->where('member_id', $member->id)->exists() => "{$member->username} is already on this event's list",
                $reasonName === '' => 'blank comp reason',
                $reason === null => "no active comp reason named '{$reasonName}'",
                mb_strlen($notes) > 255 => 'notes longer than 255 characters',
                default => null,
            };

            if ($problem === null) {
                $decision = app(AdmissionPolicy::class)->decide($member, $event);

                if ($decision->blocksCheckIn()) {
                    $problem = "{$member->username} can't be admitted ({$decision->message})";
                } elseif ($room !== null && $room <= 0) {
                    $problem = 'building at capacity';
                }
            }

            // Only a row that will be added claims the member: a rejected
            // row (a typo'd reason, say) mustn't block a fixed repeat below.
            if ($problem === null) {
                $seen[$member->id] = $row;
            }

            $rows[] = [
                'row' => $row,
                'identifier' => $identifier,
                'reason' => $reasonName,
                'notes' => $notes,
                'member' => $member?->username,
                'problem' => $problem,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if ($room !== null) {
                $room--;
            }

            if ($recordedBy) {
                $price = $pricing->price($member, $event);

                $attendance = $event->attendance()->create([
                    'member_id' => $member->id,
                    'comp_reason_id' => $reason->id,
                    'notes' => $notes === '' ? null : $notes,
                    'checked_in_by' => $recordedBy->id,
                    'checked_in_at' => null,
                    ...$pricing->applyEventComp($price)->toAttendanceAttributes(),
                ]);

                // Entry only, as in the form: each subscribable add-on's
                // line (Pool, at launch, untouched by the comp) is its own
                // attendance_add_ons row.
                foreach ($price->addOnAttendanceRows() as $addOnRow) {
                    AttendanceAddOn::create(['attendance_id' => $attendance->id, ...$addOnRow]);
                }

                $created++;
            }
        }

        return ['created' => $created, 'log' => $log, 'rows' => $rows];
    }
}
