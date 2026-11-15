<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A spreadsheet of every member with what the door needs to know about them,
 * for a night when the system itself is down: written beside the nightly
 * backups (roster:export, or backup:database with BACKUP_EMERGENCY_ROSTER
 * on) so it travels off the machine the same way they do.
 *
 * It carries the door's *decision* only, the same thing the Door role sees:
 * never a ban or watchlist reason, a date of birth, notes or an email.
 * Ages are as of the day it was written, and a ban exception for one event
 * is not reflected: that stays a Manager's call on the night.
 */
class EmergencyRosterExporter
{
    private const HEADER_ROW = 4;

    public function __construct(private AdmissionPolicy $admission) {}

    public static function path(): string
    {
        return config('backup.destination').DIRECTORY_SEPARATOR.'roster'.DIRECTORY_SEPARATOR.config('backup.prefix').'-roster.xlsx';
    }

    /**
     * Writes the roster, replacing the last one, and returns how many
     * members it lists.
     */
    public function write(?string $path = null): int
    {
        $path ??= self::path();
        File::ensureDirectoryExists(dirname($path));

        $month = today()->startOfMonth();
        $subscriberIds = Subscription::where('add_on_id', AddOn::entry()->id)
            ->whereDate('covered_month', $month->toDateString())
            ->pluck('member_id')
            ->flip();

        $rows = Member::with('category')->get()
            ->sortBy(fn (Member $member): string => mb_strtolower($member->displayName()))
            ->map(fn (Member $member): array => [
                $member->displayName(),
                $member->username,
                $member->member_number,
                $member->first_name,
                $member->last_name,
                $member->category?->name,
                $this->doorStatus($member),
                $this->alcoholFlag($member),
                $subscriberIds->has($member->id) ? __('Yes') : '',
            ])
            ->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('Roster'));

        $sheet->setCellValue('A1', __(':name emergency roster, as of :when', [
            'name' => MembershipSetting::current()->org_name ?: config('app.name'),
            'when' => now()->format('D M j, Y g:i A'),
        ]));
        $sheet->setCellValue('A2', __('Use only while the system is down. Anything changed since then (a new ban, a new member, a subscription bought) is not here.'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray([
            __('Name'),
            __('Username'),
            __('Member #'),
            __('First name'),
            __('Last name'),
            __('Category'),
            __('Door status'),
            __('Flag'),
            __('Subscription for :month', ['month' => $month->format('M Y')]),
        ], null, 'A'.self::HEADER_ROW);
        $sheet->getStyle('A'.self::HEADER_ROW.':I'.self::HEADER_ROW)->getFont()->setBold(true);
        $sheet->fromArray($rows->all(), null, 'A'.(self::HEADER_ROW + 1));

        $sheet->freezePane('A'.(self::HEADER_ROW + 1));
        $sheet->setAutoFilter('A'.self::HEADER_ROW.':I'.(self::HEADER_ROW + max(1, $rows->count())));
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setWidth(22);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $rows->count();
    }

    /**
     * AdmissionPolicy's outcome with no event to judge against, in the
     * policy's own order. Words only: the sheet may be printed.
     */
    private function doorStatus(Member $member): string
    {
        if ($member->is_deceased || $member->isCurrentlyBanned() || $this->admission->isUnderAgeOfMajority($member)) {
            return __('Do not admit');
        }

        if ($member->on_watchlist) {
            return __('Notify :label', ['label' => MembershipSetting::watchlistNotifyLabel()]);
        }

        if ($this->admission->needsCapture($member) || $this->admission->needsPaperworkCapture($member)) {
            return __('Paperwork needed');
        }

        return __('OK');
    }

    private function alcoholFlag(Member $member): string
    {
        return $this->admission->isUnderAlcoholFlagAge($member)
            ? __('Under :age — no alcohol, mark hand', ['age' => MembershipSetting::current()->alcohol_flag_age])
            : '';
    }
}
