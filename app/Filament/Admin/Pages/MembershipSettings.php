<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\MembershipSetting;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * A single form over the one MembershipSetting row's operational tunables —
 * every club-wide value that used to be env/config-only. Boolean feature
 * toggles split out to App\Filament\Admin\Pages\FeatureFlags once the flag
 * count outgrew this form.
 */
class MembershipSettings extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.membership-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Membership Settings';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // Same floor as Plans/EventTypes/CompReasons (RoleGatedPolicy's default
    // minimumRole) — these values are fee/policy-adjacent settings, not a
    // create/delete-events-level concern, so Manager+ rather than Admin+.
    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->form->fill(MembershipSetting::current()->only([
            'subscription_eligibility_threshold',
            'subscription_eligibility_window_months',
            'probation_period_days',
            'guests_allowed_during_probation',
            'venue_capacity',
            'default_opening_float',
            'event_window_buffer_minutes',
            'age_of_majority',
            'alcohol_flag_age',
            'watchlist_notify_label',
            'currency',
            'locale',
            'org_name',
            'member_search_fields',
            'checkin_display_name_field',
            'member_email_required',
            'hide_member_pii_by_default',
            'active_patrons_show_staff_roles',
            'showrunner_door_includes_pool',
            'showrunner_door_includes_addons',
            'upstream_remote',
            'upstream_branch',
            'deploy_task_name',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('subscription_eligibility_threshold')
                    ->label('Subscription eligibility threshold')
                    ->helperText(__('A member becomes eligible to subscribe once they\'ve attended this many events — all-time, or within the window below.'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('subscription_eligibility_window_months')
                    ->label('Count attended events from the last (months)')
                    ->helperText(__('Only events attended in this many months count toward the threshold above. Leave blank to count all-time. A member flagged "Subscription eligible" by hand stays eligible either way.'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(120),
                TextInput::make('probation_period_days')
                    ->label('Probation period (days)')
                    ->helperText(__('Never affects admission or pricing. A member on probation can\'t bring a guest, unless the setting below allows it.'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Toggle::make('guests_allowed_during_probation')
                    ->label('Allow guests during probation')
                    ->helperText(__('Lets a member still on probation register a guest at the Check-In Desk. Off by default. Turning guests off entirely is on Feature Flags.'))
                    ->required(),
                TextInput::make('venue_capacity')
                    ->label('Venue capacity')
                    ->helperText(__('Hard cap on how many people can be in the building at once. Leave blank to not enforce a capacity limit.'))
                    ->numeric()
                    ->minValue(1),
                TextInput::make('default_opening_float')
                    ->label('Default cash drawer opening float')
                    ->helperText(__('Pre-fills the "opening count" field when a Door+ user opens a new cashbox shift. Leave blank to not pre-fill anything.'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),
                TextInput::make('event_window_buffer_minutes')
                    ->label('Event window buffer (minutes)')
                    ->helperText(__('Minutes of slack on either side of an event\'s start/end time when deciding whether it\'s "current" for the check-in page\'s event picker — lets staff pull an event up a little early and keep working it a little after it ends.'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('age_of_majority')
                    ->label('Age of majority')
                    ->helperText(__('AdmissionPolicy blocks a member below this age outright. Jurisdiction-specific — adjust if your club isn\'t in an 18-is-adult jurisdiction.'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('alcohol_flag_age')
                    ->label('Check-ID / no-alcohol flag age')
                    ->helperText(__('A member below this age (but at or above "Age of majority") is admitted but flagged to check ID / mark their hand. Set equal to "Age of majority" to disable the flag entirely.'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('watchlist_notify_label')
                    ->label('Watchlist: who staff notify')
                    ->helperText(fn (): string => __('Where staff post a heads-up before admitting a watchlisted member, e.g. "the Signal group". The desk shows "Notify …" and asks staff to confirm they did. Leave blank to use the server default (currently ":default").', ['default' => config('membership.watchlist_notify_label')]))
                    ->maxLength(60),
                TextInput::make('currency')
                    ->label('Currency code')
                    ->helperText(__('A 3-letter ISO 4217 currency code (e.g. USD, EUR, GBP, CAD) — used everywhere a dollar figure is shown, from the check-in desk\'s live totals to every money column in the admin panel.'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(3)
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : $state)
                    // ->rule() evaluates a bare Closure through Filament's own
                    // DI resolver (which fails trying to inject $attribute) --
                    // wrapping it in an outer closure that itself takes no
                    // recognized parameters hands the real validation closure
                    // to Laravel's validator unevaluated, as intended.
                    //
                    // NumberFormatter::formatCurrency() is lenient about an
                    // unrecognized code -- it just prints it as a literal
                    // prefix ("ZZZ 1.00") instead of failing -- so validation
                    // can't rely on Number::currency()'s return value. ICU's
                    // own currency data (already shipped with the required
                    // intl extension, no new dependency) is the real source
                    // of truth for "is this a currency ICU knows how to format".
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $known = \ResourceBundle::create('en', 'ICUDATA-curr')->get('Currencies')->get(strtoupper((string) $value));

                        if ($known === null) {
                            $fail(__('Not a recognized currency code.'));
                        }
                    }),
                Select::make('locale')
                    ->label('Language')
                    ->helperText(__('The language of the admin panel for everyone on this installation. Only languages with a translation file in the lang folder are listed; leave blank to use the server default (English unless configured otherwise).'))
                    ->options(fn (): array => MembershipSetting::availableLocales())
                    ->placeholder(__('Server default')),
                TextInput::make('org_name')
                    ->label('Displayed organization name')
                    ->helperText(__('Shown across the admin panel (header, browser tab, login page) in place of ":name". Leave blank to use that default. Owner only.', ['name' => config('app.name')]))
                    ->maxLength(255)
                    ->visible(fn (): bool => Gate::allows('manage-org-name')),
                CheckboxList::make('member_search_fields')
                    ->label('Searchable member fields')
                    ->helperText(__('Which fields staff can search on in every member picker — the check-in desk, the Showrunner/Host selects, subscriptions, vouchers, and the rest. Username is the desk\'s primary lookup; add others only if staff actually need them.'))
                    ->options([
                        'username' => __('Username'),
                        'name' => __('Name (first & last)'),
                        'member_number' => __('Member number'),
                        'preferred_name' => __('Preferred name'),
                        'email' => __('Email'),
                    ])
                    ->minItems(1)
                    ->required(),
                Select::make('checkin_display_name_field')
                    ->label('Check-in desk display name')
                    ->helperText(__('Which field the Check-In Desk shows once a member is selected -- the greeting line, the checked-in roster, voucher labels, and the guest-registration sponsor note. Falls back to username if the chosen field is blank for a given member.'))
                    ->options([
                        'preferred_name' => __('Preferred name'),
                        'full_name' => __('Full name'),
                        'username' => __('Username'),
                    ])
                    ->required(),
                Toggle::make('member_email_required')
                    ->label('Require email at sign-up')
                    ->helperText(__('Whether the Check-In Desk requires an email address when a Prospective finishes sign-up or a guest is registered. Turn off for a club that doesn\'t collect email at the door. Name is always required.'))
                    ->required(),
                Toggle::make('hide_member_pii_by_default')
                    ->label('Hide personal info by default on the Members list')
                    ->helperText(__('Controls the starting state of the "Hide personal info" toggle on the Members list (first/last name, DOB, email). Staff can still flip it for their own session either way.'))
                    ->required(),
                Toggle::make('active_patrons_show_staff_roles')
                    ->label('Show staff roles on Active Patrons')
                    ->helperText(__('Active Patrons notes which staff are signed in ("Also in the building"). Turn this off to list their names only, without each person\'s role.'))
                    ->required(),
                Toggle::make('showrunner_door_includes_pool')
                    ->label('Showrunner commission includes pool revenue')
                    ->helperText(__('Entry revenue always counts toward the showrunner\'s commission base ("the door"). Turn this on to also count pool fee revenue from the same qualifying attendees.'))
                    ->required(),
                Toggle::make('showrunner_door_includes_addons')
                    ->label('Showrunner commission includes add-on revenue')
                    ->helperText(__('Turn this on to also count Event Add-On revenue (private room rental, sleepover, etc.) from the same qualifying attendees toward the showrunner\'s commission base.'))
                    ->required(),
                TextInput::make('upstream_remote')
                    ->label('Upstream git remote name')
                    // Keep this pattern identical to
                    // UpstreamUpdateChecker::SAFE_REF_PATTERN.
                    ->rule('regex:/^[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9])?$/')
                    ->helperText(__('The name of a git remote already added on this server (`git remote add <name> <url>`) that this fork tracks for updates -- this app never adds one itself. Leave blank if this fork doesn\'t track an upstream. Turn on "Upstream update checking enabled" on Feature Flags once set.'))
                    ->maxLength(255),
                TextInput::make('upstream_branch')
                    ->label('Upstream branch')
                    ->rule('regex:/^[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9])?$/')
                    ->helperText(__('The branch on the upstream remote to compare against -- usually "main".'))
                    ->maxLength(255)
                    ->required(),
                TextInput::make('deploy_task_name')
                    ->label('Deploy Scheduled Task name')
                    // Keep this pattern identical to
                    // App\Services\DeployTrigger::assertSafeTaskName() -- unlike
                    // upstream_remote/upstream_branch above, a task name may
                    // contain spaces, so this only blocks a leading '-'/'/'.
                    ->rule('regex:/^[^\s\/-][^\r\n]*$/')
                    ->helperText(__('The exact name of a Windows Scheduled Task, already registered on this server to run scripts/deploy.ps1, that "Run update now" on Upstream Updates should fire -- this app never registers one itself. Leave blank if you don\'t want a web-triggered deploy. Turn on "Web-triggered deploy enabled" on Feature Flags once set.'))
                    ->maxLength(255),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save settings')
            ->action(function (): void {
                $state = $this->form->getState();

                // The field is hidden for non-Owners, but $data is a plain
                // public Livewire property -- re-check server-side rather
                // than trust visibility, same convention as grant-event-comp
                // and the other Door+-open forgeable fields on CheckIn.
                if (! Gate::allows('manage-org-name')) {
                    unset($state['org_name']);
                }

                MembershipSetting::current()->update($state);

                Notification::make()->title(__('Settings saved'))->success()->send();
            });
    }
}
