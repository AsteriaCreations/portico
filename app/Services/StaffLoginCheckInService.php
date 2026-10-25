<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Signing in with a login linked to a member (users.member_id) checks that
 * member in to tonight's event, when staff_login_checkin_enabled is on. Like
 * the kiosk (KioskCheckInService) it only does what needs no human judgment:
 * exactly one event running, no visit on file yet, an admission decision of
 * exactly Ok, room, check-in open, and nothing to pay -- any $0 visit
 * (subscription, comped category, host, free event). Anything else tells the
 * person to go to the desk (toast + bell) and alerts the Door+ staff signed
 * in right now. Signing in outside an event, or already checked in, does
 * nothing.
 *
 * The visit is written by CheckInService as the login itself, with no
 * payment method (nothing changed hands). Reasons only Manager+ may read
 * never reach the person: they get "check in at the desk", and the desk
 * gets the admission decision's own message, as with the kiosk.
 */
class StaffLoginCheckInService
{
    public function __construct(
        private AdmissionPolicy $admission,
        private CapacityService $capacity,
        private PricingService $pricing,
        private CheckInService $checkIn,
    ) {}

    public function handle(User $user): void
    {
        if (! MembershipSetting::current()->staff_login_checkin_enabled || ! $user->active || ! $user->member) {
            return;
        }

        $member = $user->member;

        $events = Event::currentQuery()->get()->filter(fn (Event $event): bool => $event->isCurrentlyActive());
        if ($events->isEmpty()) {
            return;
        }
        if ($events->count() > 1) {
            $this->sendToDesk($user, $member, null, __('More than one event is running, so you weren\'t checked in automatically.'), __('More than one event is running.'));

            return;
        }
        $event = $events->first();

        $visit = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->first();
        if ($visit) {
            // Prepaid or on the comp list, not yet arrived: Mark arrived is
            // a desk action (it has no payment), so ask for it.
            if ($visit->checked_in_at === null) {
                $this->sendToDesk($user, $member, $event, __('You\'re on the list for :event: ask the desk to mark you arrived.', ['event' => $event->name]), __('On the list, not yet marked arrived.'));
            }

            return;
        }

        if ($event->isCheckInClosedFor($member)) {
            $this->sendToDesk($user, $member, $event, __('Check-in for :event has closed.', ['event' => $event->name]), __('Check-in has closed.'));

            return;
        }

        $decision = $this->admission->decide($member, $event);
        if ($decision->outcome !== AdmissionOutcome::Ok) {
            $this->sendToDesk($user, $member, $event, __('You weren\'t checked in to :event automatically.', ['event' => $event->name]), $decision->message);

            return;
        }

        if (! $this->capacity->hasRoom($event->event_date)) {
            $this->sendToDesk($user, $member, $event, __(':event is at capacity.', ['event' => $event->name]), __('The building is at capacity.'));

            return;
        }

        $dueCents = $this->pricing->price($member, $event)->amountPaidCents;
        if ($dueCents > 0) {
            $due = MembershipSetting::formatMoney(Cents::toFloat($dueCents));
            $this->sendToDesk($user, $member, $event, __('You owe :amount for :event.', ['amount' => $due, 'event' => $event->name]), __(':amount due.', ['amount' => $due]));

            return;
        }

        try {
            $this->checkIn->record($member, $event, $user, new CheckInRequest, null);
        } catch (CheckInRefused $refused) {
            $this->sendToDesk($user, $member, $event, __('You weren\'t checked in to :event automatically.', ['event' => $event->name]), $refused->title);

            return;
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            // Another register checked them in at the same moment.
            return;
        }

        Notification::make()
            ->title(__('You\'re checked in to :event', ['event' => $event->name]))
            ->success()
            ->send();
    }

    /**
     * Tells the person (toast now, bell to keep) and every other Door+ login
     * signed in right now, who is the one able to act.
     */
    private function sendToDesk(User $user, Member $member, ?Event $event, string $forUser, string $forDesk): void
    {
        $notification = Notification::make()
            ->title($forUser)
            ->body(__('Please check in at the desk.'))
            ->warning();

        $notification->send();

        // sendNow(), not ->sendToDatabase(): this app runs no queue worker
        // (see ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
        LaravelNotification::sendNow($user, $notification->toDatabase());

        $desk = User::signedInStaffQuery()
            ->whereKeyNot($user->id)
            ->get()
            ->filter(fn (User $staff): bool => $staff->role->atLeast(Role::Door));

        if ($desk->isEmpty()) {
            return;
        }

        $alert = Notification::make()
            ->title(__('Staff sign-in: :name needs the desk', ['name' => $member->displayName()]))
            ->body($event ? $event->name.' · '.$forDesk : $forDesk)
            ->warning()
            ->actions([
                Action::make('openDesk')
                    ->label('Open the Check-In Desk')
                    ->url(CheckIn::getUrl())
                    ->markAsRead(),
            ]);

        LaravelNotification::sendNow($desk->values(), $alert->toDatabase());
    }
}
