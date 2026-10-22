<?php

namespace App\Filament\Concerns;

use App\Exceptions\DayPassRefused;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\AddOnDayPassService;
use App\Services\EntryCorrectionService;
use App\Services\SubscriptionBundleService;
use App\Support\Cents;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * The Check-In Desk's sales outside a check-in: a subscription on its own,
 * an add-on day pass, and converting tonight's paid entry into a
 * subscription. The rules live in SubscriptionBundleService,
 * AddOnDayPassService and EntryCorrectionService; these are the forms.
 * Pulled out of CheckIn; behaviour is unchanged.
 *
 * Relies on the host page for getSelectedMember(), getExistingAttendance(),
 * getOpenShift(), paymentMethodOptions(), haltForTraining(),
 * formatCurrency(), searchMembers() and memberLabel().
 */
trait SellsSubscriptionsAtDesk
{
    // A standalone subscription purchase against the member's account,
    // independent of checking them into any event tonight -- e.g. a member
    // tops up coverage and gets in free later, in two transactions rather
    // than one bundled check-in. Same App\Services\SubscriptionBundleService
    // mechanism as checkInAction() and ListSubscriptions::bulkPurchaseAction()
    // (Manager+, via the Subscriptions resource) -- deliberately ungated
    // here, not Gate::allows('create', Subscription::class), matching the
    // existing "subscription collection at check-in is every role including
    // Door" precedent;
    // this is that same capability, just no longer requiring an event to be
    // selected first. Once purchased, a later check-in that night picks up
    // the coverage automatically via Member::hasActiveSubscriptionFor() -- no
    // special-casing needed in pricingForm/getLivePriceBreakdown().
    public function purchaseSubscriptionAction(): Action
    {
        $member = $this->getSelectedMember();
        $openShift = $this->getOpenShift();

        return Action::make('purchaseSubscription')
            ->label(__('Buy Subscription (no check-in)'))
            ->modalDescription(__('This purchases coverage on its own — it does not check the member in or apply to any event tonight. To cover tonight\'s entry with a subscription instead, use the Regular/Pool Subscription options under Payment options below.'))
            ->visible(fn (): bool => (bool) $member?->isSubscriptionEligible())
            ->schema([
                Select::make('add_on_id')
                    ->label(__('Plan'))
                    // Entry (the Regular subscription) plus every currently
                    // subscribable add-on (Pool, at launch) -- rebuilt fresh
                    // on every open/submit, same reasoning as every other
                    // options() closure here.
                    ->options(fn () => collect([AddOn::entry()])
                        ->merge(AddOn::subscribable()->orderBy('sort_order')->get()->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable()))
                        ->mapWithKeys(fn (AddOn $addOn) => [$addOn->id => $addOn->name])
                        ->all())
                    ->required()
                    ->live(),
                DatePicker::make('desired_start')
                    ->label(__('Desired start month'))
                    ->helperText(__('If a month in the window is already covered, the whole bundle shifts forward to the next free block — the notification will say so.'))
                    ->default(now()->startOfMonth())
                    ->required()
                    ->live()
                    ->dehydrateStateUsing(fn (?string $state) => $state ? Carbon::parse($state)->startOfMonth()->toDateString() : null),
                Select::make('duration_months')
                    ->label(__('Duration'))
                    ->options(function (Get $get): array {
                        $addOn = $get('add_on_id') ? AddOn::find($get('add_on_id')) : null;
                        if (! $addOn) {
                            return [];
                        }

                        return Plan::currentOptionsFor($addOn, now())
                            ->mapWithKeys(fn (Plan $plan) => [$plan->duration_months => "{$plan->duration_months} month(s) — {$this->formatCurrency($plan->price)}"])
                            ->all();
                    })
                    ->required(),
                // Never one-time-restricted, even for a Venmo/PayPal/electronic
                // method — that gate only applies to entry (check-in) and day
                // passes; a member may pay for their membership by the same
                // method any number of times. See Member::hasUsedOneTimeMethod().
                Select::make('payment_method')
                    ->live()
                    ->options($this->paymentMethodOptions($openShift))
                    // Optional only when a voucher may cover it all.
                    ->required(fn (Get $get): bool => ! $get('apply_voucher'))
                    ->helperText(fn (Get $get): ?string => $this->paymentMethodHelperText($openShift, $get('payment_method'))),
                // Same voucher fields as Payment options at check-in, minus
                // the live default: the price isn't known until a plan and
                // duration are picked, and the amount is capped server-side.
                Checkbox::make('apply_voucher')
                    ->label(__("Apply voucher credit — :amount available on :name's account", ['amount' => $this->formatCurrency($member?->voucherBalance() ?? 0.0), 'name' => $member?->displayName()]))
                    ->live()
                    ->visible(fn (): bool => MembershipSetting::current()->vouchers_enabled),
                Select::make('voucher_payer_id')
                    ->label(__("Apply from a different member's balance (optional)"))
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $payer) => [$payer->id => static::memberLabel($payer, $search).' — '.$this->formatCurrency($payer->voucherBalance()).' available'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($payer = Member::find($value)) ? static::memberLabel($payer) : null)
                    ->helperText(__("Leave blank to use :name's own balance.", ['name' => $member?->displayName()]))
                    ->visible(fn (Get $get): bool => (bool) $get('apply_voucher')),
                TextInput::make('voucher_amount')
                    ->label(__('Voucher amount to apply'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(fn (Get $get): bool => (bool) $get('apply_voucher'))
                    ->helperText(__('Capped automatically at the balance available and what\'s still due — a partial amount is fine.'))
                    ->visible(fn (Get $get): bool => (bool) $get('apply_voucher')),
                // Only another member's balance needs a reason; a member
                // spending their own gets Voucher::redemptionReason()'s default.
                TextInput::make('voucher_reason')
                    ->label(__('Reason (required — kept on the ledger)'))
                    ->maxLength(255)
                    ->required($usesAnotherBalance = fn (Get $get): bool => (bool) $get('apply_voucher') && filled($get('voucher_payer_id')) && (int) $get('voucher_payer_id') !== $member?->id)
                    ->visible($usesAnotherBalance),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: subscription purchase simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $addOn = AddOn::find($data['add_on_id']);
                abort_unless($addOn, 404);

                // The service re-checks the add-on is purchasable, so a
                // forged add_on_id can't route around the filtered options.
                $sale = app(SubscriptionBundleService::class)->sell(
                    $member,
                    $addOn,
                    (int) $data['duration_months'],
                    Carbon::parse($data['desired_start']),
                    auth()->user(),
                    paymentMethod: $data['payment_method'] ?? null,
                    registerShift: $this->getOpenShift(),
                    applyVoucher: (bool) ($data['apply_voucher'] ?? false),
                    voucherPayerId: ! empty($data['voucher_payer_id']) ? (int) $data['voucher_payer_id'] : null,
                    voucherCents: Cents::of($data['voucher_amount'] ?? 0),
                    voucherReason: (string) ($data['voucher_reason'] ?? ''),
                );

                $title = __('Subscription recorded — :amount covering :range', ['amount' => $this->formatCurrency((float) $sale->rows->sum('amount_paid')), 'range' => $sale->rangeLabel()]);
                if ($sale->voucherAppliedCents > 0) {
                    $title .= ' ('.__(':amount voucher', ['amount' => $this->formatCurrency(Cents::toFloat($sale->voucherAppliedCents))]).')';
                }

                Notification::make()
                    ->title($title)
                    ->success()
                    ->send();
            });
    }

