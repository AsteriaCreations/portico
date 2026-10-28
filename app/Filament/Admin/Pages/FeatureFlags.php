<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\MembershipSetting;
use App\Services\FeatureSetupReminders;
use App\Services\KioskQrCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * A single form over the one MembershipSetting row's boolean feature toggles
 * — split out of App\Filament\Admin\Pages\MembershipSettings once the flag
 * count outgrew a single flat form. Every toggle here exists so a club that
 * doesn't want a given feature can turn it off. A flag stops new writes; it
 * never hides data already collected.
 */
class FeatureFlags extends Page
{
    use HasUnsavedDataChangesAlert;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.feature-flags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Feature Flags';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * The kiosk setup link just issued by setUpKioskDeviceAction(), shown
     * once by showKioskSetupLinkAction(). Never stored: only the secret's
     * hash is saved, so leaving the page loses it and the next setup issues
     * a new one.
     */
    public ?string $kioskSetupUrl = null;

    // Same floor as MembershipSettings (RoleGatedPolicy's default
    // minimumRole) — these are policy-adjacent settings, not a
    // create/delete-events-level concern, so Manager+ rather than Admin+.
    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->form->fill(MembershipSetting::current()->only([
            'vouchers_enabled',
            'add_ons_enabled',
            'showrunner_comp_requests_enabled',
            'manager_perk_enabled',
            'suspensions_enabled',
            'pool_enabled',
            'day_pass_future_events_enabled',
            'prepay_enabled',
            'register_shifts_enabled',
            'cash_envelope_reminder_enabled',
            'cash_exchanges_enabled',
            'showrunner_payouts_enabled',
            'instructor_payouts_enabled',
            'visit_notes_enabled',
            'behavior_notes_enabled',
            'guests_enabled',
            'door_username_rename_enabled',
            'door_preferred_name_change_enabled',
            'kiosk_checkin_enabled',
            'staff_login_checkin_enabled',
            'issue_reports_enabled',
            'kiosk_show_code_enabled',
            'kiosk_subscribers_only',
            'hold_desk_prepay_cash',
            'separate_subscription_envelope',
            'upstream_check_enabled',
            'deploy_trigger_enabled',
        ]));

        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                // Grouped by which nav group (see AdminPanelProvider::navigationGroups())
                // owns the resource/page each flag primarily controls -- not a
                // strict partition (several flags also touch the check-in
                // desk), just the most useful scan order.
                Section::make(__('Front of House'))
                    ->components([
                        Toggle::make('showrunner_comp_requests_enabled')
                            ->label('Showrunner comp requests enabled')
                            ->helperText(__('Turns off the Showrunner Comp Requests page and the Comp Requests tab on Events. Leave on unless this club doesn\'t run event Showrunners.'))
                            ->required(),
                        Toggle::make('register_shifts_enabled')
                            ->label('Register shift tracking enabled')
                            ->helperText(__('Turns off the cash-drawer Register section on the check-in page (open/close box, drops, misc payments) and the Register Shifts / Registers admin resources. Leave on unless this club never tracks a physical cash box.'))
                            ->required(),
                        Toggle::make('cash_envelope_reminder_enabled')
                            ->label('Cash envelope reminder on closing the box')
                            ->helperText(__('When a box closes with cash collected, the confirmation stays on screen until it is dismissed and tells staff to seal the cash in an envelope labelled with the Entry / Other amounts and the event(s) or date it covers, with each event\'s prepaid cash (and, by default, subscription cash) in envelopes of their own. Only cash payment methods count. It\'s a reminder only: nothing extra is saved. Has no effect while register shift tracking is off. Off by default.'))
                            ->required(),
                        Toggle::make('cash_exchanges_enabled')
                            ->label('Cash for electronic payments')
                            ->helperText(__('Adds "Cash for an electronic payment" to the desk\'s cash box: someone pays by Venmo (or another non-cash method) and gets the same amount in cash, e.g. for change. The cash comes off the box\'s expected count and the electronic money is listed apart from tonight\'s takings; it is not revenue. A method with a transaction fee adds it to what they pay. Has no effect while register shift tracking is off. Off by default.'))
                            ->required(),
                        Toggle::make('separate_subscription_envelope')
                            ->label('Subscription cash in its own envelope')
                            ->helperText(__('On, the close-box envelope reminder puts subscription cash in an envelope of its own, labelled with the date. Off, it goes in the night\'s envelope with entry and other cash, listed as its own amount. Only changes what the reminder says. Has no effect while the envelope reminder is off. On by default.'))
                            ->required(),
                        Toggle::make('hold_desk_prepay_cash')
                            ->label('Hold desk prepay cash for its event')
                            ->helperText(__('On, cash the Check-In Desk takes for a later event goes in that event\'s prepay envelope, and the box\'s expected count leaves it out. Off, it stays in tonight\'s box and is counted like the rest of tonight\'s cash. Only changes payments taken from now on; cash already held stays held. Cash recorded on an event\'s Prepay List is always held for the event. On by default.'))
                            ->required(),
                        Toggle::make('visit_notes_enabled')
                            ->label('Patron visit notes enabled')
                            ->helperText(__('Turns off the Visit note column on Active Patrons (e.g. a clothing description to spot a flagged patron tonight). Never affects an already-saved note on an existing attendance row, but nothing shows it once it drops off Active Patrons regardless. Leave on unless this club doesn\'t want staff jotting this kind of note.'))
                            ->required(),
                        Toggle::make('behavior_notes_enabled')
                            ->label('Patron behavior notes enabled')
                            ->helperText(__('Turns off adding new behavior notes on Active Patrons. Notes already written stay reviewable by Manager+ on the member\'s profile regardless of this setting. Leave on unless this club doesn\'t want this kind of accountability record.'))
                            ->required(),
                        Toggle::make('guests_enabled')
                            ->label('Guests enabled')
                            ->helperText(__('Turns off "Register a guest" on the Check-In Desk. Guests already registered keep their records and can still be checked in. Whether a member on probation may bring one is on Membership Settings. Leave on unless this club doesn\'t allow guests.'))
                            ->required(),
                        Toggle::make('door_username_rename_enabled')
                            ->label('Door can rename usernames')
                            ->helperText(__('Adds a "Rename username" button for the selected member on the Check-In Desk, so Door staff can fix a username without a Manager. Every rename is still logged (old name, new name, who, when) on the member\'s record, and each one sends Managers and above a notification. Managers and above can always rename from the member\'s edit page. Off by default.'))
                            ->required(),
                        Toggle::make('door_preferred_name_change_enabled')
                            ->label('Door can change preferred names')
                            ->helperText(__('Adds a "Change preferred name" button for the selected member on the Check-In Desk, so Door staff can update what a member goes by without a Manager. Changes aren\'t logged, same as editing it on the member\'s page. Managers and above see the button either way. Off by default.'))
                            ->required(),
                        Toggle::make('reset_desk_event_on_member_change')
                            ->label('Pick the event again for each member')
                            ->helperText(__('On the Check-In Desk, picking a different member puts Event back to what a fresh page shows: tonight\'s event, or blank when several events overlap, so the desk has to choose. If the member is already on exactly one of tonight\'s events (prepaid, on the comp list or checked in), that one is chosen for them. Off, Event stays on whatever was last picked. On by default.'))
                            ->required(),
                        Toggle::make('kiosk_checkin_enabled')
                            ->label('Kiosk check-in')
                            ->helperText(__('Adds a "Kiosk QR code" button for the selected member on the Check-In Desk and on the member\'s page, showing a code to print or photograph. The code only identifies the member: the kiosk lets them in only when they owe nothing (and, by default, have an active subscription), and sends anyone else to the desk. Off by default.'))
                            ->required(),
                        Toggle::make('staff_login_checkin_enabled')
                            ->label('Check in staff when they sign in')
                            ->helperText(__('Signing in with a login linked to a member (My account, or Users) checks that member in to tonight\'s event when they owe nothing: a subscription, a comped category, the host, or a free event. Anything else (money due, two events at once, check-in closed or full, a watchlist or ID check) tells them to check in at the desk and notifies the Door staff signed in. Signing in when no event is running does nothing. Off by default.'))
                            ->required(),
                        Toggle::make('issue_reports_enabled')
                            ->label('Report a problem from the desk')
                            ->helperText(__('Adds a "Report a problem" button to the Check-In Desk. It saves what the desk is showing (the event, the choices made, the admission decision, the price and the member\'s situation) with names, contact details, date of birth, notes and reasons left out, for Manager+ to review under Issue reports. Off by default.'))
                            ->required(),
                        Toggle::make('kiosk_subscribers_only')
                            ->label('Kiosk admits subscribers only')
                            ->helperText(__('On, the kiosk lets a member in only with an active subscription for the event\'s month. Off, it also lets in anyone else who owes nothing tonight, such as a comped category or the event\'s host. Either way, anyone with something to pay, a watchlist or ID check, or anything else for staff is sent to the desk. Needs Kiosk check-in on. On by default.'))
                            ->required(),
                        Toggle::make('kiosk_show_code_enabled')
                            ->label('Show codes on the kiosk')
                            ->helperText(__('Adds a "Show on kiosk" button next to "Kiosk QR code", for a desk whose screen faces staff. The kiosk tablet shows the member\'s code (for 60 seconds by default; set on Membership Settings) so they can take a picture of it with their phone. Anyone at the tablet can see it while it\'s up. Needs Kiosk check-in on. Off by default.'))
                            ->required(),
                    ]),
                Section::make(__('Records'))
                    ->components([
                        Toggle::make('vouchers_enabled')
                            ->label('Vouchers enabled')
                            ->helperText(__('Turns off the Vouchers resource (nav item and direct access) and voucher redemption at check-in. Leave on unless this club never uses account-credit vouchers.'))
                            ->required(),
                        Toggle::make('manager_perk_enabled')
                            ->label('Manager & Owner subscription perk enabled')
                            ->helperText(__('Turns off the monthly free-subscription perk Managers and Owners can grant from the Subscriptions list. Leave on unless this club doesn\'t offer this perk.'))
                            ->required(),
                        Toggle::make('suspensions_enabled')
                            ->label('Suspensions enabled')
                            ->helperText(__('Turns off the "Suspended until" date field on a banned member\'s record, leaving only permanent bans settable. Doesn\'t affect an already-set suspension date.'))
                            ->required(),
                        Toggle::make('prepay_enabled')
                            ->label('Door-ahead prepay enabled')
                            ->helperText(__('Turns off the per-event "Allow prepay ahead of the door" toggle and the Prepay List tab on Events. Building capacity enforcement is separate -- leave venue_capacity blank on Membership Settings to disable that regardless of this setting. Leave on unless this club never takes payment before the day of an event.'))
                            ->required(),
                    ]),
                Section::make(__('Desk & Money'))
                    ->components([
                        Toggle::make('add_ons_enabled')
                            ->label('Event Add-Ons enabled')
                            ->helperText(__('Turns off the Add-Ons resource (nav item and direct access) and add-on selection at check-in. Leave on unless this club never sells priced extras like a rentable room or sleepover.'))
                            ->required(),
                        Toggle::make('pool_enabled')
                            ->label('Pool enabled')
                            ->helperText(__('Turns off the pool fee field on events, Pool subscriptions, and pool day passes everywhere they can be newly created. Never affects existing pool-priced events or already-purchased pool coverage. Leave on unless this club has no pool.'))
                            ->required(),
                        Toggle::make('day_pass_future_events_enabled')
                            ->label('Day passes for later events')
                            ->helperText(__('Lets the Check-In Desk sell a day pass (e.g. Pool) for an event that isn\'t running tonight. That money goes into tonight\'s box with nothing tying it to the later event, so it\'s off by default: the desk only offers tonight\'s events.'))
                            ->required(),
                    ]),
                Section::make(__('Members & Events'))
                    ->components([
                        Toggle::make('showrunner_payouts_enabled')
                            ->label('Showrunner door commission enabled')
                            ->helperText(__('Turns off the Showrunner Payout Tiers resource and the commission breakdown shown on an event\'s edit page. Leave on unless this club doesn\'t pay Showrunners a cut of the door.'))
                            ->required(),
                        Toggle::make('instructor_payouts_enabled')
                            ->label('Instructor per-head pay enabled')
                            ->helperText(__('Turns off the Instructor Pay Rates tab on Event Types, the instructor payout breakdown shown on an event\'s edit page, and paying the instructor from the Check-In Desk. Leave on unless this club doesn\'t pay instructors per attendee.'))
                            ->required(),
                    ]),
                Section::make(__('System'))
                    ->components([
                        Toggle::make('upstream_check_enabled')
                            ->label('Upstream update checking enabled')
                            ->helperText(__('Turns on the Upstream Updates page and its scheduled git fetch. Only useful if this fork tracks an upstream remote -- see Membership Settings for the remote/branch to configure, and docs/DEPLOYMENT.md for the required manual `git remote add` step. Off by default: unlike other flags here, there\'s no existing behavior to preserve.'))
                            ->required(),
                        Toggle::make('deploy_trigger_enabled')
                            ->label('Web-triggered deploy enabled')
                            ->helperText(__('Turns on the "Run update now" button on the Upstream Updates page, which hands off to a Windows Scheduled Task running scripts/deploy.ps1 -- see docs/DEPLOYMENT.md §7. Also needs a configured deploy task name on Membership Settings and a one-time Scheduled Task registration on the server; this app never registers one itself. Off by default: this is a new, high-risk capability, not existing behavior to preserve.'))
                            ->required(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->setUpKioskDeviceAction()];
    }

    /**
     * Issues a new kiosk device secret and shows the tablet's setup link
     * (with the secret in it) once. Any tablet set up with the old one stops
     * working until it opens the new link.
     */
    public function setUpKioskDeviceAction(): Action
    {
        return Action::make('setUpKioskDevice')
            ->label('Set up a kiosk tablet')
            ->icon(Heroicon::OutlinedDeviceTablet)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('set-up-kiosk-device'))
            ->requiresConfirmation()
            ->modalDescription(__('This makes a new setup link for the kiosk tablet. A tablet already set up stops working until the new link is opened on it.'))
            ->modalSubmitActionLabel(__('Make a new link'))
            ->action(function (): void {
                abort_unless(Gate::allows('set-up-kiosk-device'), 403);

                $secret = MembershipSetting::current()->regenerateKioskDeviceSecret();
                $this->kioskSetupUrl = route('kiosk', ['key' => $secret]);

                $this->replaceMountedAction('showKioskSetupLink');
            });
    }

