<?php

namespace App\Services;

use App\Filament\Admin\Pages\MembershipSettings;
use App\Filament\Admin\Resources\AddOns\AddOnResource;
use App\Filament\Admin\Resources\CompReasons\CompReasonResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\EventTypes\EventTypeResource;
use App\Filament\Admin\Resources\PaperworkTypes\PaperworkTypeResource;
use App\Filament\Admin\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Admin\Resources\Registers\RegisterResource;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\ShowrunnerPayoutTierResource;
use App\Models\AddOn;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\InstructorPayRate;
use App\Models\MembershipSetting;
use App\Models\PaperworkType;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Register;
use App\Models\ShowrunnerPayoutTier;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * When someone turns a feature on at Feature Flags, sends them a bell
 * notification for each setup step that still looks undone, with a button
 * straight to the screen where it's done. A step already done (e.g. a Pool
 * plan exists) sends nothing, so turning a feature off and on again doesn't
 * nag, and a step on a screen the person can't open is skipped.
 *
 * To add reminders for a new feature flag, add an entry to reminders().
 */
class FeatureSetupReminders
{
    /**
     * Sends $user the reminders for every flag in $turnedOn, returning how
     * many were sent.
     *
     * @param  list<string>  $turnedOn  feature-flag columns that just went from off to on
     */
    public function sendFor(User $user, array $turnedOn): int
    {
        $sent = 0;
        $reminders = $this->reminders();

        foreach ($turnedOn as $flag) {
            foreach ($reminders[$flag] ?? [] as $reminder) {
                if (! ($reminder['canOpen'])() || ! ($reminder['needed'])()) {
                    continue;
                }

                $notification = Notification::make()
                    ->title($reminder['title'])
                    ->body($reminder['body'])
                    ->icon('heroicon-o-clipboard-document-check')
                    ->info()
                    ->actions([
                        Action::make('open')
                            ->label(__('Open'))
                            ->url(($reminder['url'])())
                            ->markAsRead(),
                    ]);

                // sendNow(), not sendToDatabase(): this app runs no queue
                // worker, and Filament's database notification is queued.
                LaravelNotification::sendNow($user, $notification->toDatabase());
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Every flag's setup steps. `needed` says whether the step still looks
     * undone; `canOpen` whether the person can reach the linked screen.
     *
     * @return array<string, list<array{title: string, body: string, url: Closure(): string, canOpen: Closure(): bool, needed: Closure(): bool}>>
     */
    protected function reminders(): array
    {
        $upcomingEvents = fn (): Builder => Event::query()->whereNull('archived_at')->whereDate('event_date', '>=', today());
        $settings = MembershipSetting::current();
        $settingsPage = fn (): string => MembershipSettings::getUrl();
        $canOpenSettings = fn (): bool => MembershipSettings::canAccess();

        return [
            'pool_enabled' => [
                $this->reminder(
                    __('Pool: set the Pool subscription price'),
                    __('Pool is on, but there\'s no Pool subscription plan yet.'),
                    PlanResource::class,
                    fn (): bool => ($pool = AddOn::pool()) !== null && ! Plan::where('add_on_id', $pool->id)->exists(),
                ),
                $this->reminder(
                    __('Pool: check the Pool Waiver'),
                    __('No active waiver is linked to Pool, so members can use it without signing one. Set "Gates add-on" on the waiver if the club requires it.'),
                    PaperworkTypeResource::class,
                    fn (): bool => ($pool = AddOn::pool()) !== null && ! PaperworkType::where('active', true)->where('gates_add_on_id', $pool->id)->exists(),
                ),
                $this->reminder(
                    __('Pool: set pool fees on upcoming events'),
                    __('None of the upcoming events has a pool fee yet.'),
                    EventResource::class,
                    fn (): bool => $upcomingEvents()->exists() && ! $upcomingEvents()->where('pool_fee', '>', 0)->exists(),
                ),
            ],
            'add_ons_enabled' => [
                $this->reminder(
                    __('Add-ons: add your add-ons and prices'),
                    __('There are no active add-ons (room rental, sleepover, …) to sell yet.'),
                    AddOnResource::class,
                    fn (): bool => ! AddOn::where('active', true)->where('subscribable', false)->exists(),
                ),
                $this->reminder(
                    __('Add-ons: choose which events offer them'),
                    __('None of the upcoming events offers an add-on yet — pick them under "Available add-ons" on each event.'),
                    EventResource::class,
                    fn (): bool => $upcomingEvents()->exists() && ! $upcomingEvents()->whereHas('addOns')->exists(),
                ),
            ],
            'register_shifts_enabled' => [
                $this->reminder(
                    __('Register shifts: add your cash drawers'),
                    __('There are no active registers for staff to open at the Check-In Desk.'),
                    RegisterResource::class,
                    fn (): bool => ! Register::where('active', true)->exists(),
                ),
                $this->reminder(
                    __('Register shifts: mark cash as needing the register'),
                    __('No payment method has "Requires the register to be open" turned on, so nothing counts toward the cash count.'),
                    PaymentMethodResource::class,
                    fn (): bool => ! PaymentMethod::where('active', true)->where('requires_register_shift', true)->exists(),
                ),
                [
                    'title' => __('Register shifts: set the default opening float'),
                    'body' => __('Pre-fills the opening count when staff open a register.'),
                    'url' => $settingsPage,
                    'canOpen' => $canOpenSettings,
                    'needed' => fn (): bool => $settings->default_opening_float === null,
                ],
            ],
            'prepay_enabled' => [
                $this->reminder(
                    __('Prepay: turn it on for the events that allow it'),
                    __('Prepay is per event — none of the upcoming events has "Allow prepay ahead of the door" on yet.'),
                    EventResource::class,
                    fn (): bool => $upcomingEvents()->exists() && ! $upcomingEvents()->where('door_prepay_enabled', true)->exists(),
                ),
            ],
            'vouchers_enabled' => [
                $this->reminder(
                    __('Vouchers: set reward amounts on comp reasons (optional)'),
                    __('A comp reason with "Grants a voucher of" set automatically rewards comped attendees once the event ends. None has one yet.'),
                    CompReasonResource::class,
                    fn (): bool => ! CompReason::where('grants_voucher_amount', '>', 0)->exists(),
                ),
            ],
            'showrunner_comp_requests_enabled' => [
                $this->reminder(
                    __('Comp requests: assign Showrunners to events'),
                    __('A Showrunner can only request comps for an event they\'re assigned to. None of the upcoming events has one yet.'),
                    EventResource::class,
                    fn (): bool => $upcomingEvents()->exists() && ! $upcomingEvents()->whereNotNull('showrunner_id')->exists(),
                ),
            ],
            'showrunner_payouts_enabled' => [
                $this->reminder(
                    __('Showrunner commission: set up payout tiers'),
                    __('There are no payout tiers yet, so no commission is worked out.'),
                    ShowrunnerPayoutTierResource::class,
                    fn (): bool => ! ShowrunnerPayoutTier::query()->exists(),
                ),
            ],
            'instructor_payouts_enabled' => [
                $this->reminder(
                    __('Instructor pay: set pay rates on your event types'),
                    __('No event type has an instructor pay rate yet — open an event type\'s "Instructor Pay Rates" tab.'),
                    EventTypeResource::class,
                    fn (): bool => ! InstructorPayRate::query()->exists(),
                ),
            ],
            'guests_enabled' => [
                [
                    'title' => __('Guests: review the guest rules'),
                    'body' => __('Check "Allow guests during probation" and "Max guests per member per night" on Membership Settings.'),
                    'url' => $settingsPage,
                    'canOpen' => $canOpenSettings,
                    'needed' => fn (): bool => true,
                ],
            ],
            'upstream_check_enabled' => [
                [
                    'title' => __('Upstream checking: set the upstream remote'),
                    'body' => __('Enter the git remote this install tracks on Membership Settings.'),
                    'url' => $settingsPage,
                    'canOpen' => $canOpenSettings,
                    'needed' => fn (): bool => blank($settings->upstream_remote),
                ],
            ],
            'deploy_trigger_enabled' => [
                [
                    'title' => __('Web deploy: set the Scheduled Task name'),
                    'body' => __('Enter the Windows Scheduled Task that runs the deploy on Membership Settings.'),
                    'url' => $settingsPage,
                    'canOpen' => $canOpenSettings,
                    'needed' => fn (): bool => blank($settings->deploy_task_name),
                ],
            ],
        ];
    }

    /**
     * A step that links to a resource's list page.
     *
     * @param  class-string  $resource
     * @return array{title: string, body: string, url: Closure(): string, canOpen: Closure(): bool, needed: Closure(): bool}
     */
    private function reminder(string $title, string $body, string $resource, Closure $needed): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'url' => fn (): string => $resource::getUrl('index'),
            'canOpen' => fn (): bool => $resource::canAccess(),
            'needed' => $needed,
        ];
    }
}
