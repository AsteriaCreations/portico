<?php

namespace App\Services;

use App\Models\AttendanceAddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-adds members to an event's prepay list from an uploaded file — for an
 * external-payment reconciliation process (Venmo/PayPal/etc. matched to a
 * member, then noted for the event). "Never guess, skip and log": an
 * unmatched identifier, a member already on the list, or a bad amount
 * override are all skipped and logged rather than assumed. See
 * docs/BLUEPRINT.md "Prepay events".
 */
class PrepayListImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(Event $event, string $filePath, User $recordedBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];
        $capacityService = app(CapacityService::class);

        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $identifier = trim((string) $sheet->getCell("A{$row}")->getValue());
            $overrideRaw = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($identifier === '') {
                $log[] = "row {$row}: blank member identifier, skipped";

                continue;
            }

            $member = ctype_digit($identifier)
                ? Member::where('member_number', (int) $identifier)->first()
                : Member::where('username', $identifier)->first();

            if (! $member) {
                $log[] = "row {$row}: no member found for identifier '{$identifier}', skipped";

                continue;
            }

            if ($event->attendance()->where('member_id', $member->id)->exists()) {
                $log[] = "row {$row}: {$member->username} is already on this event's list, skipped";

                continue;
            }

            $override = null;
            if ($overrideRaw !== '') {
                if (! is_numeric($overrideRaw)) {
                    $log[] = "row {$row}: non-numeric amount override '{$overrideRaw}', skipped";

                    continue;
                }
                $override = (float) $overrideRaw;
            }

            if (! $capacityService->hasRoom($event->event_date)) {
                $log[] = "row {$row} onward: building at capacity, remaining rows skipped";

                break;
            }

            $breakdown = app(PricingService::class)->price($member, $event);
            $attrs = $breakdown->toAttendanceAttributes();

            if ($override !== null) {
                $attrs['amount_paid'] = $override;
            }

            $attendance = $event->attendance()->create([
                'member_id' => $member->id,
                'checked_in_by' => $recordedBy->id,
                'checked_in_at' => null,
                ...$attrs,
            ]);

            // toAttendanceAttributes() above only covers entry -- each
            // subscribable add-on's line (Pool, at launch) is its own
            // attendance_add_ons row, same as CheckIn::checkInAction()'s
            // own transaction.
            foreach ($breakdown->addOnAttendanceRows() as $addOnRow) {
                AttendanceAddOn::create(['attendance_id' => $attendance->id, ...$addOnRow]);
            }

            $created++;
        }

        return ['created' => $created, 'log' => $log];
    }
}