    // Never a button of its own: opened only by setUpKioskDeviceAction().
    public function showKioskSetupLinkAction(): Action
    {
        return Action::make('showKioskSetupLink')
            ->label('Kiosk setup link')
            ->visible(fn (): bool => $this->kioskSetupUrl !== null && Gate::allows('set-up-kiosk-device'))
            ->modalHeading(__('Open this on the kiosk tablet'))
            ->modalContent(fn () => view('filament.admin.kiosk-setup-link', [
                'url' => $this->kioskSetupUrl,
                'qrDataUri' => $this->kioskSetupUrl ? app(KioskQrCode::class)->pngDataUri($this->kioskSetupUrl) : null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Done'));
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save settings')
            ->action(function (): void {
                $setting = MembershipSetting::current();
                $state = $this->form->getState();

                $turnedOn = collect($state)
                    ->filter(fn (mixed $value, string $flag): bool => (bool) $value && ! $setting->{$flag})
                    ->keys()
                    ->all();

                $setting->update($state);
                $this->rememberData();

                $reminders = app(FeatureSetupReminders::class)->sendFor(auth()->user(), $turnedOn);

                Notification::make()
                    ->title(__('Settings saved'))
                    ->body($reminders > 0 ? trans_choice(':count setup reminder added to your notifications (the bell).|:count setup reminders added to your notifications (the bell).', $reminders) : null)
                    ->success()
                    ->send();
            });
    }
}
