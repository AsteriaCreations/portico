<?php

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use App\Models\AddOn;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\ManagerPerkService;
use App\Services\SubscriptionBundleService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->grantManagerPerkAction(),
            $this->bulkPurchaseAction(),
        ];
    }

    /**
     * Records a multi-month subscription bundle purchased outside of check-in (e.g.
     * paid over the phone) — same App\Services\SubscriptionBundleService
     * mechanism CheckIn::checkInAction() uses, so the two never drift apart.
     */
    protected function bulkPurchaseAction(): Action
    {
        return Action::make('bulkPurchase')
            ->label('Bulk subscription purchase')
            ->visible(fn (): bool => Gate::allows('create', Subscription::class))
            ->schema([
                Select::make('member_id')
                    ->label('Member')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        ->where(fn ($query) => $query
                            ->where('username', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('member_number', 'like', "%{$search}%"))
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Member $member) => [$member->id => "{$member->last_name}, {$member->first_name} ({$member->username})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($member = Member::find($value))
                        ? "{$member->last_name}, {$member->first_name} ({$member->username})"
                        : null)
                    ->required()
                    ->rule(function () {
                        return function (string $attribute, $value, $fail) {
                            $member = $value ? Member::find($value) : null;
                            if ($member && ! $member->isSubscriptionEligible()) {
                                $fail('This member is not yet subscription-eligible.');
                            }
                        };
                    }),
                Select::make('add_on_id')
                    ->label('Plan')
                    // Entry (the Regular subscription) plus every currently
                    // subscribable add-on (Pool, at launch) -- same set
                    // CheckIn::purchaseSubscriptionAction() offers.
                    ->options(fn () => collect([AddOn::entry()])
                        ->merge(AddOn::subscribable()->orderBy('sort_order')->get()->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable()))
                        ->mapWithKeys(fn (AddOn $addOn) => [$addOn->id => $addOn->name])
                        ->all())
                    ->required()
                    ->live(),
                DatePicker::make('desired_start')
                    ->label('Desired start month')
                    ->helperText('If a month in the window is already covered, the whole bundle shifts forward to the next free block — the notification will say so.')
                    ->default(now()->startOfMonth())
                    ->required()
                    ->live()
                    ->dehydrateStateUsing(fn (?string $state) => $state ? Carbon::parse($state)->startOfMonth()->toDateString() : null),
                Select::make('duration_months')
                    ->label('Duration')
                    ->options(function (Get $get): array {
                        $addOn = $get('add_on_id') ? AddOn::find($get('add_on_id')) : null;
                        if (! $addOn) {
                            return [];
                        }

                        // As of now, not desired_start — SubscriptionBundleService::purchase()
                        // prices every duration (including 1) at today's rate,
                        // since this action records a payment happening now.
                        return Plan::currentOptionsFor($addOn, now())
                            ->mapWithKeys(fn (Plan $plan) => [$plan->duration_months => "{$plan->duration_months} month(s) — \$".number_format($plan->price, 2)])
                            ->all();
                    })
                    ->required(),
                Select::make('payment_method')
                    ->options(PaymentMethod::options()),
                DatePicker::make('paid_on')
                    ->default(now()),
            ])
            ->action(function (array $data): void {
                $member = Member::findOrFail($data['member_id']);
                $addOn = AddOn::findOrFail($data['add_on_id']);
                $months = (int) $data['duration_months'];
                $desiredStart = Carbon::parse($data['desired_start']);

                $rows = app(SubscriptionBundleService::class)->purchase(
                    $member,
                    $addOn,
                    $months,
                    $desiredStart,
                    Auth::user(),
                    $data['payment_method'] ?? null,
                    null,
                );

                $first = $rows->first();
                $last = $rows->last();
                $rangeLabel = $first->covered_month->isSameMonth($last->covered_month)
                    ? $first->covered_month->format('F Y')
                    : $first->covered_month->format('F Y').' – '.$last->covered_month->format('F Y');

                Notification::make()
                    ->title("Bundle recorded for {$member->username} — \$".number_format((float) $rows->sum('amount_paid'), 2)." covering {$rangeLabel}")
                    ->success()
                    ->send();
            });
    }

    protected function grantManagerPerkAction(): Action
    {
        return Action::make('grantManagerPerk')
            ->label('Grant monthly subscription perk')
            ->visible(fn (): bool => Gate::allows('grant-manager-subscription-perk')
                && app(ManagerPerkService::class)->isAvailable(Auth::user()))
            ->schema([
                Select::make('member_id')
                    ->label('Member')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        ->where(fn ($query) => $query
                            ->where('username', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('member_number', 'like', "%{$search}%"))
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Member $member) => [$member->id => "{$member->last_name}, {$member->first_name} ({$member->username})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($member = Member::find($value))
                        ? "{$member->last_name}, {$member->first_name} ({$member->username})"
                        : null)
                    ->required()
                    ->rule(function () {
                        return function (string $attribute, $value, $fail) {
                            $member = $value ? Member::find($value) : null;
                            if ($member && ! $member->isSubscriptionEligible()) {
                                $fail('This member is not yet subscription-eligible.');
                            }
                            if ($member && $member->hasActiveSubscriptionFor(AddOn::entry(), now()->startOfMonth())) {
                                $fail('This member already has regular subscription coverage this month.');
                            }
                        };
                    }),
                TextInput::make('notes')
                    ->label('Note (optional — a default is recorded either way)')
                    ->maxLength(255),
            ])
            ->action(function (array $data) {
                // Re-checked here, not just via ->visible() above -- same
                // defensive pattern as ActivePatrons::departAction() and
                // CompRequestsRelationManager::approveAction(). Without
                // this, neither this closure nor ManagerPerkService::grant()
                // itself verify the caller's role, so a forged Livewire call
                // from an Admin (who already has legitimate access to this
                // whole resource) could bypass the deliberate Manager/Owner-
                // only, non-monotonic exclusion.
                abort_unless(Gate::allows('grant-manager-subscription-perk'), 403);

                $manager = Auth::user();
                $service = app(ManagerPerkService::class);

                if (! $service->isAvailable($manager)) {
                    Notification::make()
                        ->title('You have already used your monthly subscription perk.')
                        ->danger()
                        ->send();

                    return;
                }

                $beneficiary = Member::findOrFail($data['member_id']);
                $service->grant($manager, $beneficiary, $data['notes'] ?? null);

                Notification::make()
                    ->title("Granted regular subscription to {$beneficiary->username} for ".now()->format('F Y'))
                    ->success()
                    ->send();
            });
    }
}
