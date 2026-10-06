<?php

namespace App\Filament\Admin\Resources\Members\Pages;

use App\Enums\WatchlistReviewDecision;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Concerns\RenamesMemberUsername;
use App\Filament\Concerns\ShowsKioskQrCode;
use App\Models\Member;
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
use Illuminate\Support\Facades\Gate;

class EditMember extends EditRecord
{
    use RenamesMemberUsername;
    use ShowsKioskQrCode;

    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->reviewWatchlistAction(),
            $this->renameUsernameAction(),
            $this->makeKioskQrCodeAction(resolveMember: fn (): Member => $this->getRecord()),
            $this->makeEmailKioskQrCodeAction(resolveMember: fn (): Member => $this->getRecord()),
            $this->replaceKioskCodeAction(),
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
                ? __('Review date: :date. Reason on file: :reason', ['date' => $this->getRecord()->watchlist_review_on->isoFormat('ll'), 'reason' => $this->getRecord()->watchlist_reason ?? '—'])
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

    // Manager+ always passes rename-member-username, and this page is
    // already Manager+ via MemberPolicy -- see RenamesMemberUsername.
    protected function renameUsernameAction(): Action
    {
        return $this->makeRenameUsernameAction(
            resolveMember: fn (): Member => $this->getRecord(),
            // The main form's own username field is disabled/dehydrated
            // and only ever filled at mount -- without this, the edit
            // page would keep showing the old value until a full reload.
            afterSave: fn () => $this->refreshFormData(['username']),
        );
    }

    /**
     * For a lost card: issues a new kiosk code, so the old card stops
     * working. Manager+ (replace-kiosk-token), from this page only -- the
     * desk can show a code but never replace one.
     */
    protected function replaceKioskCodeAction(): Action
    {
        return Action::make('replaceKioskCode')
            ->label('Replace kiosk code')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->kiosk_token !== null && Gate::allows('replace-kiosk-token'))
            ->requiresConfirmation()
            ->modalDescription(__('Their current kiosk card or photo stops working straight away. Show or print the new code afterwards with "Kiosk QR code".'))
            ->action(function (): void {
                abort_unless(Gate::allows('replace-kiosk-token'), 403);

                $this->getRecord()->regenerateKioskToken();

                Notification::make()->title(__('Kiosk code replaced'))->success()->send();
            });
    }
}
