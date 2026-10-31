<?php

namespace App\Filament\Concerns;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Services\AdmissionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * The Check-In Desk's arrival actions: mark a prepaid member arrived (from
 * the member card or a row of the prepaid table) and undo a departure.
 * Pulled out of CheckIn; behaviour is unchanged. The prepaid table itself
 * stays on the page, since InteractsWithTable already defines table().
 *
 * Relies on the host page for getSelectedMember(), getSelectedEvent(),
 * getExistingAttendance(), getDecision() and haltForTraining().
 */
trait MarksArrivals
{
    public function markArrivedAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $blockedReason = $this->arrivalBlockedReason($this->getSelectedEvent());
        $requiresAcknowledgement = $attendance
            && is_null($attendance->checked_in_at)
            && $this->getDecision()?->requiresAcknowledgement();

        return Action::make('markArrived')
            // The reason is in the label, not a tooltip, so a screen reader
            // hears why the button is disabled.
            ->label($blockedReason ? __('Mark arrived (:reason)', ['reason' => $blockedReason]) : __('Mark arrived'))
            ->disabled($blockedReason !== null)
            ->schema($requiresAcknowledgement ? [
                Checkbox::make('acknowledged')
                    ->label(__('I have notified :label per the watchlist note.', ['label' => MembershipSetting::watchlistNotifyLabel()]))
                    ->accepted()
                    ->required(),
            ] : [])
            ->visible(fn (): bool => (bool) ($attendance && is_null($attendance->checked_in_at)))
            ->action(function (): void {
                if ($this->haltForTraining(__('Practice: marked arrived.'))) {
                    return;
                }

                $attendance = $this->getExistingAttendance();
                $member = $this->getSelectedMember();
                $event = $this->getSelectedEvent();
                abort_unless($attendance && $member && $event, 404);
                // Re-checked here, not just via ->disabled(): a forged call
                // must not mark someone arrived at an event that isn't running.
                abort_if($this->arrivalBlockedReason($event) !== null, 403);

                $decision = app(AdmissionPolicy::class)->decide($member, $event);

                if ($decision->blocksCheckIn()) {
                    Notification::make()
                        ->title($decision->message)
                        ->danger()
                        ->send();

                    return;
                }

                $attendance->update(['checked_in_at' => now()]);

                Notification::make()->title(__('Marked arrived'))->success()->send();
            });
    }

    // The reverse of ActivePatrons::departAction() -- a departed attendance
    // row drops off that page's own query entirely (whereNull('departed_at')),
    // so there's no undo surface there; this is where staff would naturally
    // notice someone's back anyway, since they'd search for the member here
    // the same way as any other arrival. Clears departed_at on the same row
    // rather than creating a new one -- they already paid for tonight, so
    // this is "still here," not a second visit. Same gate as Depart itself.
    public function markAsReturnedAction(): Action
    {
        $attendance = $this->getExistingAttendance();

        return Action::make('markAsReturned')
            ->label(__('Mark as returned'))
            ->color('success')
            ->visible(fn (): bool => (bool) ($attendance?->departed_at) && Gate::allows('record-departures'))
            ->action(function (): void {
                if ($this->haltForTraining(__('Practice: mark-as-returned simulated.'))) {
                    return;
                }

                // Re-checked here, not just via ->visible() -- same
                // defensive pattern as ActivePatrons::departAction() and
                // every other standalone action on this page.
                abort_unless(Gate::allows('record-departures'), 403);

                $attendance = $this->getExistingAttendance();
                abort_unless($attendance && $attendance->departed_at !== null, 404);

                $attendance->update(['departed_at' => null]);

                Notification::make()->title(__('Marked as returned'))->success()->send();
            });
    }

    protected function markArrivedRowAction(): Action
    {
        return Action::make('markArrived')
            ->label(fn (Attendance $record): string => ($reason = $this->arrivalBlockedReason($record->event))
                ? __('Mark arrived (:reason)', ['reason' => $reason])
                : __('Mark arrived'))
            ->disabled(fn (Attendance $record): bool => $this->arrivalBlockedReason($record->event) !== null)
            ->schema(function (Attendance $record) {
                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                return $decision->requiresAcknowledgement() ? [
                    Checkbox::make('acknowledged')
                        ->label(__('I have notified :label per the note.', ['label' => MembershipSetting::watchlistNotifyLabel()]))
                        ->accepted()
                        ->required(),
                ] : [];
            })
            ->action(function (Attendance $record): void {
                if ($this->haltForTraining(__('Practice: marked arrived.'))) {
                    return;
                }

                abort_if($this->arrivalBlockedReason($record->event) !== null, 403);

                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                if ($decision->blocksCheckIn()) {
                    Notification::make()->title($decision->message)->danger()->send();

                    return;
                }

                $record->update(['checked_in_at' => now()]);

                Notification::make()->title(__('Marked arrived'))->success()->send();
            });
    }

    /**
     * Why a prepaid or Comp List arrival can't be marked for this event, or
     * null when it can. A door_prepay_enabled event is on the desk ahead of
     * its date so prepayments can be taken, but nobody arrives at it until
     * it's running: the same Event::isCurrentlyActive() rule a walk-in
     * check-in uses.
     */
    protected function arrivalBlockedReason(?Event $event): ?string
    {
        if ($event === null || $event->isCurrentlyActive()) {
            return null;
        }

        return $event->event_date->isFuture()
            ? __('opens when the event starts')
            : __('the event is over');
    }
}
