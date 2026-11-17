<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\Member;
use App\Services\Analytics\RetentionAnalytics;
use App\Support\CsvDownload;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Regulars who have stopped coming (RetentionAnalytics::lapsed()): enough
 * visits to count as a regular, and none lately. Both numbers are the
 * viewer's to change. As of today, so it doesn't follow the Analytics
 * page's period; banned, deceased and inactive members are never listed.
 */
class LapsedMembersWidget extends TableWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(RetentionAnalytics::class)->lapsedCandidates())
            ->heading(__('Regulars who have stopped coming'))
            ->description(__('As of today. Change what counts as a regular, or how long is too long, under Filters.'))
            ->defaultSort('last_visit_at', 'desc')
            ->recordUrl(fn (Member $record): string => MemberResource::getUrl('edit', ['record' => $record]))
            ->headerActions([$this->exportAction()])
            ->columns([
                TextColumn::make('username')
                    ->label('Member')
                    ->state(fn (Member $record): string => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns()),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('visits')
                    ->label('Visits')
                    ->state(fn (Member $record): int => $this->visitsOf($record))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_visit_at')
                    ->label('Last visit')
                    ->date()
                    ->sortable(),
                TextColumn::make('days_since')
                    ->label('Days since')
                    ->state(fn (Member $record): int => $this->daysSince($record))
                    ->numeric(),
            ])
            ->filters([
                // Always applied: with the fields left blank it uses the defaults.
                Filter::make('lapsed')
                    ->schema([
                        TextInput::make('min_visits')
                            ->label('At least this many visits')
                            ->numeric()
                            ->minValue(1)
                            ->default(RetentionAnalytics::LAPSED_MIN_VISITS),
                        TextInput::make('days')
                            ->label('No visit in this many days')
                            ->numeric()
                            ->minValue(1)
                            ->default(RetentionAnalytics::LAPSED_DAYS),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => app(RetentionAnalytics::class)->lapsed(
                        $query,
                        $this->positive($data['min_visits'] ?? null, RetentionAnalytics::LAPSED_MIN_VISITS),
                        $this->positive($data['days'] ?? null, RetentionAnalytics::LAPSED_DAYS),
                    ))
                    ->indicateUsing(fn (array $data): array => [__('At least :visits visits, none in :days days', [
                        'visits' => $this->positive($data['min_visits'] ?? null, RetentionAnalytics::LAPSED_MIN_VISITS),
                        'days' => $this->positive($data['days'] ?? null, RetentionAnalytics::LAPSED_DAYS),
                    ])]),
            ]);
    }

    /**
     * The list as filtered, searched and sorted, every page of it. No email
     * or other contact details: the Members list has its own opt-in export.
     */
    protected function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (): StreamedResponse => CsvDownload::stream(
                'lapsed-members-'.now()->toDateString().'.csv',
                ['Member Number', 'Username', 'Preferred Name', 'Category', 'Visits', 'Last Visit', 'Days Since'],
                $this->getTableQueryForExport()->get()->map(fn (Member $member): array => [
                    $member->member_number,
                    $member->username,
                    $member->preferred_name,
                    $member->category?->name,
                    $this->visitsOf($member),
                    CarbonImmutable::parse($member->last_visit_at)->toDateString(),
                    $this->daysSince($member),
                ]),
            ));
    }

    /**
     * Arrivals on record plus visits from before these records began.
     */
    private function visitsOf(Member $member): int
    {
        return (int) $member->visits + (int) $member->prior_visits;
    }

    private function daysSince(Member $member): int
    {
        return (int) CarbonImmutable::parse($member->last_visit_at)->startOfDay()->diffInDays(today());
    }

    private function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $default;
    }
}
