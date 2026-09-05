<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Member;
use App\Models\PaperworkType;
use App\Services\Concerns\ParsesSpreadsheetValues;
use Illuminate\Database\QueryException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Bulk-creates members from an uploaded spreadsheet — mirrors EventBulkImporter's
 * "never guess, skip and log" philosophy: a blank/duplicate username, an
 * unmatched category/sponsor, a bad boolean, or an unparseable date are all
 * skipped and logged rather than assumed. Create-only, same as
 * EventBulkImporter — a row whose username already exists is skipped, never
 * used to update the existing member.
 */
class MemberBulkImporter
{
    use ParsesSpreadsheetValues;

    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];
        $standardPaperworkTypeId = PaperworkType::where('name', 'Standard Paperwork')->value('id');

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $username = trim((string) $sheet->getCell("A{$row}")->getValue());
            $firstName = trim((string) $sheet->getCell("B{$row}")->getValue());
            $lastName = trim((string) $sheet->getCell("C{$row}")->getValue());
            $preferredName = trim((string) $sheet->getCell("D{$row}")->getValue());
            $email = trim((string) $sheet->getCell("E{$row}")->getValue());
            $emailOptInRaw = trim((string) $sheet->getCell("F{$row}")->getValue());
            $categoryRaw = trim((string) $sheet->getCell("G{$row}")->getValue());
            $sponsorUsername = trim((string) $sheet->getCell("H{$row}")->getValue());
            $memberNumberRaw = trim((string) $sheet->getCell("I{$row}")->getValue());
            $dateVettedRaw = trim((string) $sheet->getCell("J{$row}")->getValue());
            $dobRaw = trim((string) $sheet->getCell("K{$row}")->getValue());
            $paperworkDateRaw = trim((string) $sheet->getCell("L{$row}")->getValue());
            $isActiveRaw = trim((string) $sheet->getCell("M{$row}")->getValue());
            $subscriptionEligibleRaw = trim((string) $sheet->getCell("N{$row}")->getValue());
            $onWatchlistRaw = trim((string) $sheet->getCell("O{$row}")->getValue());
            $watchlistReason = trim((string) $sheet->getCell("P{$row}")->getValue());
            $isBannedRaw = trim((string) $sheet->getCell("Q{$row}")->getValue());
            $banReason = trim((string) $sheet->getCell("R{$row}")->getValue());
            $probationOverrideRaw = trim((string) $sheet->getCell("S{$row}")->getValue());
            $missingPaperworkRaw = trim((string) $sheet->getCell("T{$row}")->getValue());
            $isDeceasedRaw = trim((string) $sheet->getCell("U{$row}")->getValue());
            $hospitalityNote = trim((string) $sheet->getCell("V{$row}")->getValue());
            $notes = trim((string) $sheet->getCell("W{$row}")->getValue());

            if ($username === '' && $firstName === '' && $lastName === '') {
                continue;
            }

            if ($username === '') {
                $log[] = "row {$row}: blank username, skipped";

                continue;
            }

            if (Member::where('username', $username)->exists()) {
                $log[] = "row {$row}: a member with username '{$username}' already exists, skipped";

                continue;
            }

            if ($categoryRaw === '') {
                $log[] = "row {$row}: blank category, skipped";

                continue;
            }

            $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($categoryRaw)])->first();

            if (! $category) {
                $log[] = "row {$row}: no category found named '{$categoryRaw}', skipped";

                continue;
            }

            $sponsorId = null;
            if ($sponsorUsername !== '') {
                $sponsor = Member::where('username', $sponsorUsername)->first();

                if (! $sponsor) {
                    $log[] = "row {$row}: no member found for sponsor_username '{$sponsorUsername}', skipped";

                    continue;
                }

                $sponsorId = $sponsor->id;
            }

            $memberNumber = null;
            if ($memberNumberRaw !== '') {
                if (! ctype_digit($memberNumberRaw)) {
                    $log[] = "row {$row}: non-numeric member_number '{$memberNumberRaw}', skipped";

                    continue;
                }

                $memberNumber = (int) $memberNumberRaw;
            }

            try {
                $dateVetted = $this->parseDate($dateVettedRaw, 'date_vetted');
                $dob = $this->parseDate($dobRaw, 'dob');
                $paperworkDate = $this->parseDate($paperworkDateRaw, 'paperwork_date');
                $probationOverrideStart = $this->parseDate($probationOverrideRaw, 'probation_override_start');

                $isActive = $this->parseBoolean($isActiveRaw, 'is_active', default: true);
                $emailOptIn = $this->parseBoolean($emailOptInRaw, 'email_opt_in', default: false);
                $subscriptionEligible = $this->parseBoolean($subscriptionEligibleRaw, 'subscription_eligible', default: false);
                $onWatchlist = $this->parseBoolean($onWatchlistRaw, 'on_watchlist', default: false);
                $isBanned = $this->parseBoolean($isBannedRaw, 'is_banned', default: false);
                $missingPaperwork = $this->parseBoolean($missingPaperworkRaw, 'missing_paperwork', default: false);
                $isDeceased = $this->parseBoolean($isDeceasedRaw, 'is_deceased', default: false);
            } catch (Throwable $exception) {
                $log[] = "row {$row}: {$exception->getMessage()}, skipped";

                continue;
            }

            try {
                $member = Member::create([
                    'username' => $username,
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'preferred_name' => $preferredName !== '' ? $preferredName : null,
                    'email' => $email !== '' ? $email : null,
                    'email_opt_in' => $emailOptIn,
                    'category_id' => $category->id,
                    'sponsor_id' => $sponsorId,
                    'member_number' => $memberNumber,
                    'date_vetted' => $dateVetted,
                    'dob' => $dob,
                    'is_active' => $isActive,
                    'subscription_eligible' => $subscriptionEligible,
                    'on_watchlist' => $onWatchlist,
                    'watchlist_reason' => $watchlistReason !== '' ? $watchlistReason : null,
                    'is_banned' => $isBanned,
                    'ban_reason' => $banReason !== '' ? $banReason : null,
                    'probation_override_start' => $probationOverrideStart,
                    'missing_paperwork' => $missingPaperwork,
                    'is_deceased' => $isDeceased,
                    'hospitality_note' => $hospitalityNote !== '' ? $hospitalityNote : null,
                    'notes' => $notes !== '' ? $notes : null,
                ]);
            } catch (QueryException) {
                $log[] = "row {$row}: username or member_number already taken, skipped";

                continue;
            }

            // Column L is a "Standard Paperwork" signing date -- recorded in
            // member_paperwork now, not a members column.
            if ($paperworkDate !== null && $standardPaperworkTypeId !== null) {
                $member->paperwork()->create([
                    'paperwork_type_id' => $standardPaperworkTypeId,
                    'signed_on' => $paperworkDate,
                    'recorded_by' => null,
                ]);
            }

            $created++;
        }

        return ['created' => $created, 'log' => $log];
    }
}
