<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\MembershipSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
    protected string $view = 'filament.admin.pages.feature-flags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Feature Flags';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

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
            'prepay_enabled',
            'register_shifts_enabled',
            'showrunner_payouts_enabled',
            'instructor_payouts_enabled',
            'visit_notes_enabled',
            'behavior_notes_enabled',
            'upstream_check_enabled',
            'deploy_trigger_enabled',
        ]));
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
                Section::make('Front of House')
                    ->components([
                        Toggle::make('showrunner_comp_requests_enabled')
                            ->label('Showrunner comp requests enabled')
                            ->helperText('Turns off the Showrunner Comp Requests page and the Comp Requests tab on Events. Leave on unless this club doesn\'t run event Showrunners.')
                            ->required(),
                        Toggle::make('register_shifts_enabled')
                            ->label('Register shift tracking enabled')
                            ->helperText('Turns off the cash-drawer Register section on the check-in page (open/close box, drops, misc payments) and the Register Shifts / Registers admin resources. Leave on unless this club never tracks a physical cash box.')
                            ->required(),
                        Toggle::make('visit_notes_enabled')
                            ->label('Patron visit notes enabled')
                            ->helperText('Turns off the Visit note column on Active Patrons (e.g. a clothing description to spot a flagged patron tonight). Never affects an already-saved note on an existing attendance row, but nothing shows it once it drops off Active Patrons regardless. Leave on unless this club doesn\'t want staff jotting this kind of note.')
                            ->required(),
                        Toggle::make('behavior_notes_enabled')
                            ->label('Patron behavior notes enabled')
                            ->helperText('Turns off adding new behavior notes on Active Patrons. Notes already written stay reviewable by Manager+ on the member\'s profile regardless of this setting. Leave on unless this club doesn\'t want this kind of accountability record.')
                            ->required(),
                    ]),
                Section::make('Records')
                    ->components([
                        Toggle::make('vouchers_enabled')
                            ->label('Vouchers enabled')
                            ->helperText('Turns off the Vouchers resource (nav item and direct access) and voucher redemption at check-in. Leave on unless this club never uses account-credit vouchers.')
                            ->required(),
                        Toggle::make('manager_perk_enabled')
                            ->label('Manager & Owner subscription perk enabled')
                            ->helperText('Turns off the monthly free-subscription perk Managers and Owners can grant from the Subscriptions list. Leave on unless this club doesn\'t offer this perk.')
                            ->required(),
                        Toggle::make('suspensions_enabled')
                            ->label('Suspensions enabled')
                            ->helperText('Turns off the "Suspended until" date field on a banned member\'s record, leaving only permanent bans settable. Doesn\'t affect an already-set suspension date.')
                            ->required(),
                        Toggle::make('prepay_enabled')
                            ->label('Door-ahead prepay enabled')
                            ->helperText('Turns off the per-event "Allow prepay ahead of the door" toggle and the Prepay List tab on Events. Building capacity enforcement is separate -- leave venue_capacity blank on Membership Settings to disable that regardless of this setting. Leave on unless this club never takes payment before the day of an event.')
                            ->required(),
                    ]),
                Section::make('Desk & Money')
                    ->components([
                        Toggle::make('add_ons_enabled')
                            ->label('Event Add-Ons enabled')
                            ->helperText('Turns off the Add-Ons resource (nav item and direct access) and add-on selection at check-in. Leave on unless this club never sells priced extras like a rentable room or sleepover.')
                            ->required(),
                        Toggle::make('pool_enabled')
                            ->label('Pool enabled')
                            ->helperText('Turns off the pool fee field on events, Pool subscriptions, and pool day passes everywhere they can be newly created. Never affects existing pool-priced events or already-purchased pool coverage. Leave on unless this club has no pool.')
                            ->required(),
                    ]),
                Section::make('Members & Events')
                    ->components([
                        Toggle::make('showrunner_payouts_enabled')
                            ->label('Showrunner door commission enabled')
                            ->helperText('Turns off the Showrunner Payout Tiers resource and the commission breakdown shown on an event\'s edit page. Leave on unless this club doesn\'t pay Showrunners a cut of the door.')
                            ->required(),
                        Toggle::make('instructor_payouts_enabled')
                            ->label('Instructor per-head pay enabled')
                            ->helperText('Turns off the Instructor Pay Rates tab on Event Types and the instructor payout breakdown shown on an event\'s edit page. Leave on unless this club doesn\'t pay instructors per attendee.')
                            ->required(),
                    ]),
                Section::make('System')
                    ->components([
                        Toggle::make('upstream_check_enabled')
                            ->label('Upstream update checking enabled')
                            ->helperText('Turns on the Upstream Updates page and its scheduled git fetch. Only useful if this fork tracks an upstream remote -- see Membership Settings for the remote/branch to configure, and docs/DEPLOYMENT.md for the required manual `git remote add` step. Off by default: unlike other flags here, there\'s no existing behavior to preserve.')
                            ->required(),
                        Toggle::make('deploy_trigger_enabled')
                            ->label('Web-triggered deploy enabled')
                            ->helperText('Turns on the "Run update now" button on the Upstream Updates page, which hands off to a Windows Scheduled Task running scripts/deploy.ps1 -- see docs/DEPLOYMENT.md §7. Also needs a configured deploy task name on Membership Settings and a one-time Scheduled Task registration on the server; this app never registers one itself. Off by default: this is a new, high-risk capability, not existing behavior to preserve.')
                            ->required(),
                    ]),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save settings')
            ->action(function (): void {
                MembershipSetting::current()->update($this->form->getState());

                Notification::make()->title('Settings saved')->success()->send();
            });
    }
}
