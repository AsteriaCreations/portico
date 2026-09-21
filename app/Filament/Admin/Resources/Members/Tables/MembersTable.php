<?php

namespace App\Filament\Admin\Resources\Members\Tables;

use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MembersTable
{
    private const string PII_MASK = '••••••';

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('member_number')
                    ->numeric()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('first_name')
                    ->formatStateUsing(fn (?string $state, $livewire): ?string => $livewire->piiHidden ? self::PII_MASK : $state)
                    ->searchable(),
                TextColumn::make('last_name')
                    ->formatStateUsing(fn (?string $state, $livewire): ?string => $livewire->piiHidden ? self::PII_MASK : $state)
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('dob')
                    ->label('DOB')
                    ->formatStateUsing(fn (?Carbon $state, $livewire): string => $livewire->piiHidden ? self::PII_MASK : ($state?->translatedFormat('M j, Y') ?? '—'))
                    ->sortable(),
                TextColumn::make('email')
                    ->formatStateUsing(fn (?string $state, $livewire): string => $livewire->piiHidden ? self::PII_MASK : ($state ?? '—'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                IconColumn::make('email_opt_in')
                    ->label('Email OK')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('OK to email (bulk list)') : __('Not opted in to bulk email')),
                IconColumn::make('on_watchlist')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('On watchlist') : __('Not on watchlist')),
                IconColumn::make('is_banned')
                    ->label('Banned')
                    ->boolean()
                    // Computed, not the raw column -- an expired suspension
                    // shouldn't still read as "banned" here, same reason
                    // the probation column below is computed rather than
                    // stored. See Member::isCurrentlyBanned().
                    ->state(fn (Member $record) => $record->isCurrentlyBanned())
                    ->tooltip(fn (bool $state): string => $state ? __('Banned') : __('Not banned')),
                IconColumn::make('probation')
                    ->label('On Probation')
                    ->boolean()
                    ->state(fn (Member $record) => $record->isOnProbation())
                    ->tooltip(fn (bool $state): string => $state ? __('On probation') : __('Not on probation')),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name'),
                TernaryFilter::make('on_watchlist')
                    ->label('Watchlist'),
                TernaryFilter::make('is_banned')
                    ->label('Banned')
                    // Redefines true/false as "currently banned" (accounts
                    // for an expired suspension), not the raw column -- same
                    // portable date-cutoff style as the on_probation filter
                    // below. See Member::isCurrentlyBanned().
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_banned', true)
                            ->where(fn (Builder $q) => $q->whereNull('banned_until')->orWhereDate('banned_until', '>', today())),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                            ->where('is_banned', false)
                            ->orWhere(fn (Builder $q2) => $q2->whereNotNull('banned_until')->whereDate('banned_until', '<=', today()))),
                    ),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('is_deceased')
                    ->label('Deceased'),
                TernaryFilter::make('missing_paperwork')
                    ->label('Missing paperwork'),
                TernaryFilter::make('subscription_eligible')
                    ->label('Subscription eligible'),
                // Mirrors Member::isOnProbation()'s own logic (now() < start +
                // probation_period_days) as a cutoff comparison, rather than a
                // DB-specific date-math function, so it stays portable across
                // the SQLite test suite and MySQL/MariaDB in production.
                Filter::make('on_probation')
                    ->label('On probation')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $cutoff = now()->subDays(MembershipSetting::current()->probation_period_days);

                        return $query->where(
                            fn (Builder $q) => $q
                                ->where(fn (Builder $q) => $q->whereNotNull('probation_override_start')->where('probation_override_start', '>', $cutoff))
                                ->orWhere(fn (Builder $q) => $q->whereNull('probation_override_start')->whereNotNull('date_vetted')->where('date_vetted', '>', $cutoff))
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('changeCategory')
                        ->label('Change category')
                        ->schema([
                            Select::make('category_id')
                                ->label('New category')
                                ->options(fn () => Category::query()->where('active', true)->orderBy('sort_order')->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (Member $record) => $record->update(['category_id' => $data['category_id']]));

                            Notification::make()
                                ->title(trans_choice('Category updated for :count member|Category updated for :count members', $records->count()))
                                ->success()
                                ->send();
                        }),
                    // Flags a member as needing paperwork again (e.g. the
                    // club rolled out a new waiver text and wants everyone
                    // re-confirmed), regardless of what member_paperwork
                    // already has on file for them -- Standard Paperwork has
                    // no renewal_months/version concept of its own, so this
                    // is the mass equivalent of toggling missing_paperwork
                    // on the plain edit form one member at a time. Routes
                    // through the same per-record ->update() (not a mass
                    // query-builder update) so MemberObserver still logs
                    // each change to member_status_changes.
                    BulkAction::make('requirePaperwork')
                        ->label('Require paperwork')
                        ->icon('heroicon-o-document-text')
                        ->requiresConfirmation()
                        ->modalDescription(__('Flags each selected member as missing paperwork. They\'ll be prompted to reconfirm it (e.g. sign the new waiver) the next time they\'re selected at the Check-In Desk.'))
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(fn (Member $record) => $record->update(['missing_paperwork' => true]));

                            Notification::make()
                                ->title(trans_choice('Paperwork required for :count member|Paperwork required for :count members', $records->count()))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
