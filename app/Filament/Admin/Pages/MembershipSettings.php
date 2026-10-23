<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Category;
use App\Models\MembershipSetting;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * A single form over the one MembershipSetting row's operational tunables —
 * every club-wide value that used to be env/config-only. Boolean feature
 * toggles split out to App\Filament\Admin\Pages\FeatureFlags once the flag
 * count outgrew this form.
 */
class MembershipSettings extends Page
{
    use HasUnsavedDataChangesAlert;
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
            'max_guests_per_night',
            'guest_followup_moves_category',
            'guest_followup_category_id',
            'venue_capacity',
            'default_opening_float',
            'event_window_buffer_minutes',
            'checkin_close_minutes_overnight',
            'checkin_close_minutes_same_day',
            'first_visit_after_close_enabled',
            'kiosk_show_code_seconds',
            'kiosk_result_seconds',
            'kiosk_problem_result_seconds',
            'kiosk_same_code_cooldown_seconds',
            'kiosk_requests_per_minute',
            'kiosk_code_email_batch_size',
            'week_starts_on',
            'age_of_majority',
            'alcohol_flag_age',
            'watchlist_notify_label',
            'watchlist_probation_mode',
            'watchlist_probation_days',
            'watchlist_probation_blocks_guests',
            'house_policy_banned_headline',
            'house_policy_banned_instructions',
            'house_policy_watchlist_headline',
            'house_policy_watchlist_instructions',
            'house_policy_under_majority_headline',
            'house_policy_under_majority_instructions',
            'house_policy_under_alcohol_age_headline',
            'house_policy_under_alcohol_age_instructions',
            'currency',
            'locale',
            'org_name',
            'org_logo_path',
            'member_search_fields',
            'checkin_display_name_field',
            'member_email_required',
            'hide_member_pii_by_default',
            'active_patrons_show_staff_roles',
            'upstream_remote',
            'upstream_branch',
            'deploy_task_name',
        ]));

        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                // Grouped by what each setting is about, in the order a new club
                // would set them up. The two set-once technical groups start
                // collapsed. Layout only: field names and the saved state are flat.
                Section::make(__('Organization'))
                    ->components([
                        TextInput::make('org_name')
                            ->label('Displayed organization name')
                            ->helperText(__('Shown across the admin panel (header, browser tab, login page) in place of ":name". Leave blank to use that default. Owner only.', ['name' => config('app.name')]))
                            ->maxLength(255)
                            ->visible(fn (): bool => Gate::allows('manage-org-name')),
                        // No SVG: an uploaded SVG served from this origin can carry script.
                        FileUpload::make('org_logo_path')
                            ->label('Organization logo')
                            ->helperText(__('Shown in place of the organization name in the admin panel header, on the login page, and on the kiosk screen. Pick one that reads on both light and dark backgrounds. PNG, JPG or WebP, up to 1 MB. Leave empty to show the name as text. Owner only.'))
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(1024)
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->visible(fn (): bool => Gate::allows('manage-org-name')),
                        Select::make('locale')
                            ->label('Language')
                            ->helperText(__('The language of the admin panel for everyone on this installation. Only languages with a translation file in the lang folder are listed; leave blank to use the server default (English unless configured otherwise).'))
                            ->options(fn (): array => MembershipSetting::availableLocales())
                            ->placeholder(__('Server default')),
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
                        Select::make('week_starts_on')
                            ->label('Week starts on')
                            ->helperText(__('The first day of the club\'s week, for the "this week" figures on Analytics and when the Cleaning Checklist resets. Blank follows the language setting (Monday in English). Changing it mid-week re-opens this week\'s checklist tasks, since their ticks belong to the old week.'))
                            ->placeholder(__('Follow the language setting'))
                            ->options([
                                0 => __('Sunday'),
                                1 => __('Monday'),
                                2 => __('Tuesday'),
                                3 => __('Wednesday'),
                                4 => __('Thursday'),
                                5 => __('Friday'),
                                6 => __('Saturday'),
                            ]),
                    ]),
                Section::make(__('Admission & membership'))
                    ->components([
                        TextInput::make('age_of_majority')
                            ->label('Age of majority')
                            ->helperText(__('AdmissionPolicy blocks a member below this age outright. Jurisdiction-specific — adjust if your club isn\'t in an 18-is-adult jurisdiction.'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('alcohol_flag_age')
                            ->label('No-alcohol / mark-hand age')
                            ->helperText(__('A member below this age (but at or above "Age of majority") is admitted but flagged to mark their hand, no alcohol. Set equal to "Age of majority" to disable the flag entirely.'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
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
                            ->helperText(__('Never affects admission or pricing. A member on probation can\'t bring a guest, unless "Allow guests during probation" (under Guests) allows it.'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Toggle::make('member_email_required')
                            ->label('Require email at sign-up')
                            ->helperText(__('Whether the Check-In Desk requires an email address when a Prospective finishes sign-up or a guest is registered. Turn off for a club that doesn\'t collect email at the door. Name is always required.'))
                            ->required(),
                    ]),
                Section::make(__('Guests'))
                    ->components([
                        Toggle::make('guests_allowed_during_probation')
                            ->label('Allow guests during probation')
                            ->helperText(__('Lets a member still on probation register a guest at the Check-In Desk. Off by default. Turning guests off entirely is on Feature Flags.'))
                            ->required(),
                        TextInput::make('max_guests_per_night')
                            ->label('Max guests per member per night')
                            ->helperText(__('How many new guests one member may register at the Check-In Desk in a night, counted from when they checked in (so a late event doesn\'t reset it at midnight). Returning guests who check in under them don\'t count. Leave blank for no limit.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50),
                        Toggle::make('guest_followup_moves_category')
                            ->label('Move a guest on when their follow-up is sent')
                            ->helperText(__('When a guest is marked "follow-up sent" on the Members list, moves them out of Guest into the category below. Off, they stay a Guest and only the follow-up is recorded. On by default.'))
                            ->live()
                            ->required(),
                        Select::make('guest_followup_category_id')
                            ->label('Category after follow-up')
                            ->helperText(__('Where a guest goes once their follow-up is sent. Blank means Irregular. If the chosen category is later deleted, Irregular is used.'))
                            ->placeholder(__('Irregular'))
                            ->options(fn (): array => Category::whereNotIn('name', ['Prospective', 'Guest'])->orderBy('name')->pluck('name', 'id')->all())
                            ->visible(fn (Get $get): bool => (bool) $get('guest_followup_moves_category')),
                    ]),
                Section::make(__('Watchlist'))
                    ->components([
                        TextInput::make('watchlist_notify_label')
                            ->label('Watchlist: who staff notify')
                            ->helperText(fn (): string => __('Where staff post a heads-up before admitting a watchlisted member, e.g. "the Signal group". The desk shows "Notify …" and asks staff to confirm they did. Leave blank to use the server default (currently ":default").', ['default' => config('membership.watchlist_notify_label')]))
                            ->maxLength(60),
                        Select::make('watchlist_probation_mode')
                            ->label('Watchlist probation')
                            ->helperText(__('When an Owner takes a member off the watchlist, they can start a probation — staff see "Recently off watchlist" at the Check-In Desk and on Active Patrons. "Same as new-member probation" uses the probation period above and the same guest rule.'))
                            ->options([
                                'same' => __('Same as new-member probation'),
                                'custom' => __('Custom'),
                                'off' => __('Off'),
                            ])
                            ->live()
                            ->required(),
                        TextInput::make('watchlist_probation_days')
                            ->label('Watchlist probation (days)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->required(fn (Get $get): bool => $get('watchlist_probation_mode') === 'custom')
                            ->visible(fn (Get $get): bool => $get('watchlist_probation_mode') === 'custom'),
                        Toggle::make('watchlist_probation_blocks_guests')
                            ->label('Watchlist probation blocks guests')
                            ->helperText(__('A member on watchlist probation can\'t register a guest at the Check-In Desk. Off: the probation is informational only.'))
                            ->visible(fn (Get $get): bool => $get('watchlist_probation_mode') === 'custom'),
                    ]),
                Section::make(__('House Policy'))
                    ->description(__('What the Check-In Desk\'s status bar says in each situation, in your own words. Leave a line blank to use the wording shown in it. :age fills in that situation\'s age from above and :label the watchlist\'s "who staff notify". Everyone at the desk sees this text, so don\'t put names or reasons in it. Wording only: who gets in doesn\'t change. Admin and Owner can change it.'))
                    ->collapsible()
                    ->components(static::housePolicyFieldsets()),
                Section::make(__('Events & check-in'))
                    ->components([
                        TextInput::make('venue_capacity')
                            ->label('Venue capacity')
                            ->helperText(__('Hard cap on how many people can be in the building at once. Leave blank to not enforce a capacity limit.'))
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('event_window_buffer_minutes')
                            ->label('Event window buffer (minutes)')
                            ->helperText(__('Minutes of slack on either side of an event\'s start/end time when deciding whether it\'s "current" for the check-in page\'s event picker — lets staff pull an event up a little early and keep working it a little after it ends.'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('checkin_close_minutes_overnight')
                            ->label('Check-in closes before the end — overnight events (minutes)')
                            ->helperText(__('Default check-in close time for an event that runs past midnight: this many minutes before it ends. Each event can override it. Changing this doesn\'t move events already saved.'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->required(),
                        TextInput::make('checkin_close_minutes_same_day')
                            ->label('Check-in closes before the end — same-day events (minutes)')
                            ->helperText(__('Default check-in close time for an event that ends the day it starts: this many minutes before it ends. Each event can override it. Changing this doesn\'t move events already saved.'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->required(),
                        Toggle::make('first_visit_after_close_enabled')
                            ->label('Let a first visit check in after close')
                            ->helperText(__('Lets the Check-In Desk still check in someone on their first visit (e.g. a new member finishing paperwork) after an event\'s check-in has closed. Off, check-in closes for everyone. The kiosk is always closed after close. On by default.'))
                            ->required(),
                    ]),
                Section::make(__('Check-In Desk'))
                    ->components([
                        CheckboxList::make('member_search_fields')
                            ->label('Searchable member fields')
                            ->helperText(__('Which fields staff can search on in every member picker — the check-in desk, the Showrunner/Host selects, subscriptions, vouchers, and the rest. Username is the desk\'s primary lookup; add others only if staff actually need them. Each result shows the first of these fields the member has, plus, at the desk, whichever other field matched what was typed. Email only ever shows to Manager and up.'))
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
                        TextInput::make('default_opening_float')
                            ->label('Default cash drawer opening float')
                            ->helperText(__('Pre-fills the "opening count" field when a Door+ user opens a new cashbox shift. Leave blank to not pre-fill anything.'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01),
                    ]),
                Section::make(__('Members list & Active Patrons'))
                    ->components([
                        Toggle::make('hide_member_pii_by_default')
                            ->label('Hide personal info by default on the Members list')
                            ->helperText(__('Controls the starting state of the "Hide personal info" toggle on the Members list (first/last name, DOB, email). Staff can still flip it for their own session either way.'))
                            ->required(),
                        Toggle::make('active_patrons_show_staff_roles')
                            ->label('Show staff roles on Active Patrons')
                            ->helperText(__('Active Patrons notes which staff are signed in ("Also in the building"). Turn this off to list their names only, without each person\'s role.'))
                            ->required(),
                    ]),
                Section::make(__('Kiosk'))
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        TextInput::make('kiosk_show_code_seconds')
                            ->label('Kiosk "Show on kiosk" time (seconds)')
                            ->helperText(__('How long the kiosk tablet shows a member\'s code after staff send it with "Show on kiosk", long enough for them to take a picture. Anyone at the tablet can see it while it\'s up. Only matters while "Show codes on the kiosk" is on (Feature Flags).'))
                            ->numeric()
                            ->integer()
                            ->minValue(10)
                            ->maxValue(300)
                            ->required(),
                        TextInput::make('kiosk_result_seconds')
                            ->label('Kiosk "checked in" message time (seconds)')
                            ->helperText(__('How long the kiosk shows "checked in" or "already checked in" after a scan before it is ready for the next person.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(30)
                            ->required(),
                        TextInput::make('kiosk_problem_result_seconds')
                            ->label('Kiosk problem message time (seconds)')
                            ->helperText(__('How long the kiosk shows any other result, such as "see staff" or "code not recognized", so there is time to read it.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->required(),
                        TextInput::make('kiosk_same_code_cooldown_seconds')
                            ->label('Kiosk ignores a repeated code for (seconds)')
                            ->helperText(__('After a scan, the kiosk ignores the same code for this long, so a code still held up to the camera isn\'t scanned twice. A different code scans right away.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->required(),
                        TextInput::make('kiosk_requests_per_minute')
                            ->label('Kiosk scans allowed per minute')
                            ->helperText(__('The most scans the kiosk tablet may send in a minute; past it, the tablet asks the member to wait a moment. It stops anyone guessing codes. Raise it only if a busy door hits it. At least 30.'))
                            ->numeric()
                            ->integer()
                            ->minValue(30)
                            ->maxValue(600)
                            ->required(),
                        TextInput::make('kiosk_code_email_batch_size')
                            ->label('Kiosk codes emailed per click')
                            ->helperText(__('How many subscribers one click of "Email kiosk codes" (Subscriptions) sends to; click again for the next batch. Each email waits on the mail server, so a big batch can time out the page — lower it if that happens.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(200)
                            ->required(),
                    ]),
                Section::make(__('Updates & deploy'))
                    ->collapsible()
                    ->collapsed()
                    ->components([
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
                    ]),
            ]);
    }

    /**
     * One headline + instructions pair per House Policy situation, each
     * placeholder showing the wording the desk uses when it's left blank.
     *
     * @return list<Fieldset>
     */
    protected static function housePolicyFieldsets(): array
    {
        $shipped = [
            'banned' => [__('Banned'), __('Do not admit'), __('e.g. Take no money. Get a Manager.')],
            'watchlist' => [__('On the watchlist'), __('Acknowledge before admitting'), __('Notify :label')],
            'under_majority' => [__('Under the age of majority'), __('Do not admit'), __('e.g. Under :age: we can\'t admit them.')],
            'under_alcohol_age' => [__('Under the no-alcohol age'), __('Under :age — mark hand, no alcohol'), __('e.g. Mark their hand before you check them in.')],
        ];

        return collect($shipped)
            ->map(fn (array $text, string $situation): Fieldset => Fieldset::make($text[0])
                ->columns(1)
                ->components([
                    TextInput::make("house_policy_{$situation}_headline")
                        ->label(__('Headline'))
                        ->placeholder($text[1])
                        ->maxLength(120)
                        ->disabled(fn (): bool => ! Gate::allows('manage-house-policy')),
                    TextInput::make("house_policy_{$situation}_instructions")
                        ->label(__('What to do'))
                        ->placeholder($text[2])
                        ->maxLength(255)
                        ->disabled(fn (): bool => ! Gate::allows('manage-house-policy')),
                ]))
            ->values()
            ->all();
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
                    unset($state['org_name'], $state['org_logo_path']);
                }
                // Read-only for a Manager; same server-side re-check.
                if (! Gate::allows('manage-house-policy')) {
                    foreach (MembershipSetting::HOUSE_POLICY_SITUATIONS as $situation) {
                        unset($state["house_policy_{$situation}_headline"], $state["house_policy_{$situation}_instructions"]);
                    }
                }

                $settings = MembershipSetting::current();
                $replacedLogo = array_key_exists('org_logo_path', $state) && $state['org_logo_path'] !== $settings->org_logo_path
                    ? $settings->org_logo_path
                    : null;

                $settings->update($state);

                if (filled($replacedLogo)) {
                    Storage::disk('public')->delete($replacedLogo);
                }
                $this->rememberData();

                Notification::make()->title(__('Settings saved'))->success()->send();
            });
    }
}