    // A one-time purchase covering a subscribable add-on for exactly one
    // event -- distinct from a subscription (a whole calendar month)
    // purchasable above via purchaseSubscriptionAction(). The rules (which
    // add-ons and events qualify, the fee, the one-time method) live in
    // AddOnDayPassService; this is only the form. Its own event Select, not
    // coupled to whatever event is currently selected on the page, so staff
    // can sell a pass for a different night while mid-transaction on
    // tonight's. See docs/BLUEPRINT.md "Fee pipeline".
    public function purchaseAddOnDayPassAction(): Action
    {
        $member = $this->getSelectedMember();
        $openShift = $this->getOpenShift();
        $lockedOneTimeCodes = $member?->hasUsedOneTimeMethod() ? PaymentMethod::oneTimeCodes()->all() : [];
        $dayPasses = app(AddOnDayPassService::class);
        $dayPassableAddOns = $member ? $dayPasses->sellableAddOns($member) : collect();
        $defaultAddOnId = $dayPassableAddOns->count() === 1 ? $dayPassableAddOns->first()->id : null;

        return Action::make('purchaseAddOnDayPass')
            ->label(__('Buy Day Pass'))
            ->visible(fn (): bool => (bool) $member && $dayPassableAddOns->isNotEmpty())
            ->schema([
                Select::make('add_on_id')
                    ->label(__('Add-on'))
                    ->options($dayPassableAddOns->pluck('name', 'id')->all())
                    ->default($defaultAddOnId)
                    ->required()
                    ->live(),
                Select::make('event_id')
                    ->label(__('Event'))
                    ->options(function (Get $get) use ($dayPasses) {
                        $addOn = $get('add_on_id') ? AddOn::find($get('add_on_id')) : null;
                        if (! $addOn) {
                            return [];
                        }

                        return $dayPasses->eventOptionsQuery($addOn)
                            ->orderBy('event_date')
                            ->get()
                            ->mapWithKeys(fn (Event $event) => [$event->id => "{$event->name} — {$event->event_date->isoFormat('ll')} ({$this->formatCurrency((float) $addOn->priceFor($event))})"]);
                    })
                    ->required()
                    ->searchable(),
                Select::make('payment_method')
                    ->live()
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true))
                    ->required()
                    ->helperText(fn (Get $get): ?string => $this->paymentMethodHelperText($openShift, $get('payment_method'))),
            ])
            ->action(function (array $data) use ($dayPasses): void {
                if ($this->haltForTraining(__('Practice: day pass simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $addOn = AddOn::find($data['add_on_id']);
                abort_unless($addOn, 404);
                $event = Event::find($data['event_id']);
                abort_unless($event, 404);

                // AddOnDayPassService::sell() re-checks the add-on, paperwork,
                // event and one-time method the pickers above filter on.
                try {
                    $pass = $dayPasses->sell(
                        $member,
                        $addOn,
                        $event,
                        auth()->user(),
                        paymentMethod: $data['payment_method'],
                        registerShift: $this->getOpenShift(),
                    );
                } catch (DayPassRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__(':addon day pass recorded — :amount for :event', ['addon' => $addOn->name, 'amount' => $this->formatCurrency((float) $pass->amount_paid), 'event' => $event->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Same-night "they paid entry, now they want to subscribe" -- an audited
     * exception to standard procedure. The rules and the write live in
     * EntryCorrectionService; this only shows the quote, collects the
     * reason, and re-checks the gate.
     */
    public function convertEntryToSubscriptionAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $service = app(EntryCorrectionService::class);

        return Action::make('convertEntryToSubscription')
            ->label(__('Convert entry to subscription'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->visible(fn (): bool => $attendance !== null
                && Gate::allows('correct-entry-payment')
                && $service->refusalReason($attendance) === null)
            ->modalDescription(function () use ($attendance, $service): string {
                $quote = $service->quoteCents($attendance);
                $method = PaymentMethod::where('code', $attendance->payment_method)->value('label') ?? $attendance->payment_method ?? __('no payment method');
                $register = $attendance->registerShift?->register;
                $atOtherRegister = $register && $register->id !== $this->registerId;

                return __('Subscription :subscription + entry now :new_entry, against the :old_entry already paid. :settlement (:method).', [
                    'subscription' => $this->formatCurrency(Cents::toFloat($quote['subscription'])),
                    'new_entry' => $this->formatCurrency(Cents::toFloat($quote['new_entry'])),
                    'old_entry' => $this->formatCurrency(Cents::toFloat($quote['old_entry'])),
                    'settlement' => EntryCorrectionService::settlementLabel($quote['net']),
                    'method' => $method,
                ]).($atOtherRegister ? ' '.__('Settle it at register :register.', ['register' => $register->name]) : '')
                    .' '.__('This is recorded as a payment correction and the Owner is notified.');
            })
            ->schema([
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: entry conversion simulated.'))) {
                    return;
                }

                abort_unless(Gate::allows('correct-entry-payment'), 403);

                $attendance = $this->getExistingAttendance();
                abort_unless($attendance, 404);

                $correction = app(EntryCorrectionService::class)->convertToSubscription($attendance, auth()->user(), $data['reason']);

                Notification::make()
                    ->title(__('Entry converted to subscription'))
                    ->body(EntryCorrectionService::settlementLabel(Cents::of($correction->net_amount)))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
