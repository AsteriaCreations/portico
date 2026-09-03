<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Mail\EventEndedSummary;
use App\Models\CommandRun;
use App\Models\Event;
use App\Models\User;
use App\Services\EventSummaryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Emails and in-app-notifies the Owner(s) plus the event's own assigned
 * Showrunner (if any) once an event has ended, with a short attendance/
 * revenue summary. Run on a timer via Windows Task Scheduler, the same way
 * backup:database and vouchers:grant-comp-rewards already are — this app has
 * no Laravel scheduler. Admin deliberately isn't pushed anything here — the
 * same summary is available live on the event's own edit page instead (see
 * EventForm).
 */
#[Signature('events:notify-ended')]
#[Description("Email and notify the Owner(s) and an event's assigned Showrunner once it has ended.")]
class NotifyEventEnded extends Command
{
    public function handle(EventSummaryService $summaryService): int
    {
        $events = Event::whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->whereNull('ended_notification_sent_at')
            ->get();

        $notified = 0;

        foreach ($events as $event) {
            $recipients = User::query()
                ->where('active', true)
                ->where(fn ($query) => $query
                    ->where('role', Role::Owner)
                    ->when($event->showrunner_id, fn ($query) => $query->orWhere('member_id', $event->showrunner_id)))
                ->get();

            if ($recipients->isNotEmpty()) {
                $summary = $summaryService->forEvent($event);

                foreach ($recipients as $recipient) {
                    if ($recipient->email) {
                        Mail::to($recipient->email)->send(new EventEndedSummary($event, $summary));
                    }
                }

                $notification = Notification::make()
                    ->title("Event ended: {$event->name}")
                    ->body("{$summary['checked_in']} checked in, {$summary['prepaid_no_show']} prepaid but never arrived — \${$summary['revenue']} revenue.")
                    ->actions([
                        Action::make('view')
                            ->label('View event')
                            ->url(EventResource::getUrl('edit', ['record' => $event]))
                            ->markAsRead(),
                    ]);

                // Same reasoning as ShowrunnerCompRequests::notifyAdminsOfNewRequest():
                // DatabaseNotification implements ShouldQueue, and this app's
                // real .env has no queue worker, so sendNow() (not
                // ->sendToDatabase()) is required to actually deliver it.
                LaravelNotification::sendNow($recipients, $notification->toDatabase());

                $notified++;
            }

            $event->update(['ended_notification_sent_at' => now()]);
        }

        $this->info("Events notified: {$notified} (of {$events->count()} newly ended).");
        CommandRun::recordSuccess('events:notify-ended');

        return self::SUCCESS;
    }
}
