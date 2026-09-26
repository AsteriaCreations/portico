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
use Filament\Forms\Components\DatePicker;
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
                TextColumn::make('guest_followup_sent_at')
                    ->label('Guest follow-up sent')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->tooltip(fn (Member $record): ?string => $record->guestFollowupSentBy ? __('Marked by :name', ['name' => $record->guestFollowupSentBy->name]) : null)
                    ->sortable()
                    ->toggleable(),
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
                // For the guest follow-up round: filter to guests not yet sent
                // it (optionally with "Registered" below for a date range),
                // export the list, send, then "Mark follow-up sent".
                SelectFilter::make('guest_followup')
                    ->label('Guest follow-up')
                    ->options([
                        'pending' => __('Guests not yet sent follow-up'),
                        'sent' => __('Guests already sent follow-up'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pending' => $query->whereHas('category', fn (Builder $q) => $q->where('name', 'Guest'))->whereNull('guest_followup_sent_at'),
                        'sent' => $query->whereHas('category', fn (Builder $q) => $q->where('name', 'Guest'))->whereNotNull('guest_followup_sent_at'),
                        default => $query,
                    }),
                Filter::make('registered')
                    ->schema([
                        DatePicker::make('registered_from')->label('Registered from'),
                        DatePicker::make('registered_until')->label('Registered until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['registered_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['registered_until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['registered_from'] ?? null) {
                            $indicators[] = __('Registered from :date', ['date' => Carbon::parse($data['registered_from'])->translatedFormat('M j, Y')]);
                        }
                        if ($data['registered_until'] ?? null) {
                            $indicators[] = __('Registered until :date', ['date' => Carbon::parse($data['registered_until'])->translatedFormat('M j, Y')]);
                        }

                        return $indicators;
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
                    BulkAction::make('markGuestFollowupSent')
                        ->label('Mark follow-up sent')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->modalDescription(__('Records that each selected guest has been sent the club\'s follow-up, and that you marked it. Selected members who aren\'t guests are skipped.'))
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $marked = $records->filter(fn (Member $record): bool => $record->markGuestFollowupSent(auth()->user()))->count();
                            $skipped = $records->count() - $marked;

                            Notification::make()
                                ->title(trans_choice('Follow-up marked sent for :count guest|Follow-up marked sent for :count guests', $marked))
                                ->body($skipped > 0 ? trans_choice(':count selected member isn\'t a guest and was skipped.|:count selected members aren\'t guests and were skipped.', $skipped) : null)
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('clearGuestFollowup')
                        ->label('Mark follow-up not sent')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->modalDescription(__('Clears the follow-up mark on each selected member, e.g. after marking the wrong guest.'))
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(fn (Member $record) => $record->clearGuestFollowup());

                            Notification::make()
                                ->title(trans_choice('Follow-up cleared for :count member|Follow-up cleared for :count members', $records->count()))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
