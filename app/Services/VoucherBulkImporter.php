<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Cents;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-issues voucher ledger rows from an uploaded spreadsheet -- same
 * "never guess, skip and log" philosophy as the other bulk importers.
 * Members are matched like PrepayListImporter (member number, else
 * username), and each row follows VoucherForm's own rules: any non-zero
 * amount with at most two decimals (negative corrects an earlier grant),
 * and a required reason.
 *
 * The ledger is append-only and has no natural key, so a row exactly
 * matching an existing voucher (same member, amount and reason) is skipped:
 * re-uploading the same file never credits anyone twice. A deliberate
 * repeat grant just needs a different reason (e.g. the month in it).
 */
class VoucherBulkImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath, User $recordedBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $identifier = trim((string) $sheet->getCell("A{$row}")->getValue());
            $amountRaw = trim((string) $sheet->getCell("B{$row}")->getValue());
            $reason = trim((string) $sheet->getCell("C{$row}")->getValue());

            if ($identifier === '' && $amountRaw === '' && $reason === '') {
                continue;
            }

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

            if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $amountRaw) || Cents::of($amountRaw) === 0) {
                $log[] = "row {$row}: amount '{$amountRaw}' must be a non-zero number with at most 2 decimals, skipped";

                continue;
            }

            if ($reason === '') {
                $log[] = "row {$row}: blank reason, skipped";

                continue;
            }

            if (mb_strlen($reason) > 255) {
                $log[] = "row {$row}: reason longer than 255 characters, skipped";

                continue;
            }

            $amount = Cents::toDecimal(Cents::of($amountRaw));

            if (Voucher::where('member_id', $member->id)->where('amount', $amount)->where('reason', $reason)->exists()) {
                $log[] = "row {$row}: {$member->username} already has a {$amount} voucher for '{$reason}', skipped";

                continue;
            }

            Voucher::create([
                'member_id' => $member->id,
                'amount' => $amount,
                'reason' => $reason,
                'recorded_by' => $recordedBy->id,
            ]);

            $created++;
        }

        return ['created' => $created, 'log' => $log];
    }
}
