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
 *
 * check() runs the same rules without writing anything, so a file can be
 * validated before it's uploaded for real.
 */
class VoucherBulkImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath, User $recordedBy): array
    {
        $result = $this->process($filePath, $recordedBy);

        return ['created' => $result['created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with what an upload would do with it: the member
     * it matched (if any) and its problem, or null when it would be issued.
     *
     * @return list<array{row: int, identifier: string, amount: string, reason: string, member: ?string, problem: ?string}>
     */
    public function check(string $filePath): array
    {
        return $this->process($filePath, null)['rows'];
    }

    /**
     * @return array{created: int, log: string[], rows: list<array{row: int, identifier: string, amount: string, reason: string, member: ?string, problem: ?string}>}
     */
    private function process(string $filePath, ?User $recordedBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $identifier = trim((string) $sheet->getCell("A{$row}")->getValue());
            $amountRaw = trim((string) $sheet->getCell("B{$row}")->getValue());
            $reason = trim((string) $sheet->getCell("C{$row}")->getValue());

            if ($identifier === '' && $amountRaw === '' && $reason === '') {
                continue;
            }

            $member = $identifier === '' ? null : (ctype_digit($identifier)
                ? Member::where('member_number', (int) $identifier)->first()
                : Member::where('username', $identifier)->first());

            $problem = $this->problemWith($identifier, $member, $amountRaw, $reason);
            $amount = $problem === null ? Cents::toDecimal(Cents::of($amountRaw)) : null;

            if ($problem === null) {
                // A repeat inside the same file would be caught by the
                // ledger check on a real upload, but check() writes nothing.
                $key = "{$member->id}|{$amount}|{$reason}";

                if (isset($seen[$key])) {
                    $problem = "same member, amount and reason as row {$seen[$key]}";
                } elseif (Voucher::where('member_id', $member->id)->where('amount', $amount)->where('reason', $reason)->exists()) {
                    $problem = "{$member->username} already has a {$amount} voucher for '{$reason}'";
                }

                $seen[$key] ??= $row;
            }

            $rows[] = [
                'row' => $row,
                'identifier' => $identifier,
                'amount' => $amountRaw,
                'reason' => $reason,
                'member' => $member?->username,
                'problem' => $problem,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if ($recordedBy) {
                Voucher::create([
                    'member_id' => $member->id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'recorded_by' => $recordedBy->id,
                ]);

                $created++;
            }
        }

        return ['created' => $created, 'log' => $log, 'rows' => $rows];
    }

    private function problemWith(string $identifier, ?Member $member, string $amountRaw, string $reason): ?string
    {
        return match (true) {
            $identifier === '' => 'blank member identifier',
            $member === null => "no member found for identifier '{$identifier}'",
            ! preg_match('/^-?\d+(\.\d{1,2})?$/', $amountRaw) || Cents::of($amountRaw) === 0 => "amount '{$amountRaw}' must be a non-zero number with at most 2 decimals",
            $reason === '' => 'blank reason',
            mb_strlen($reason) > 255 => 'reason longer than 255 characters',
            default => null,
        };
    }
}
