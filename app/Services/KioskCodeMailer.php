<?php

namespace App\Services;

use App\Mail\KioskCodeEmail;
use App\Models\AddOn;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails members their kiosk QR code (KioskCodeEmail), one at a time from
 * the desk or member page, or in batches to this month's subscribers. Sent
 * synchronously -- there's no queue worker -- so a batch is capped and
 * resumable: each sent member is stamped kiosk_token_emailed_at and skipped
 * next time. Until the server has a real mail transport (MAIL_MAILER=log),
 * a single "send" only lands in the log and marks nothing, and a batch
 * doesn't run at all; isConfigured() lets the screens say so.
 */
class KioskCodeMailer
{
    /** Per click: a slow SMTP round trip per member, inside one web request. */
    public const BATCH_SIZE = 50;

    public function __construct(private KioskQrCode $qr) {}

    public static function isConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Sends one member their code, creating it if they have none. Throws if
     * the mail server refuses; the member isn't stamped as emailed then.
     */
    public function send(Member $member): void
    {
        $token = $member->ensureKioskToken();

        Mail::to($member->email)->send(new KioskCodeEmail($member, $this->qr->png($token)));

        // Only a real delivery counts: a "send" to the log must leave them
        // pending, so they're included once the club sets up email.
        if (self::isConfigured()) {
            $member->forceFill(['kiosk_token_emailed_at' => now()])->save();
        }
    }

    /**
     * Active members with an email, opted in to club email, an active Regular
     * subscription this month, and no code emailed since it was last issued.
     *
     * @return Builder<Member>
     */
    public function pendingSubscribers(): Builder
    {
        $entry = AddOn::entry();

        return Member::query()
            ->where('is_active', true)
            ->where('email_opt_in', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereNull('kiosk_token_emailed_at')
            ->whereHas('subscriptions', fn (Builder $query) => $query
                ->where('add_on_id', $entry->id)
                ->whereDate('covered_month', now()->startOfMonth()->toDateString()));
    }

    /**
     * Sends the next batch of pendingSubscribers(). A member the mail server
     * refuses is reported and left pending; the rest of the batch continues.
     *
     * @return array{sent: int, failed: list<string>, remaining: int}
     */
    public function sendBatch(int $limit = self::BATCH_SIZE): array
    {
        // Logging a whole batch would mark nobody and help nobody.
        if (! self::isConfigured()) {
            return ['sent' => 0, 'failed' => [], 'remaining' => $this->pendingSubscribers()->count()];
        }

        $sent = 0;
        $failed = [];

        foreach ($this->pendingSubscribers()->orderBy('id')->limit($limit)->get() as $member) {
            try {
                $this->send($member);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $member->username;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $this->pendingSubscribers()->count()];
    }
}
