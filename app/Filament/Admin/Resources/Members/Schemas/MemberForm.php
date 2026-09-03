<?php

namespace App\Filament\Admin\Resources\Members\Schemas;

use App\Models\Member;
use App\Models\MembershipSetting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->components([
                        TextInput::make('member_number')
                            ->numeric()
                            ->default(null)
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Assigned automatically')
                            ->helperText('System-assigned on create. Only fixable via a direct database edit, e.g. for a legacy-import correction.'),
                        TextInput::make('username')
                            ->required()
                            // Free to set on create, but locked once a member
                            // exists -- renames go through the audited
                            // "Rename username" header action instead, which
                            // checks for a duplicate and logs who/when/from/to
                            // (members change their chosen name periodically).
                            ->disabled(fn (?Member $record): bool => (bool) $record)
                            ->dehydrated(fn (?Member $record): bool => ! $record)
                            ->helperText(fn (?Member $record): ?string => $record
                                ? 'Use the "Rename username" button above to change this.'
                                : null),
                        TextInput::make('preferred_name')
                            ->default(null),
                        TextInput::make('first_name')
                            ->default(null),
                        TextInput::make('last_name')
                            ->default(null),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->default(null),
                        Toggle::make('email_opt_in')
                            ->label('OK to email (bulk list)')
                            ->helperText('Included in the Members list\'s bulk email export when on and an email address is set.'),
                    ]),
                Section::make('Membership')
                    ->columns(2)
                    ->components([
                        Select::make('category_id')
                            ->relationship('category', 'name')
                            ->required(),
                        Select::make('sponsor_id')
                            ->label('Sponsor (for a Guest)')
                            ->relationship('sponsor', 'username')
                            ->searchable()
                            ->helperText('The member responsible for this guest, if this record is a Guest.'),
                        DatePicker::make('date_vetted'),
                        DatePicker::make('dob'),
                        DatePicker::make('paperwork_date'),
                        Toggle::make('is_active')
                            ->required(),
                        Toggle::make('subscription_eligible')
                            ->label('Subscription eligible (manual override)')
                            ->required(),
                    ]),
                Section::make('Status flags')
                    ->columns(2)
                    ->components([
                        Toggle::make('on_watchlist')
                            ->live()
                            ->required(),
                        TextInput::make('watchlist_reason')
                            ->required(fn (Get $get): bool => (bool) $get('on_watchlist'))
                            ->default(null),
                        Toggle::make('is_banned')
                            ->live()
                            ->required(),
                        TextInput::make('ban_reason')
                            ->required(fn (Get $get): bool => (bool) $get('is_banned'))
                            ->default(null),
                        DatePicker::make('banned_until')
                            ->label('Suspended until')
                            ->helperText('Leave blank for a permanent ban. Set a date to make this a suspension that lifts on its own — no one has to remember to manually un-ban.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_banned') && MembershipSetting::current()->suspensions_enabled),
                        DatePicker::make('probation_override_start')
                            ->label('Probation start override')
                            ->helperText('Leave blank to base probation on Date Vetted above. On probation for '.MembershipSetting::current()->probation_period_days.' days from whichever date applies — reporting-only, never affects admission.'),
                        Toggle::make('missing_paperwork')
                            ->required(),
                        Toggle::make('is_deceased')
                            ->required(),
                        TextInput::make('hospitality_note')
                            ->default(null),
                    ]),
                Section::make('Notes')
                    ->components([
                        Textarea::make('notes')
                            ->default(null)
                            ->columnSpanFull(),
                    ]),
                Section::make('Skills')
                    ->visible(fn (): bool => Gate::allows('assign-member-skills'))
                    ->components([
                        Select::make('skills')
                            ->label('')
                            ->relationship('skills', 'name')
                            ->multiple()
                            ->preload()
                            // The Section's ->visible() above only controls
                            // rendering -- Filament's saveRelationships()
                            // checks isHidden() on this component itself,
                            // not an ancestor's, so the gate must be
                            // repeated here to actually stop the sync for
                            // anyone below Admin (matches "never trust
                            // visibility alone" elsewhere in this app).
                            ->visible(fn (): bool => Gate::allows('assign-member-skills'))
                            ->helperText('Admin+ only.'),
                    ]),
            ]);
    }
}
