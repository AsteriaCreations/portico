<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdmissionOutcome;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Filament\Concerns\CapturesMemberIdentity;
use App\Filament\Concerns\ManagesCashBox;
use App\Filament\Concerns\MarksArrivals;
use App\Filament\Concerns\RenamesMemberUsername;
use App\Filament\Concerns\SellsSubscriptionsAtDesk;
use App\Filament\Concerns\ShowsKioskQrCode;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Voucher;
use App\Notifications\LastCheckInNotification;
use App\Services\AdmissionDecision;
use App\Services\AdmissionPolicy;
use App\Services\CapacityService;
use App\Services\CheckInRequest;
use App\Services\CheckInService;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\RegisterShiftService;
use App\Services\SubscriptionBundleService;
use App\Support\Cents;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckIn extends Page implements HasTable
{
    use CapturesMemberIdentity;
    use InteractsWithTable;
    use ManagesCashBox;
    use MarksArrivals;
    use RenamesMemberUsername;
    use SellsSubscriptionsAtDesk;
    use ShowsKioskQrCode;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.check-in';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Check-In Desk';

    protected static ?string $title = 'Check-In Desk';

    // Sits directly under Dashboard, above every navigation group -- the
    // single most-used page in the panel.
    protected static ?int $navigationSort = -1;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // Which register (cashbox) this session is working at — deliberately kept
    // out of $data/statePath('data'): mount() and registerGuestAction() both
    // call $this->form->fill([...]) with a partial key set, which resets any
    // other key sharing that state path.
    public ?int $registerId = null;

    // Subscription/comp/voucher choices, live and on-page rather than sealed inside
    // checkInAction's own modal — statePath('pricingData'), deliberately its
    // own form/property for the same reason registerId is kept separate
    // above. Reset whenever member or event changes (below) and after a
    // successful check-in (see checkInAction()), so a previous transaction's
    // choices never leak into the next one.
    //
    // add_on_ids must always be seeded as a real array, never left absent --
    // Alpine/Livewire's checkbox-group binding (getInputValue() in
    // livewire.js) only concats into an array on the *first* click when the
    // current bound value Array.isArray()'s true; otherwise it falls back to
    // writing the click's own boolean `checked` state as the whole property
    // value, which then forces every OTHER checkbox sharing that wire:model
    // to render checked too (`el.checked = !!value` once value isn't an
    // array). Observed live: checking "Private room rental" also checked
    // "Sleepover", and the underlying state became literally `true`.
    //
    // apply_voucher is seeded too: while a key is absent, Livewire sends its
    // first change as an update to the whole pricingData, not to
    // pricingData.apply_voucher, so Filament never ran the checkbox's
    // afterStateUpdated() and the first tick left the voucher amount empty.
    public ?array $pricingData = self::EMPTY_PRICING_DATA;

    private const EMPTY_PRICING_DATA = ['add_on_ids' => [], 'apply_voucher' => false];

    // Training mode: a new volunteer can rehearse the whole desk flow —
    // search, status line, Due total, Check in, the folded subscription /
    // day-pass / cash-box actions — without persisting anything. Every write
    // closure on this page short-circuits through haltForTraining() while
    // this is on; the real AdmissionPolicy / PricingService / capacity logic
    // still runs and renders exactly as normal. Public so it survives
    // Livewire round-trips; seeded from the session in mount() (session-
    // scoped, so it clears on logout) and toggled via getHeaderActions().
    public bool $trainingMode = false;

    // Bumped after a desk username rename. The Member picker keys on it
    // (see form()): its option label is cached client-side and only
    // re-fetched when the value changes, so renaming the selected member
    // kept showing the old username until re-picked. A new key makes
    // Livewire swap the element, which re-renders the label from the server.
    public int $memberPickerVersion = 0;

    // Every other role floor in this app has been Door-and-up, so nothing
    // else ever needed to gate this page explicitly. Showrunner now ranks
    // below Door and must not reach check-in/payment at all. See
    // docs/BLUEPRINT.md "Showrunners".
    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Door);
    }

    public function mount(): void
    {
        $this->form->fill([
            'event_id' => static::defaultEventId(),
        ]);

        $this->registerId = auth()->user()->default_register_id;

        $this->trainingMode = (bool) session('checkin.training_mode', false);
    }

    /**
     * The header toggle for training mode (see the $trainingMode property).
     * Available to anyone who can reach this page — the whole page is already
     * Door+ via canAccess(), so no extra gate. Session-scoped: the flag is
     * mirrored into the session here so it survives navigation and reloads
     * but clears on logout.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleTrainingMode')
                ->label($this->trainingMode ? __('Exit training mode') : __('Enter training mode'))
                ->icon(Heroicon::OutlinedAcademicCap)
                ->color($this->trainingMode ? 'warning' : 'gray')
                // Confirm only when switching it on — leaving it is always safe.
                ->requiresConfirmation(! $this->trainingMode)
                ->modalHeading(__('Enter training mode'))
                ->modalDescription(__('While training mode is on, nothing you do on this page is saved — it is for practice only.'))
                ->modalSubmitActionLabel(__('Enter training mode'))
                ->action(function (): void {
                    $this->trainingMode = ! $this->trainingMode;
                    session(['checkin.training_mode' => $this->trainingMode]);

                    // Drop any half-entered transaction so real work never
                    // bleeds into practice or vice versa — same reset the
                    // updated* hooks below do when member/event changes.
                    $this->pricingData = self::EMPTY_PRICING_DATA;
                    $this->form->fill(['event_id' => static::defaultEventId()]);

                    $notification = Notification::make();

                    if ($this->trainingMode) {
                        $notification->title(__('Training mode on — nothing will be saved'))->warning();
                    } else {
                        $notification->title(__('Training mode off — check-ins are live again'))->success();
                    }

                    $notification->send();
                }),
        ];
    }

    /**
     * With training mode on, every write action on this page stops here: the
     * real admission / pricing / decision logic has already run and is on
     * screen, but nothing is persisted. Returns true when the caller must
     * return without writing.
     */
    protected function haltForTraining(string $practiceMessage): bool
    {
        if (! $this->trainingMode) {
            return false;
        }

        Notification::make()
            ->title(__('Practice only — nothing saved'))
            ->body($practiceMessage)
            ->warning()
            ->send();

        return true;
    }

    // Sticky per user: fires automatically off wire:model.live="registerId"
    // in the Blade view, so the next visit to this page pre-selects it. Skipped
    // in training mode — the picker still moves on screen, it just isn't
    // persisted as the user's default.
    public function updatedRegisterId(?int $value): void
    {
        if ($this->trainingMode) {
            return;
        }

        auth()->user()->update(['default_register_id' => $value]);
    }

    // A different member also puts Event back to the page's default (unless
    // the reset_desk_event_on_member_change flag is off), so a pick made for
    // one person -- one of two overlapping events, or a later event for a
    // prepayment -- never carries over to the next. Only on a desk pick:
    // registerGuestAction() fills member and event together, which fires no
    // updated* hook, so a new guest keeps the sponsor's event.
    public function updatedDataMemberId(): void
    {
        $this->pricingData = self::EMPTY_PRICING_DATA;

        $memberId = $this->data['member_id'] ?? null;

        if (filled($memberId) && MembershipSetting::current()->reset_desk_event_on_member_change) {
            $this->data['event_id'] = static::tonightsEventIdFor((int) $memberId) ?? static::defaultEventId();
        }
    }

    public function updatedDataEventId(): void
    {
        $this->pricingData = self::EMPTY_PRICING_DATA;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                // Member first: it alone determines the admission decision, subscription
                // eligibility, and guest-sponsor status. Event only matters for
                // per-event price and capacity, so it's the second, narrower input.
                Select::make('member_id')
                    ->key(fn (): string => "member_id.v{$this->memberPickerVersion}")
                    ->label(__('Member'))
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $member) => [$member->id => static::memberLabel($member, $search)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($member = Member::find($value)) ? static::memberLabel($member) : null)
                    ->required(),
                Select::make('event_id')
                    ->label(__('Event'))
                    ->live()
                    ->searchable()
                    ->options(fn () => static::eventSelectQuery()
                        ->orderByDesc('event_date')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Event $event) => [$event->id => static::eventLabel($event)])
                        ->all())
                    ->getSearchResultsUsing(fn (string $search) => static::eventSelectQuery()
                        ->where('name', 'like', "%{$search}%")
                        ->orderByDesc('event_date')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Event $event) => [$event->id => static::eventLabel($event)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($event = Event::find($value)) ? static::eventLabel($event) : null)
                    ->required(),
            ]);
    }

    /**
     * One lookup per request for each selection, keyed by id so picking a
     * different member/event reloads. A render calls these ~25 times; each
     * used to be its own query. Private, so Livewire never persists it past
     * the request. Every desk write to the member goes through
     * getSelectedMember(), so it updates this same instance.
     *
     * @var array{mixed, ?Event}|null
     */
    private ?array $selectedEventCache = null;

    /** @var array{mixed, ?Member}|null */
    private ?array $selectedMemberCache = null;

    /** @var array{string|false, ?PriceBreakdown}|null See getLivePriceBreakdownBeforeVoucher(). */
    private ?array $livePriceBeforeVoucherCache = null;

    public function getSelectedEvent(): ?Event
    {
        $id = $this->data['event_id'] ?? null;

        if ($this->selectedEventCache === null || $this->selectedEventCache[0] !== $id) {
            $this->selectedEventCache = [$id, $id ? Event::find($id) : null];
        }

        return $this->selectedEventCache[1];
    }

    /**
     * Whether the selected event is running and past its check-in close
     * time for the selected member (a first visit is let through, see
     * Event::isCheckInClosedFor()). A prepay for a later event is never
     * closed here; CheckInService re-checks this under its own transaction.
     */
    public function isCheckInClosed(): bool
    {
        $event = $this->getSelectedEvent();
        $member = $this->getSelectedMember();

        return $event !== null && $event->isCurrentlyActive()
            && ($member ? $event->isCheckInClosedFor($member) : $event->isCheckInClosed());
    }

    /**
     * Check-in has closed but the selected member is on a first visit, so
     * the desk still offers Check in and says why.
     */
    public function isCheckInOpenForFirstVisit(): bool
    {
        $event = $this->getSelectedEvent();

        return $event !== null && $event->isCurrentlyActive() && $event->isCheckInClosed() && ! $this->isCheckInClosed();
    }

    public function getSelectedMember(): ?Member
    {
        $id = $this->data['member_id'] ?? null;

        if ($this->selectedMemberCache === null || $this->selectedMemberCache[0] !== $id) {
            $this->selectedMemberCache = [$id, $id ? Member::find($id) : null];
        }

        return $this->selectedMemberCache[1];
    }

    public function getExistingAttendance(): ?Attendance
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();

        if (! $member || ! $event) {
            return null;
        }

        return Attendance::query()
            ->where('member_id', $member->id)
            ->where('event_id', $event->id)
            ->first();
    }

    public function getDecision(): ?AdmissionDecision
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();

        if (! $member || ! $event) {
            return null;
        }

        return app(AdmissionPolicy::class)->decide($member, $event);
    }

    public function getPriceBreakdown(): ?PriceBreakdown
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();

        if (! $member || ! $event) {
            return null;
        }

        return app(PricingService::class)->price($member, $event);
    }

    // Live running total, reflecting whatever's currently selected in
    // pricingForm() — comp and subscription applied, voucher not yet. Split out from
    // getLivePriceBreakdown() below purely so apply_voucher's own visibility
    // and voucher_amount's default can both be capped at "what's due after
    // comp" without computing voucher twice.
    //
    // Computed once per render and reused: a render asks for it three times
    // (the Due line, apply_voucher's visibility, voucher_amount's default),
    // each a full PricingService preview (~7 queries). Keyed on the
    // selections, and dropped at the start of every render (rendering()), so
    // an action that changed what's owed without touching pricingData
    // (selling a standalone subscription, recording a waiver) is always
    // reflected. PriceBreakdown is readonly, so sharing it is safe.
    protected function getLivePriceBreakdownBeforeVoucher(): ?PriceBreakdown
    {
        $key = json_encode([$this->data['member_id'] ?? null, $this->data['event_id'] ?? null, $this->pricingData]);

        if ($this->livePriceBeforeVoucherCache === null || $this->livePriceBeforeVoucherCache[0] !== $key) {
            $this->livePriceBeforeVoucherCache = [$key, $this->computeLivePriceBreakdownBeforeVoucher()];
        }

        return $this->livePriceBeforeVoucherCache[1];
    }

    public function rendering(): void
    {
        $this->livePriceBeforeVoucherCache = null;
    }

    private function computeLivePriceBreakdownBeforeVoucher(): ?PriceBreakdown
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();

        if (! $member || ! $event) {
            return null;
        }

        $eligible = $member->isSubscriptionEligible();

        $addOnSubscriptionIdsSelected = $eligible
            ? AddOn::subscribable()->get()
                ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
                ->filter(fn (AddOn $addOn) => ($this->pricingData["subscription_addon_{$addOn->id}_duration"] ?? 'none') !== 'none')
                ->pluck('id')
                ->all()
            : [];

        $breakdown = app(PricingService::class)->previewWithSelections(
            $member,
            $event,
            regularSelected: $eligible && ($this->pricingData['subscription_regular_duration'] ?? 'none') !== 'none',
            addOnSubscriptionIdsSelected: $addOnSubscriptionIdsSelected,
        );

        if (($this->pricingData['comp_entry'] ?? false) && Gate::allows('grant-event-comp')) {
            $breakdown = app(PricingService::class)->applyEventComp($breakdown);
        }

        return $breakdown;
    }

    // The page's own running total — what pricingForm()'s current selections
    // would charge if "Check In" were clicked right now. Read-only: subscription
    // selection is simulated via PricingService::previewWithSelections()
    // rather than a real Subscription row, and voucher balance is checked
    // but never locked/spent here — only checkInAction()'s own closure
    // writes anything.
    public function getLivePriceBreakdown(): ?PriceBreakdown
    {
        $breakdown = $this->getLivePriceBreakdownBeforeVoucher();

        if (! $breakdown) {
            return null;
        }

        $member = $this->getSelectedMember();

        if ($member && ($this->pricingData['apply_voucher'] ?? false) && MembershipSetting::current()->vouchers_enabled) {
            $payerId = ! empty($this->pricingData['voucher_payer_id']) ? $this->pricingData['voucher_payer_id'] : $member->id;
            $payer = Member::find($payerId);

            if ($payer) {
                $breakdown = app(PricingService::class)->applyVoucher(
                    $breakdown,
                    Cents::of($payer->voucherBalance()),
                    Cents::of($this->pricingData['voucher_amount'] ?? 0),
                );
            }
        }

        return $breakdown;
    }

    /**
     * What the subscriptions picked in Payment options would cost, in cents
     * -- paid in this same check-in on top of entry, so the Due line has to
     * include it. Same targets and eligibility check as CheckInService, and
     * priced by the same SubscriptionBundleService::quoteCents() rule.
     */
    public function getLiveSubscriptionTotalCents(): int
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();

        if (! $member || ! $event || ! $member->isSubscriptionEligible()) {
            return 0;
        }

        $month = $event->event_date->clone()->startOfMonth();
        $service = app(SubscriptionBundleService::class);

        return collect([AddOn::entry()->id => 'subscription_regular_duration'])
            ->union(AddOn::subscribable()->get()
                ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
                ->mapWithKeys(fn (AddOn $addOn) => [$addOn->id => "subscription_addon_{$addOn->id}_duration"]))
            ->map(fn (string $field, int $addOnId): int => in_array($this->pricingData[$field] ?? 'none', ['none', null], true)
                ? 0
                : $service->quoteCents($member, AddOn::find($addOnId), (int) $this->pricingData[$field], $month))
            ->sum();
    }

    /**
     * The Due line: entry/pool after coverage, comp and voucher, plus
     * add-ons, plus any subscription bought in this check-in. The payment
     * method's transaction fee isn't known until the Check in dialog, so
     * it's left out here.
     */
    public function getLiveDueTotal(): float
    {
        return Cents::toFloat(($this->getLivePriceBreakdown()?->amountPaidCents ?? 0) + $this->getLiveSubscriptionTotalCents() - $this->getLiveSubscriptionVoucherCents())
            + $this->getLiveAddOnTotal();
    }

    /**
     * The voucher credit left over after entry/pool that would go toward the
     * subscriptions picked in Payment options -- the same split
     * CheckInService makes, previewed without spending anything.
     */
    public function getLiveSubscriptionVoucherCents(): int
    {
        $member = $this->getSelectedMember();
        $subscriptionTotal = $this->getLiveSubscriptionTotalCents();

        if (! $member || $subscriptionTotal <= 0 || ! ($this->pricingData['apply_voucher'] ?? false) || ! MembershipSetting::current()->vouchers_enabled) {
            return 0;
        }

        $payer = Member::find(! empty($this->pricingData['voucher_payer_id']) ? $this->pricingData['voucher_payer_id'] : $member->id);

        if (! $payer) {
            return 0;
        }

        $available = min(Cents::of($this->pricingData['voucher_amount'] ?? 0), Cents::of($payer->voucherBalance()));
        $usedOnEntry = $this->getLivePriceBreakdown()?->voucherCoverageCents ?? 0;

        return max(0, min($available - $usedOnEntry, $subscriptionTotal));
    }

    // Add-ons never go through PricingService/PriceBreakdown — they're a flat
    // additive charge on top of entry/pool, never comped or voucher-covered,
    // so they're tracked as a separate live total rather than a third
    // component wedged into the breakdown's fixed entry/pool shape.
    public function getLiveAddOnTotal(): float
    {
        if (! MembershipSetting::current()->add_ons_enabled) {
            return 0.0;
        }

        return (float) AddOn::offeredAt($this->getSelectedEvent())
            ->whereIn('id', static::normalizeAddOnIds($this->pricingData['add_on_ids'] ?? null))
            ->sum('price');
    }

    /**
     * A CheckboxList's raw Livewire-bound state is normally an array, but a
     * hidden/never-interacted field can round-trip as a bare boolean instead
     * (observed live: an unset add_on_ids came back as `true`, which crashes
     * whereIn()'s internal count() check) -- this is client-submitted state,
     * a real boundary, not a "can't happen" case, so it's normalized here
     * rather than trusted as already array-shaped.
     *
     * @return array<int, int|string>
     */
    private static function normalizeAddOnIds(mixed $ids): array
    {
        return is_array($ids) ? $ids : [];
    }

    // Subscription/comp/voucher choices, split out of checkInAction()'s own schema so
    // they're live on the page — see the pricingData property comment above.
    public function pricingForm(Schema $schema): Schema
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();
        $decision = $this->getDecision();
        $canPreviewPricing = $member && $event
            && ! ($decision?->blocksCheckIn() ?? true)
            && $decision?->outcome !== AdmissionOutcome::Capture;

        $eligible = $member?->isSubscriptionEligible() ?? false;
        $regularOptions = ($member && $event && $eligible) ? $this->subscriptionOptions(AddOn::entry(), $member, $event) : ['none' => __('No subscription payment')];
        // One Select per subscribable add-on (Pool, at launch) rather than a
        // hardcoded pair of fields -- a club that flags a second add-on
        // subscribable gets a duration picker for it with no code change.
        $addOnSubscriptionSelects = AddOn::subscribable()->orderBy('sort_order')->get()
            ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
            ->map(function (AddOn $addOn) use ($member, $event, $eligible, $canPreviewPricing) {
                $options = ($member && $event && $eligible) ? $this->subscriptionOptions($addOn, $member, $event) : ['none' => __('No subscription payment')];

                return Select::make("subscription_addon_{$addOn->id}_duration")
                    ->label(__(':addon Subscription (covers tonight)', ['addon' => $addOn->name]))
                    ->live()
                    ->options($options)
                    ->default('none')
                    ->visible($canPreviewPricing && $eligible && count($options) > 1);
            })
            ->values()
            ->all();
        $canGrantComp = Gate::allows('grant-event-comp');
        $entryFee = $this->getPriceBreakdown()?->entryFeeCents ?? 0;
        $ownBalance = $member?->voucherBalance() ?? 0.0;
        // Entry/pool still owed plus any subscription picked above -- credit
        // can pay toward either (CheckInService spends it in that order).
        $dueBeforeVoucher = ($this->getLivePriceBreakdownBeforeVoucher()?->amountPaidCents ?? 0) + $this->getLiveSubscriptionTotalCents();
        $defaultVoucherAmount = Cents::toFloat(min(Cents::of($ownBalance), $dueBeforeVoucher)) ?: null;
        // The suggestion follows whoever's balance is being drawn: the
        // attendee's own, or the other member picked below. Seeding it from
        // the attendee's balance alone left it blank (or at the attendee's
        // smaller balance) when another member was paying.
        $suggestVoucherAmount = function (mixed $payerId) use ($member, $ownBalance, $dueBeforeVoucher): ?float {
            $balance = filled($payerId) && (int) $payerId !== $member?->id
                ? (Member::find($payerId)?->voucherBalance() ?? 0.0)
                : $ownBalance;

            return Cents::toFloat(min(Cents::of($balance), $dueBeforeVoucher)) ?: null;
        };
        $voucherLabel = $member
            ? __("Apply voucher credit — :amount available on :name's account", ['amount' => $this->formatCurrency($ownBalance), 'name' => $member->displayName()])
            : __('Apply voucher credit');

        return $schema
            ->statePath('pricingData')
            ->components([
                CheckboxList::make('add_on_ids')
                    ->label(__('Add-ons'))
                    // Subscribable add-ons (Pool) are never in this list --
                    // they're priced automatically via PricingService
                    // whenever the event has a price for them, the same
                    // "no checkbox needed" behavior pool_fee always had.
                    // Only flat, non-subscribable extras bound to this event
                    // (EventForm's "Available add-ons" field) are opt-in
                    // here.
                    ->options(fn () => AddOn::offeredAt($event)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (AddOn $addOn) => [
                            $addOn->id => $addOn->name.' — '.$this->formatCurrency($addOn->price)
                                .(($event && ! $addOn->hasRoomOn($event->event_date)) ? ' (full for tonight)' : ''),
                        ])
                        ->all())
                    // Rebuilt fresh on every submit, same as the one-time
                    // payment-method options above -- Filament's own "in:"
                    // validation is built from enabled options only
                    // (CheckboxList::getInValidationRuleValues()), so a
                    // forged selection of a now-full add-on is rejected
                    // before checkInAction's closure ever runs.
                    ->disableOptionWhen(fn (int|string $value): bool => $event && ! (AddOn::find($value)?->hasRoomOn($event->event_date) ?? true))
                    ->live()
                    ->visible($canPreviewPricing && MembershipSetting::current()->add_ons_enabled),
                Select::make('subscription_regular_duration')
                    ->label(__('Regular Subscription (covers tonight)'))
                    ->live()
                    ->options($regularOptions)
                    ->default('none')
                    ->visible($canPreviewPricing && $eligible && count($regularOptions) > 1),
                ...$addOnSubscriptionSelects,
                Checkbox::make('comp_entry')
                    ->label(__('Comp this entry (e.g. worked the event)'))
                    ->live()
                    ->visible($canPreviewPricing && $canGrantComp && $entryFee > 0),
                Select::make('comp_reason_id')
                    ->label(__('Reason'))
                    ->options(fn () => CompReason::where('active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(fn (Get $get): bool => (bool) $get('comp_entry'))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && $canGrantComp && (bool) $get('comp_entry')),
                Checkbox::make('apply_voucher')
                    ->label($voucherLabel)
                    ->live()
                    // Picking a subscription after the form loaded changes
                    // what's due, so the amount is re-suggested on ticking.
                    ->afterStateUpdated(fn (Set $set, Get $get, ?bool $state) => $state ? $set('voucher_amount', $suggestVoucherAmount($get('voucher_payer_id'))) : null)
                    ->visible($canPreviewPricing && $member && MembershipSetting::current()->vouchers_enabled && $dueBeforeVoucher > 0),
                Select::make('voucher_payer_id')
                    ->label(__("Apply from a different member's balance (optional)"))
                    ->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('voucher_amount', $suggestVoucherAmount($state)))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $payer) => [$payer->id => static::memberLabel($payer, $search).' — '.$this->formatCurrency($payer->voucherBalance()).' available'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($payer = Member::find($value)) ? static::memberLabel($payer) : null)
                    ->helperText(__("Leave blank to use :name's own balance.", ['name' => $member?->displayName()]))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                TextInput::make('voucher_amount')
                    ->label(__('Voucher amount to apply'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->default($defaultVoucherAmount)
                    // So the Due line follows a typed amount, not just a suggested one.
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => (bool) $get('apply_voucher'))
                    ->helperText(__('Capped automatically at the balance available and what\'s still due — a partial amount is fine.'))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                // Only another member's balance needs a reason; a member
                // spending their own gets Voucher::redemptionReason()'s default.
                TextInput::make('voucher_reason')
                    ->label(__('Reason (required — kept on the ledger)'))
                    ->maxLength(255)
                    ->required($usesAnotherBalance = fn (Get $get): bool => (bool) $get('apply_voucher') && filled($get('voucher_payer_id')) && (int) $get('voucher_payer_id') !== $member?->id)
                    ->visible(fn (Get $get): bool => $canPreviewPricing && $usesAnotherBalance($get)),
            ]);
    }

    public function canSeeReason(): bool
    {
        return Gate::allows('view-sensitive-member-fields');
    }

    /**
     * Presentation-only: one colour-coded verdict line for the desk, so a
     * volunteer reads a decision ("Ready to admit" / "Under 21 — mark hand"
     * / "Do not admit") rather than parsing raw flags. No policy logic lives
     * here -- member-only mode mirrors AdmissionPolicy::decide()'s own
     * priority order (ages as of today), and member+event mode just relabels
     * getDecision()'s existing outcome. The banned, watchlist and two age
     * situations use the club's House Policy wording where set
     * (MembershipSetting::housePolicy()). AdmissionPolicy
     * / AdmissionDecision are untouched, so the decision->message copy
     * consumed by markArrivedAction's notifications and by ActivePatrons is
     * unaffected. `flags` keeps the underlying labels visible as supporting
     * detail (reason text still gated by canSeeReason()).
     *
     * @return array{tone: string, headline: string, detail: string|null, flags: list<string>}|null
     */
    public function statusStrip(): ?array
    {
        $member = $this->getSelectedMember();

        if (! $member) {
            return null;
        }

        $showReason = $this->canSeeReason();
        $policy = app(AdmissionPolicy::class);

        $flags = [];
        if ($member->is_deceased) {
            $flags[] = 'Deceased';
        }
        if ($member->isCurrentlyBanned()) {
            $flags[] = 'Banned'.($showReason && $member->ban_reason ? ' — '.$member->ban_reason : '');
        }
        if ($member->on_watchlist) {
            $flags[] = __('On watchlist').($showReason && $member->watchlist_reason ? ' — '.$member->watchlist_reason : '');
        }
        // Informational only -- never changes the admission decision.
        if ($member->isOnWatchlistProbation()) {
            $flags[] = __('Recently off watchlist — probation until :date', ['date' => $member->watchlistProbationEndsOn()->isoFormat('ll')]);
        }
        if ($policy->needsCapture($member)) {
            $flags[] = __('Prospective — sign-up incomplete');
        }
        if ($policy->needsPaperworkCapture($member)) {
            $flags[] = __('Missing paperwork');
        }

        $event = $this->getSelectedEvent();

        // The club's House Policy wording (Membership Settings), where set;
        // each part falls back to the shipped wording below.
        $banned = MembershipSetting::housePolicy('banned');
        $watchlist = MembershipSetting::housePolicy('watchlist');
        $underMajority = MembershipSetting::housePolicy('under_majority');
        $underAlcoholAge = MembershipSetting::housePolicy('under_alcohol_age');

        // Listed regardless of the headline, so it isn't hidden when a
        // watchlist/sign-up/paperwork outcome outranks it in decide(), but
        // not repeated under its own headline.
        $underAgeFlag = $underAlcoholAge['headline'] ?? __('Under :age — mark hand, no alcohol', ['age' => MembershipSetting::current()->alcohol_flag_age]);
        if ($policy->isUnderAlcoholFlagAge($member, $event)) {
            $flags[] = $underAgeFlag;
        }
        $flagsWithoutUnderAge = array_values(array_diff($flags, [$underAgeFlag]));

        if (! $event) {
            $provisional = match (true) {
                $member->is_deceased => ['stop', __('Do not admit'), null, $flags],
                $member->isCurrentlyBanned() => ['stop', $banned['headline'] ?? __('Do not admit'), $banned['instructions'], $flags],
                $policy->isUnderAgeOfMajority($member) => ['stop', $underMajority['headline'] ?? __('Do not admit'), $underMajority['instructions'], $flags],
                $member->on_watchlist => ['check', $watchlist['headline'] ?? __('Watchlist — notify :label, then confirm at check-in', ['label' => MembershipSetting::watchlistNotifyLabel()]), $watchlist['instructions'], $flags],
                $policy->needsCapture($member) => ['check', __('Prospective — finish sign-up to admit'), null, $flags],
                $policy->needsPaperworkCapture($member) => ['check', __('Missing paperwork — record new paperwork to admit'), null, $flags],
                $policy->isUnderAlcoholFlagAge($member) => ['check', $underAgeFlag, $underAlcoholAge['instructions'], $flagsWithoutUnderAge],
                default => ['go', __("No flags yet — pick tonight's event"), null, $flags],
            };

            return ['tone' => $provisional[0], 'headline' => $provisional[1], 'detail' => $provisional[2], 'flags' => $provisional[3]];
        }

        $decision = $this->getDecision();
        // Which House Policy a Block falls under; deceased keeps the shipped wording.
        $blockPolicy = match (true) {
            $member->is_deceased => ['headline' => null, 'instructions' => null],
            $member->isCurrentlyBanned() => $banned,
            default => $underMajority,
        };

        // detail carries the actionable instruction only; the ban/watchlist
        // reason already appears in $flags below (reason-gated), so it's not
        // re-appended here.
        return match ($decision->outcome) {
            AdmissionOutcome::Block => ['tone' => 'stop', 'headline' => $blockPolicy['headline'] ?? __('Do not admit'), 'detail' => $blockPolicy['instructions'], 'flags' => $flags],
            // A banned member's one-night exception keeps the shipped wording.
            AdmissionOutcome::Warn => $member->isCurrentlyBanned()
                ? ['tone' => 'check', 'headline' => __('Acknowledge before admitting'), 'detail' => $decision->message, 'flags' => $flags]
                : ['tone' => 'check', 'headline' => $watchlist['headline'] ?? __('Acknowledge before admitting'), 'detail' => $watchlist['instructions'] ?? $decision->message, 'flags' => $flags],
            // Capture has two causes; a Prospective's sign-up outranks
            // missing paperwork in decide(), so the headline follows suit.
            AdmissionOutcome::Capture => ['tone' => 'check', 'headline' => $policy->needsCapture($member) ? __('Finish sign-up to admit') : __('Record new paperwork to admit'), 'detail' => $decision->message, 'flags' => $flags],
            AdmissionOutcome::Flag => ['tone' => 'check', 'headline' => $underAgeFlag, 'detail' => $underAlcoholAge['instructions'], 'flags' => $flagsWithoutUnderAge],
            AdmissionOutcome::Ok => ['tone' => 'go', 'headline' => __('Ready to admit'), 'detail' => null, 'flags' => $flags],
        };
    }

    // Reflects MembershipSetting::member_search_fields so the help text never
    // promises a lookup the search box won't actually do.
    public function memberSearchFieldsLabel(): string
    {
        return Member::searchableFieldsLabel();
    }

    public function getOccupancy(): int
    {
        return app(CapacityService::class)->occupancy(today());
    }

    public function getCapacity(): ?int
    {
        return app(CapacityService::class)->capacity();
    }

    public function getCurrentRegister(): ?Register
    {
        return $this->registerId ? Register::find($this->registerId) : null;
    }

    /**
     * @return array<int, string>
     */
    public function getRegisterOptions(): array
    {
        return Register::where('active', true)->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    // Register-scoped, not per-user: any Door+ staff member sees and can act
    // on whichever shift is open on the selected register, not only the one
    // who opened it — a physical cashbox is shared across a shift's staff.
    public function getOpenShift(): ?RegisterShift
    {
        $register = $this->getCurrentRegister();

        return $register ? app(RegisterShiftService::class)->currentOpenShift($register) : null;
    }

    // A requires_register_shift method (e.g. Cash) can only be selected while
    // a shift is actually open on this register -- re-evaluated fresh on
    // every render/submit by whichever action calls this, so a forged
    // submission with no shift open never validates (see checkInAction's own
    // note on this below). Only methods available_at_desk are offered at all
    // ("Other" is off by default), and Filament's in()-options validation
    // rejects a forged one the same way.
    /**
     * @return array<string, string>
     */
    private function paymentMethodOptions(?RegisterShift $openShift): array
    {
        if ($openShift) {
            return PaymentMethod::deskOptions();
        }

        $cashCodes = PaymentMethod::cashCodes();

        return collect(PaymentMethod::deskOptions())
            ->reject(fn (string $label, string $code) => $cashCodes->contains($code))
            ->all();
    }

    // The fee note, plus why Cash is missing while no box is open -- the desk
    // must pick a method whenever money changes hands, so without this a
    // cash payment had nowhere to go but a blank method outside every box.
    private function paymentMethodHelperText(?RegisterShift $openShift, ?string $code): ?string
    {
        $noBoxNote = ! $openShift
            && MembershipSetting::current()->register_shifts_enabled
            && collect(PaymentMethod::deskOptions())->keys()->intersect(PaymentMethod::cashCodes())->isNotEmpty()
                ? __('No box is open on this register, so cash isn\'t offered. Open the box to take cash, or pick another method.')
                : null;

        return collect([$noBoxNote, PaymentMethod::feeHelperText($code)])->filter()->implode(' ') ?: null;
    }

    // Lets the desk fix a username on the spot (a typo at sign-up, a
    // member who changed theirs) without fetching a Manager. Hidden unless
    // the rename-member-username gate allows it -- for Door that means the
    // club turned on door_username_rename_enabled. Member-only, like
    // saveAndPromoteAction(). Logged by MemberObserver either way.
    public function renameUsernameAction(): Action
    {
        return $this->makeRenameUsernameAction(
            resolveMember: fn (): ?Member => $this->getSelectedMember(),
            haltBeforeSave: fn (): bool => $this->haltForTraining(__('Practice: username rename simulated.')),
            afterSave: fn () => $this->memberPickerVersion++,
        );
    }

    // The member's kiosk QR, to print or photograph -- typically right after
    // they buy a subscription here. Hidden unless the club turned on
    // kiosk_checkin_enabled. In training mode an existing code still shows,
    // but a missing one isn't created.
    public function kioskQrCodeAction(): Action
    {
        return $this->makeKioskQrCodeAction(
            resolveMember: fn (): ?Member => $this->getSelectedMember(),
            isPractice: fn (): bool => $this->trainingMode,
        );
    }

    // Same gate as kioskQrCodeAction(); shown only when the member has an
    // email on file. Training mode sends nothing.
    public function emailKioskQrCodeAction(): Action
    {
        return $this->makeEmailKioskQrCodeAction(
            resolveMember: fn (): ?Member => $this->getSelectedMember(),
            isPractice: fn (): bool => $this->trainingMode,
        );
    }

    // Sends the code to the kiosk tablet for the member to photograph,
    // once the club turns on kiosk_show_code_enabled. Training mode sends
    // nothing.
    public function showKioskQrCodeAction(): Action
    {
        return $this->makeShowKioskQrCodeAction(
            resolveMember: fn (): ?Member => $this->getSelectedMember(),
            isPractice: fn (): bool => $this->trainingMode,
        );
    }

    /**
     * Subscription payment options for one add-on (or Entry) at check-in:
     * "no payment," the ordinary single month (hidden outright if that
     * exact month is already covered — unchanged from before bundles
     * existed), and any bulk duration currently configured in Plans. A bulk
     * option is never hidden on conflict — the resolved (possibly shifted)
     * coverage range is baked right into its label instead, so staff see
     * where it'll land before anyone commits to it.
     *
     * @return array<int|string, string>
     */
    protected function subscriptionOptions(AddOn $addOn, Member $member, Event $event): array
    {
        $options = ['none' => __('No subscription payment')];
        $eventMonth = $event->event_date->clone()->startOfMonth();

        // As of now, like the bundles below and the charge itself
        // (CheckInService) -- the desk collects today's price.
        $monthlyPlan = Plan::currentFor($addOn, now());
        if ($monthlyPlan && ! $member->hasActiveSubscriptionFor($addOn, $eventMonth)) {
            $options[1] = __('This month — :amount', ['amount' => $this->formatCurrency($monthlyPlan->price)]);
        }

        $service = app(SubscriptionBundleService::class);

        // As of now, not the event's date: a bundle is priced at purchase
        // time (SubscriptionBundleService::purchase() does the same), since
        // the check-in desk collects payment today regardless of which
        // (possibly future, door-prepay) event is selected.
        foreach (Plan::currentOptionsFor($addOn, now()) as $plan) {
            if ($plan->duration_months <= 1) {
                continue;
            }

            try {
                $resolution = $service->resolveStart($member, $addOn, $eventMonth, $plan->duration_months);
            } catch (HttpException) {
                // No free block found within the lookahead — an extreme edge
                // case; just don't offer this duration rather than error the page.
                continue;
            }

            $endMonth = $resolution->start->clone()->addMonthsNoOverflow($plan->duration_months - 1);
            $rangeLabel = $resolution->start->isSameMonth($endMonth)
                ? $resolution->start->translatedFormat('F Y')
                : $resolution->start->translatedFormat('F Y').' – '.$endMonth->translatedFormat('F Y');

            $label = __(':months months — :amount (:range)', ['months' => $plan->duration_months, 'amount' => $this->formatCurrency($plan->price), 'range' => $rangeLabel]);

            if (! empty($resolution->skippedMonths)) {
                $skippedLabel = collect($resolution->skippedMonths)->map(fn ($m) => $m->translatedFormat('F Y'))->implode(', ');
                $label .= ' — '.__(':months already covered', ['months' => $skippedLabel]);
            }

            $options[$plan->duration_months] = $label;
        }

        return $options;
    }

    // A small final-confirm step: Subscription/comp/voucher choices are already made
    // and visible on the page via pricingForm() above by the time this opens
    // — see the pricingData property comment. All of this action's own
    // defense-in-depth (re-fetch member/event, re-check AdmissionPolicy, the
    // comp gate, capacity, lock the voucher payer row, transaction, the
    // unique-constraint race) is unchanged from before the flatten; only
    // where the Subscription/comp/voucher values come from changed, from this
    // action's own $data to $this->pricingData.
    public function checkInAction(): Action
    {
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();
        $requiresAcknowledgement = $this->getDecision()?->requiresAcknowledgement() ?? false;
        $openShift = $this->getOpenShift();
        $lockedOneTimeCodes = $member?->hasUsedOneTimeMethod() ? PaymentMethod::oneTimeCodes()->all() : [];

        return Action::make('checkIn')
            // Named, so a wrong pick between overlapping events shows at the click.
            ->label($event ? __('Check in to :event', ['event' => $event->name]) : __('Check in'))
            ->schema([
                ...($requiresAcknowledgement ? [
                    Checkbox::make('acknowledged')
                        ->label(__('I have notified :label per the watchlist note.', ['label' => MembershipSetting::watchlistNotifyLabel()]))
                        ->accepted()
                        ->required(),
                ] : []),
                DateTimePicker::make('checked_in_at')
                    ->default(now())
                    // Only shown for a live/today event -- a future event is
                    // only selectable here because of door_prepay_enabled, so
                    // there's nothing to arrive at yet; checked_in_at is
                    // forced null for it below regardless of what's submitted.
                    ->visible(fn (): bool => ! $event || $event->isCurrentlyActive()),
                Select::make('payment_method')
                    ->live()
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true))
                    ->helperText(fn (Get $get): ?string => $this->paymentMethodHelperText($openShift, $get('payment_method')))
                    // Required whenever money changes hands, or cash taken
                    // with no box open lands outside every box's count.
                    ->required()
                    // Nothing changes hands on a $0 visit, so there's no
                    // method to ask for; CheckInService records none.
                    ->visible(fn (): bool => $this->getLiveDueTotal() > 0),
                TextInput::make('on_behalf_note')
                    ->label(__('On behalf of / guest note'))
                    ->maxLength(120),
                TextInput::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => ! $this->getExistingAttendance()
                && ! ($this->getDecision()?->blocksCheckIn() ?? true)
                && $this->getDecision()?->outcome !== AdmissionOutcome::Capture
                && ! $this->isCheckInClosed()
                && ($event === null || app(CapacityService::class)->hasRoom($event->event_date)))
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice check-in complete — :amount would have been charged. Nothing was saved.', ['amount' => $this->formatCurrency($this->getLiveDueTotal())]))) {
                    return;
                }

                $member = $this->getSelectedMember();
                $event = $this->getSelectedEvent();
                abort_unless($member && $event, 404);

                // getSelectedEvent() does a raw Event::find() with no relation
                // to eventSelectQuery()'s own dropdown filtering -- a forged
                // event_id for a non-active event must be one the picker would
                // offer: prepay switched on AND this event opted in. Checking
                // the setting alone let a forged id prepay into any future
                // event. A currently-active event is never affected.
                abort_unless($event->isCurrentlyActive() || (MembershipSetting::current()->prepay_enabled && $event->door_prepay_enabled), 403);
                abort_if($event->isArchived(), 403);

                // pricingForm's own required-if rules (comp_reason_id when
                // comp_entry is checked, the voucher fields when apply_voucher
                // is checked) never get validated on their own — nothing ever
                // "submits" that form, it's just live page state. Validating
                // it here reproduces what used to happen for free when these
                // fields lived inside checkInAction's own schema.
                $this->pricingForm->getState();

                // Re-checked here, not just via the action's own visible():
                // two registers can have this same member+event pulled up at
                // once, and the second submission to land must not crash or
                // silently create a duplicate attendance row.
                if ($this->getExistingAttendance()) {
                    Notification::make()
                        ->title(__('Already checked in — another register just recorded this.'))
                        ->warning()
                        ->send();

                    return;
                }

                $decision = app(AdmissionPolicy::class)->decide($member, $event);
                abort_if($decision->blocksCheckIn(), 403);

                // Re-fetched at submit time, not render time. A forged "cash"
                // submission with no register actually open never reaches
                // this point at all — the schema's own options() closure is
                // rebuilt fresh on every submit, and Filament validates the
                // Select's value against it, rejecting the whole submission
                // before the action() closure runs.
                $openShift = $this->getOpenShift();
                try {
                    $result = app(CheckInService::class)->record(
                        $member,
                        $event,
                        auth()->user(),
                        $this->checkInRequest($data),
                        $openShift,
                    );
                } catch (CheckInRefused $refused) {
                    Notification::make()
                        ->title($refused->title)
                        ->body($refused->body)
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    // 23000 covers every integrity violation, not just the
                    // attendance unique key -- a subscription month another
                    // register sold at the same moment rolls back this whole
                    // check-in too. Only claim "already checked in" when the
                    // row is really there; otherwise nothing was saved, and
                    // the member must not be waved through on that basis.
                    if ($this->getExistingAttendance()) {
                        Notification::make()
                            ->title(__('Already checked in — another register just recorded this at the same moment.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    report($exception);

                    Notification::make()
                        ->title(__('Check-in not saved — another register changed this member\'s record at the same moment.'))
                        ->body(__('Nothing was recorded or charged. Reselect the member and try again.'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // Cleared so the next transaction (same member, or the next
                // one after registerGuestAction re-selects a new guest) never
                // inherits this one's comp/voucher/subscription choices.
                $this->pricingData = self::EMPTY_PRICING_DATA;

                $title = __('Checked in to :event — :amount due', ['event' => $event->name, 'amount' => $this->formatCurrency(Cents::toFloat($result->breakdown->amountPaidCents + $result->addOnTotalCents))]);
                if ($result->subscriptionTotalCents > 0) {
                    $title .= ' + '.__(':amount Subscription', ['amount' => $this->formatCurrency(Cents::toFloat($result->subscriptionTotalCents))]);
                }
                if ($result->addOnTotalCents > 0) {
                    $title .= ' + '.__(':amount Add-ons', ['amount' => $this->formatCurrency(Cents::toFloat($result->addOnTotalCents))]);
                }
                if ($result->voucherAppliedCents > 0) {
                    $title .= ' − '.__(':amount voucher', ['amount' => $this->formatCurrency(Cents::toFloat($result->voucherAppliedCents))]);
                }

                $notification = Notification::make()->title($title)->success();

                // Cash for a later event is held for it, not kept in this box
                // (RegisterShiftService::heldPrepayCashCents()) -- set aside
                // now, so the box's "expected" figure matches what's in it.
                $attendance = $result->attendance;
                $prepaidCashCents = Cents::of($attendance->amount_paid);
                if ($attendance->prepaid_ahead && $prepaidCashCents > 0 && PaymentMethod::cashCodes()->contains($attendance->payment_method)) {
                    $notification->body(__('Prepaid for :event — put :amount in that event\'s prepay envelope, not the box.', [
                        'event' => $event->label(),
                        'amount' => $this->formatCurrency(Cents::toFloat($prepaidCashCents)),
                    ]))->persistent();
                }

                $notification->send();

                $this->rememberLastCheckIn($member, $event, $title, $attendance->payment_method);
            });
    }

    /**
     * Keeps the check-in toast in the bell for the staff member who took it,
     * replacing their previous one, so the desk can double-check what the
     * last person owed after the toast fades.
     */
    private function rememberLastCheckIn(Member $member, Event $event, string $amountDue, ?string $paymentMethodCode): void
    {
        $user = auth()->user();
        $paymentMethod = $paymentMethodCode ? PaymentMethod::where('code', $paymentMethodCode)->value('label') : null;

        $body = collect([$amountDue, $paymentMethod ? __('Paid by :method', ['method' => $paymentMethod]) : null, $event->label()])
            ->filter()
            ->implode(' · ');

        $notification = Notification::make()
            ->title(__('Last check-in: :name', ['name' => $member->displayName()]))
            ->body($body)
            ->icon('heroicon-o-banknotes')
            ->success();

        $user->notifications()->where('type', LastCheckInNotification::class)->delete();

        // sendNow(), not sendToDatabase(): this app runs no queue worker, and
        // Filament's database notification is queued.
        LaravelNotification::sendNow($user, new LastCheckInNotification($notification->getDatabaseMessage()));

        $this->dispatch('databaseNotificationsSent');
    }

    /**
     * The desk's submitted choices, from the checkIn action's own $data
     * (time, payment method, notes) and the live pricingForm state
     * (subscriptions, add-ons, comp, voucher). Only maps field names to
     * CheckInRequest; every value is re-checked by CheckInService.
     */
    private function checkInRequest(array $data): CheckInRequest
    {
        $pricingData = $this->pricingData;

        $subscriptionFields = collect([AddOn::entry()->id => 'subscription_regular_duration'])
            ->union(AddOn::subscribable()->pluck('id')->mapWithKeys(fn ($id) => [$id => "subscription_addon_{$id}_duration"]));
        $subscriptionMonths = $subscriptionFields
            ->map(fn (string $field) => $pricingData[$field] ?? 'none')
            ->reject(fn (mixed $selected) => $selected === 'none' || $selected === null)
            ->map(fn (mixed $selected) => (int) $selected)
            ->all();

        return new CheckInRequest(
            checkedInAt: $data['checked_in_at'] ?? null,
            paymentMethod: $data['payment_method'] ?? null,
            onBehalfNote: $data['on_behalf_note'] ?? null,
            notes: $data['notes'] ?? null,
            subscriptionMonths: $subscriptionMonths,
            addOnIds: static::normalizeAddOnIds($pricingData['add_on_ids'] ?? null),
            compEntry: (bool) ($pricingData['comp_entry'] ?? false),
            compReasonId: $pricingData['comp_reason_id'] ?? null,
            applyVoucher: (bool) ($pricingData['apply_voucher'] ?? false),
            voucherPayerId: $pricingData['voucher_payer_id'] ?? null,
            voucherAmountCents: Cents::of($pricingData['voucher_amount'] ?? 0),
            voucherReason: (string) ($pricingData['voucher_reason'] ?? ''),
        );
    }

    protected function formatCurrency(float $amount): string
    {
        return MembershipSetting::formatMoney($amount);
    }

    /**
     * Back check-in: everyone still on the prepay list for the selected
     * event, with a one-click "mark arrived" per row — a second entry point
     * onto the same data as markArrivedAction(), for working through the
     * whole expected roster rather than one specific person at a time.
     */
    public function table(Table $table): Table
    {
        return $table
            // A closure, read when the records load: Filament builds the
            // table at boot, before a new event pick is applied, so reading
            // the event here showed the previously selected event's prepays.
            ->query(fn () => Attendance::query()->whereNull('checked_in_at')->where('event_id', $this->getSelectedEvent()?->id ?? 0))
            ->heading(__('Prepaid, awaiting arrival'))
            // Split/Stack layout, not a flat column list -- each prepay row
            // renders as one stacked card on a phone and a single row at sm+,
            // so the desk never needs a sideways scroll to reach the amount
            // or the "Mark arrived" button. Mirrors ActivePatrons::table().
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('member.username')
                            ->label(__('Member'))
                            ->weight(FontWeight::Bold),
                        TextColumn::make('notes')
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->placeholder(__('—'))
                            ->wrap(),
                    ])->space(1),

                    Stack::make([
                        TextColumn::make('amount_paid')
                            ->money()
                            ->icon(Heroicon::OutlinedBanknotes),
                    ])->space(1)->alignment(Alignment::End)->grow(false),
                ])->from('sm'),
            ])
            ->recordActions([$this->markArrivedRowAction()]);
    }

    protected static function defaultEventId(): ?int
    {
        $currentEventIds = static::currentEventQuery()->pluck('id');

        return $currentEventIds->count() === 1 ? $currentEventIds->first() : null;
    }

    /**
     * The one event running now that $memberId already has a row for
     * (prepaid, on the comp list, or checked in); null for none or several.
     */
    protected static function tonightsEventIdFor(int $memberId): ?int
    {
        $eventIds = Attendance::query()
            ->where('member_id', $memberId)
            ->whereIn('event_id', static::currentEventQuery()->select('id'))
            ->distinct()
            ->pluck('event_id');

        return $eventIds->count() === 1 ? $eventIds->first() : null;
    }

    /**
     * Normally only today's event(s) belong at the desk — an event flagged
     * for door_prepay_enabled is surfaced ahead of its own date too, so the
     * desk can take a walk-in prepayment for it. Archived events never
     * appear: the prepay branch is grouped with the current-event one so
     * currentQuery()'s archived_at filter can't be sidestepped by an OR.
     */
    protected static function eventSelectQuery(): Builder
    {
        if (! MembershipSetting::current()->prepay_enabled) {
            return static::currentEventQuery();
        }

        return Event::query()
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query
                ->whereIn('id', static::currentEventQuery()->select('id'))
                ->orWhere('door_prepay_enabled', true));
    }

    /**
     * "What counts as a currently active event" lives on Event::currentQuery()
     * — shared with the Active Patrons page, which needs the same definition
     * for its own cross-event roster.
     */
    protected static function currentEventQuery(): Builder
    {
        return Event::currentQuery();
    }

    protected static function eventLabel(Event $event): string
    {
        return $event->label();
    }

    /**
     * One compact identifier, plus — for a search result — whichever enabled
     * search field matched what was typed. See Member::pickerLabel().
     */
    protected static function memberLabel(Member $member, ?string $search = null): string
    {
        return Member::pickerLabel($member, $search);
    }

    /**
     * Which member fields are searchable is club-configurable
     * (MembershipSetting::member_search_fields, default username-only) — see
     * Member::searchableColumns(). Username, when enabled, stays the desk's
     * primary lookup: its matches come first, and the other enabled columns
     * only fill the remaining result slots rather than competing head-on.
     *
     * @return Collection<int, Member>
     */
    protected static function searchMembers(string $search, int $limit = 50): Collection
    {
        $columns = Member::searchableColumns();

        $primary = in_array('username', $columns, true)
            ? Member::query()->where('username', 'like', "%{$search}%")->limit($limit)->get()
            : new Collection;

        $remaining = $limit - $primary->count();
        $fallbackColumns = array_values(array_diff($columns, ['username']));

        if ($remaining <= 0 || $fallbackColumns === []) {
            return $primary;
        }

        $fallback = Member::query()
            ->whereNotIn('id', $primary->pluck('id'))
            ->where(function ($query) use ($fallbackColumns, $search): void {
                foreach ($fallbackColumns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            })
            ->limit($remaining)
            ->get();

        return $primary->concat($fallback);
    }
}
