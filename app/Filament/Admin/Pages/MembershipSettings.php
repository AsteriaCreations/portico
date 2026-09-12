<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\MembershipSetting;
use BackedEnum;
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
            'probation_period_days',
            'venue_capacity',
            'default_opening_float',
            'event_window_buffer_minutes',
            'org_name',
            'member_search_fields',
            'checkin_display_name_field',
            'hide_member_pii_by_default',
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
                    ->helperText('A member becomes eligible for either subscription plan once they\'ve attended this many events, all-time.')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('probation_period_days')
                    ->label('Probation period (days)')
                    ->helperText('Reporting-only — never affects admission or pricing.')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('venue_capacity')
                    ->label('Venue capacity')
                    ->helperText('Hard cap on how many people can be in the building at once. Leave blank to not enforce a capacity limit.')
                    ->numeric()
                    ->minValue(1),
                TextInput::make('default_opening_float')
                    ->label('Default cash drawer opening float')
                    ->helperText('Pre-fills the "opening count" field when a Door+ user opens a new cashbox shift. Leave blank to not pre-fill anything.')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),
                TextInput::make('event_window_buffer_minutes')
                    ->label('Event window buffer (minutes)')
                    ->helperText('Minutes of slack on either side of an event\'s start/end time when deciding whether it\'s "current" for the check-in page\'s event picker — lets staff pull an event up a little early and keep working it a little after it ends.')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('org_name')
                    ->label('Displayed organization name')
                    ->helperText('Shown across the admin panel (header, browser tab, login page) in place of "'.config('app.name').'". Leave blank to use that default. Owner only.')
                    ->maxLength(255)
                    ->visible(fn (): bool => Gate::allows('manage-org-name')),
                CheckboxList::make('member_search_fields')
                    ->label('Searchable member fields')
                    ->helperText('Which fields staff can search on in every member picker — the check-in desk, the Showrunner/Host selects, subscriptions, vouchers, and the rest. Username is the desk\'s primary lookup; add others only if staff actually need them.')
                    ->options([
                        'username' => 'Username',
                        'name' => 'Name (first & last)',
                        'member_number' => 'Member number',
                        'preferred_name' => 'Preferred name',
                        'email' => 'Email',
                    ])
                    ->minItems(1)
                    ->required(),
                Select::make('checkin_display_name_field')
                    ->label('Check-in desk display name')
                    ->helperText('Which field the Check-In Desk shows once a member is selected -- the greeting line, the checked-in roster, voucher labels, and the guest-registration sponsor note. Falls back to username if the chosen field is blank for a given member.')
                    ->options([
                        'preferred_name' => 'Preferred name',
                        'full_name' => 'Full name',
                        'username' => 'Username',
                    ])
                    ->required(),
                Toggle::make('hide_member_pii_by_default')
                    ->label('Hide personal info by default on the Members list')
                    ->helperText('Controls the starting state of the "Hide personal info" toggle on the Members list (first/last name, DOB, email). Staff can still flip it for their own session either way.')
                    ->required(),
                Toggle::make('showrunner_door_includes_pool')
                    ->label('Showrunner commission includes pool revenue')
                    ->helperText('Entry revenue always counts toward the showrunner\'s commission base ("the door"). Turn this on to also count pool fee revenue from the same qualifying attendees.')
                    ->required(),
                Toggle::make('showrunner_door_includes_addons')
                    ->label('Showrunner commission includes add-on revenue')
                    ->helperText('Turn this on to also count Event Add-On revenue (private room rental, sleepover, etc.) from the same qualifying attendees toward the showrunner\'s commission base.')
                    ->required(),
                TextInput::make('upstream_remote')
                    ->label('Upstream git remote name')
                    // Keep this pattern identical to
                    // UpstreamUpdateChecker::SAFE_REF_PATTERN.
                    ->rule('regex:/^[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9])?$/')
                    ->helperText('The name of a git remote already added on this server (`git remote add <name> <url>`) that this fork tracks for updates -- this app never adds one itself. Leave blank if this fork doesn\'t track an upstream. Turn on "Upstream update checking enabled" on Feature Flags once set.')
                    ->maxLength(255),
                TextInput::make('upstream_branch')
                    ->label('Upstream branch')
                    ->rule('regex:/^[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9])?$/')
                    ->helperText('The branch on the upstream remote to compare against -- usually "main".')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('deploy_task_name')
                    ->label('Deploy Scheduled Task name')
                    // Keep this pattern identical to
                    // App\Services\DeployTrigger::assertSafeTaskName() -- unlike
                    // upstream_remote/upstream_branch above, a task name may
                    // contain spaces, so this only blocks a leading '-'/'/'.
                    ->rule('regex:/^[^\s\/-][^\r\n]*$/')
                    ->helperText('The exact name of a Windows Scheduled Task, already registered on this server to run scripts/deploy.ps1, that "Run update now" on Upstream Updates should fire -- this app never registers one itself. Leave blank if you don\'t want a web-triggered deploy. Turn on "Web-triggered deploy enabled" on Feature Flags once set.')
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

                Notification::make()->title('Settings saved')->success()->send();
            });
    }
}
