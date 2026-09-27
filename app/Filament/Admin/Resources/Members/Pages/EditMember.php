<?php

namespace App\Filament\Admin\Resources\Members\Pages;

use App\Enums\WatchlistReviewDecision;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\MembershipSetting;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->reviewWatchlistAction(),
            $this->renameUsernameAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * The Owner's decision on a watchlist entry -- available whether or not
     * the review date has arrived, so an Owner can decide early. The logic
     * lives in Member::resolveWatchlistReview(); this only collects the
     * choice and re-checks the gate (never trust ->visible() alone).
     */
    protected function reviewWatchlistAction(): Action
    {
        return Action::make('reviewWatchlist')
            ->label('Review watchlist')
            ->icon('heroicon-o-scale')
            ->color(fn (): string => $this->getRecord()->isWatchlistReviewDue() ? 'danger' : 'gray')
            ->visible(fn (): bool => $this->getRecord()->on_watchlist && Gate::allows('resolve-watchlist'))
            ->modalDescription(fn (): string => $this->getRecord()->watchlist_review_on
                ? __('Review date: :date. Reason on file: :reason', ['date' => $this->getRecord()->watchlist_review_on->translatedFormat('M j, Y'), 'reason' => $this->getRecord()->watchlist_reason ?? '—'])
                : __('No review date set. Reason on file: :reason', ['reason' => $this->getRecord()->watchlist_reason ?? '—']))
            ->schema([
                Radio::make('decision')
                    ->label('Decision')
                    ->options(WatchlistReviewDecision::class)
                    ->default(WatchlistReviewDecision::Removed->value)
                    ->live()
                    ->required(),
                Checkbox::make('start_probation')
                    ->label(fn (): string => __('Start :days days of watchlist probation', ['days' => MembershipSetting::watchlistProbationDays()]))
                    ->default(true)
                    ->visible(fn (Get $get): bool => self::decisionFrom($get('decision')) === WatchlistReviewDecision::Removed
                        && MembershipSetting::watchlistProbationDays() !== null),
                DatePicker::make('new_review_on')
                    ->label('New review date')
                    ->minDate(today()->addDay())
                    ->required()
                    ->visible(fn (Get $get): bool => self::decisionFrom($get('decision')) === WatchlistReviewDecision::Extended),
                TextInput::make('notes')
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                abort_unless(Gate::allows('resolve-watchlist'), 403);

                $decision = self::decisionFrom($data['decision']);

                $this->getRecord()->resolveWatchlistReview(
                    auth()->user(),
                    $decision,
                    filled($data['new_review_on'] ?? null) ? Carbon::parse($data['new_review_on']) : null,
                    (bool) ($data['start_probation'] ?? false),
                    $data['notes'] ?? null,
                );

                $this->refreshFormData(['on_watchlist', 'watchlist_review_on']);

                Notification::make()->title($decision->getLabel())->success()->send();
            });
    }

    /**
     * The Radio's state is the enum once Filament has cast it, but the raw
     * backing value before then (e.g. the first render's default).
     */
    private static function decisionFrom(WatchlistReviewDecision|string|null $state): ?WatchlistReviewDecision
    {
        return $state instanceof WatchlistReviewDecision ? $state : WatchlistReviewDecision::tryFrom((string) $state);
    }

    // username is locked on the form itself (see MemberForm) -- this is the
    // only rename path, so it's the one place a duplicate gets checked and
    // the change gets logged (via MemberObserver -> member_username_changes).
    // No separate gate: this is a header action on the Member edit page,
    // already Manager+ only via MemberPolicy.
    protected function renameUsernameAction(): Action
    {
        return Action::make('renameUsername')
            ->label('Rename username')
            ->schema([
                TextInput::make('username')
                    ->label('New username')
                    ->required()
                    ->maxLength(60)
                    ->default(fn (): string => $this->getRecord()->username)
                    ->unique(table: 'members', column: 'username', ignoreRecord: true),
            ])
            ->action(function (array $data): void {
                // The unique() rule above already checked at validation
                // time -- this only catches the narrow race between that
                // check and this write (same pattern as
                // CheckIn::registerGuestAction()).
                try {
                    $this->getRecord()->update(['username' => $data['username']]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title(__('That username was just taken — please choose another.'))->danger()->send();

                    return;
                }

                // The main form's own username field is disabled/dehydrated
                // and only ever filled at mount -- without this, the edit
                // page would keep showing the old value until a full reload.
                $this->refreshFormData(['username']);

                Notification::make()->title(__('Username updated'))->success()->send();
            });
    }
}
