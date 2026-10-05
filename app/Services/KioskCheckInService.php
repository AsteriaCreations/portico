<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Enums\KioskScanStatus;
use App\Exceptions\CheckInRefused;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * One scan at the unattended kiosk. It admits only the case that needs no
 * human judgment at all: a recognised member, exactly one event running, no
 * visit on file yet, an admission decision of exactly Ok, room in the
 * building, an active Regular subscription for the event's month, and
 * nothing to pay. Anything else sends the member to the desk; the cases
 * staff must handle in person also alert every active Door+
 * (KioskStaffAlert).
 *
 * The desk's watchlist acknowledgement is only a form checkbox, so Warn can
 * never be automated; Flag (under the alcohol-flag age) needs a hand marked.
 * The visit itself is written by CheckInService, the same transaction as the
 * desk, attributed to the system user with the 'kiosk' payment method.
 */
class KioskCheckInService
{
    public function __construct(
        private AdmissionPolicy $admission,
        private CapacityService $capacity,
        private PricingService $pricing,
        private CheckInService $checkIn,
        private KioskStaffAlert $alert,
    ) {}

    public function scan(string $token): KioskScanResult
    {
        $member = Member::where('kiosk_token', $token)->first();
        if (! $member) {
            return new KioskScanResult(KioskScanStatus::NotRecognized, __('This code wasn\'t recognised. Please see the front desk.'));
        }

        $name = $member->displayName();

        // Never a prepay for a later event from an unattended kiosk: only
        // what is running right now, and only when that's unambiguous.
        $events = Event::currentQuery()->get()->filter(fn (Event $event): bool => $event->isCurrentlyActive());
        if ($events->isEmpty()) {
            return new KioskScanResult(KioskScanStatus::Closed, __('There\'s no event running right now.'), $name);
        }
        if ($events->count() > 1) {
            return $this->seeStaff($name, __('More than one event is running tonight. Please check in at the front desk.'));
        }
        $event = $events->first();

        if ($this->hasVisit($member, $event)) {
            return new KioskScanResult(KioskScanStatus::AlreadyCheckedIn, __('You\'re already checked in. Welcome back!'), $name);
        }

        $decision = $this->admission->decide($member, $event);
        if ($decision->outcome !== AdmissionOutcome::Ok) {
            $this->alert->send($member, $decision->message);

            return $this->seeStaff($name);
        }

        if (! $this->capacity->hasRoom($event->event_date)) {
            $this->alert->send($member, __('The building is at capacity.'));

            return $this->seeStaff($name);
        }

        // Both, not just the price: a comped category or a host also prices
        // at nothing, and the kiosk only stands in for the desk for
        // subscribers. Pool or another add-on owed means money changes hands.
        $month = $event->event_date->clone()->startOfMonth();
        if (! $member->hasActiveSubscriptionFor(AddOn::entry(), $month) || $this->pricing->price($member, $event)->amountPaidCents > 0) {
            return $this->seeStaff($name, __('Your subscription doesn\'t cover tonight. Please see the front desk.'));
        }

        $systemUser = User::where('email', config('membership.system_user_email'))->first();
        if (! $systemUser) {
            Log::error('Kiosk check-in refused: the system user ('.config('membership.system_user_email').') was not found — run the database seeder.');

            return $this->seeStaff($name);
        }

        try {
            $this->checkIn->record($member, $event, $systemUser, new CheckInRequest(paymentMethod: PaymentMethod::KIOSK), null);
        } catch (CheckInRefused $refused) {
            // The building filled, or the event was archived, between the
            // checks above and the transaction.
            $this->alert->send($member, $refused->title);

            return $this->seeStaff($name);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            if ($this->hasVisit($member, $event)) {
                return new KioskScanResult(KioskScanStatus::AlreadyCheckedIn, __('You\'re already checked in. Welcome back!'), $name);
            }

            report($exception);

            return $this->seeStaff($name);
        }

        return new KioskScanResult(KioskScanStatus::Admitted, __('Welcome, :name! You\'re checked in.', ['name' => $name]), $name);
    }

    private function hasVisit(Member $member, Event $event): bool
    {
        return Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists();
    }

    private function seeStaff(string $name, ?string $message = null): KioskScanResult
    {
        return new KioskScanResult(KioskScanStatus::SeeStaff, $message ?? __('Please see the front desk.'), $name);
    }
}
