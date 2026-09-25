<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Subscription;
use App\Support\Cents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only report of check-ins that may have been charged a payment
 * method's transaction fee on a visit with nothing due. Before pricing moved
 * to integer cents, credit plus a voucher that covered a visit exactly could
 * leave 7.2e-16 "due" as a float, which counted as money changing hands and
 * drew the fee.
 *
 * A candidate is a visit whose entry fee, less its coverage and voucher,
 * plus its add-on charges, comes to exactly zero -- yet amount_paid isn't
 * zero, so the whole amount_paid is the fee. A subscription bought at the
 * desk in the same transaction legitimately draws the fee too, but it isn't
 * linked to the visit, so the report only flags a same-day subscription
 * paid the same way for a person to check. Nothing is changed: refunds are
 * the club's decision.
 */
#[Signature('attendance:find-stray-fees')]
#[Description('List check-ins that may have been charged a transaction fee with nothing due (read-only).')]
class FindStrayTransactionFees extends Command
{
    public function handle(): int
    {
        $rows = [];
        $totalCents = 0;

        Attendance::query()
            ->where('amount_paid', '>', 0)
            ->whereNotNull('payment_method')
            ->with(['member', 'event'])
            ->withSum('addOns', 'price')
            ->chunkById(500, function ($chunk) use (&$rows, &$totalCents) {
                foreach ($chunk as $attendance) {
                    $dueCents = Cents::of($attendance->entry_fee)
                        - Cents::of($attendance->entry_coverage)
                        - Cents::of($attendance->voucher_coverage)
                        + Cents::of($attendance->add_ons_sum_price);

                    if ($dueCents !== 0) {
                        continue;
                    }

                    $chargedCents = Cents::of($attendance->amount_paid);
                    $totalCents += $chargedCents;

                    $subscriptionSameDay = Subscription::query()
                        ->where('member_id', $attendance->member_id)
                        ->where('payment_method', $attendance->payment_method)
                        ->whereDate('paid_on', $attendance->created_at)
                        ->exists();

                    $rows[] = [
                        $attendance->id,
                        $attendance->event?->event_date?->toDateString(),
                        $attendance->event?->name,
                        $attendance->member?->username,
                        $attendance->payment_method,
                        Cents::toDecimal($chargedCents),
                        $subscriptionSameDay ? __('yes — check') : __('no'),
                    ];
                }
            });

        if ($rows === []) {
            $this->info(__('No check-ins found with a transaction fee charged on nothing due.'));

            return self::SUCCESS;
        }

        $this->table(
            [__('Attendance'), __('Event date'), __('Event'), __('Member'), __('Payment method'), __('Charged'), __('Subscription bought same day?')],
            $rows,
        );
        $this->warn(__(':count check-in(s), :amount in total. Nothing was changed.', [
            'count' => count($rows),
            'amount' => Cents::toDecimal($totalCents),
        ]));

        return self::SUCCESS;
    }
}
