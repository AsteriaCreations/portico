<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdmissionOutcome;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\PaperworkType;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\Voucher;
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
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
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
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckIn extends Page implements HasTable
{
    use InteractsWithTable;
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
    public ?array $pricingData = ['add_on_ids' => []];

    // Training mode: a new volunteer can rehearse the whole desk flow —
    // search, status line, Due total, Check in, the folded subscription /
    // day-pass / cash-box actions — without persisting anything. Every write
    // closure on this page short-circuits through haltForTraining() while
    // this is on; the real AdmissionPolicy / PricingService / capacity logic
    // still runs and renders exactly as normal. Public so it survives
    // Livewire round-trips; seeded from the session in mount() (session-
    // scoped, so it clears on logout) and toggled via getHeaderActions().
    public bool $trainingMode = false;

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
                    $this->pricingData = ['add_on_ids' => []];
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

    public function updatedDataMemberId(): void
    {
        $this->pricingData = ['add_on_ids' => []];
    }

    public function updatedDataEventId(): void
    {
        $this->pricingData = ['add_on_ids' => []];
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
                    ->label(__('Member'))
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $member) => [$member->id => static::memberLabel($member)])
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

    public function getSelectedEvent(): ?Event
    {
        $id = $this->data['event_id'] ?? null;

        return $id ? Event::find($id) : null;
    }

    public function getSelectedMember(): ?Member
    {
        $id = $this->data['member_id'] ?? null;

        return $id ? Member::find($id) : null;
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
    protected function getLivePriceBreakdownBeforeVoucher(): ?PriceBreakdown
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
        return Cents::toFloat(($this->getLivePriceBreakdown()?->amountPaidCents ?? 0) + $this->getLiveSubscriptionTotalCents())
            + $this->getLiveAddOnTotal();
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
                    ->visible($canPreviewPricing && $member && MembershipSetting::current()->vouchers_enabled && ($this->getLivePriceBreakdownBeforeVoucher()?->amountPaidCents ?? 0) > 0),
                Select::make('voucher_payer_id')
                    ->label(__("Apply from a different member's balance (optional)"))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $payer) => [$payer->id => static::memberLabel($payer).' — '.$this->formatCurrency($payer->voucherBalance()).' available'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($payer = Member::find($value)) ? static::memberLabel($payer) : null)
                    ->helperText(__("Leave blank to use :name's own balance.", ['name' => $member?->displayName()]))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                TextInput::make('voucher_amount')
                    ->label(__('Voucher amount to apply'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->default(Cents::toFloat(min(Cents::of($ownBalance), $this->getLivePriceBreakdownBeforeVoucher()?->amountPaidCents ?? 0)) ?: null)
                    ->required(fn (Get $get): bool => (bool) $get('apply_voucher'))
                    ->helperText(__('Capped automatically at the balance available and what\'s still due — a partial amount is fine.'))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                TextInput::make('voucher_reason')
                    ->label(__('Reason (required — kept on the ledger)'))
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => (bool) $get('apply_voucher'))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
            ]);
    }

    public function canSeeReason(): bool
    {
        return Gate::allows('view-sensitive-member-fields');
    }

    /**
     * Presentation-only: one colour-coded verdict line for the desk, so a
     * volunteer reads a decision ("Ready to admit" / "Check ID" / "Do not
     * admit") rather than parsing raw flags. No policy logic lives here --
     * member-only mode mirrors AdmissionPolicy::decide()'s own priority
     * order minus the age checks (which need the event), and member+event
     * mode just relabels getDecision()'s existing outcome. AdmissionPolicy
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
        if ($policy->needsCapture($member)) {
            $flags[] = __('Prospective — sign-up incomplete');
        }
        if ($policy->needsPaperworkCapture($member)) {
            $flags[] = __('Paperwork not confirmed');
        }

        $event = $this->getSelectedEvent();

        // Listed regardless of the headline, so it isn't hidden when a
        // watchlist/sign-up/paperwork outcome outranks it in decide().
        if ($policy->isUnderAlcoholFlagAge($member, $event)) {
            $flags[] = __('Under :age — no alcohol, mark hand', ['age' => MembershipSetting::current()->alcohol_flag_age]);
        }

        if (! $event) {
            $provisional = match (true) {
                $member->is_deceased, $member->isCurrentlyBanned() => ['stop', __('Do not admit')],
                $member->on_watchlist => ['check', __('Watchlist — notify :label, then confirm at check-in', ['label' => config('membership.watchlist_notify_label')])],
                $policy->needsCapture($member) => ['check', __('Prospective — finish sign-up to admit')],
                $policy->needsPaperworkCapture($member) => ['check', __('Missing paperwork — confirm on file to admit')],
                default => ['go', __("No flags yet — pick tonight's event")],
            };

            return ['tone' => $provisional[0], 'headline' => $provisional[1], 'detail' => null, 'flags' => $flags];
        }

        $decision = $this->getDecision();

        // detail carries the actionable instruction only; the ban/watchlist
        // reason already appears in $flags below (reason-gated), so it's not
        // re-appended here.
        return match ($decision->outcome) {
            AdmissionOutcome::Block => ['tone' => 'stop', 'headline' => __('Do not admit'), 'detail' => null, 'flags' => $flags],
            AdmissionOutcome::Warn => ['tone' => 'check', 'headline' => __('Acknowledge before admitting'), 'detail' => $decision->message, 'flags' => $flags],
            AdmissionOutcome::Capture => ['tone' => 'check', 'headline' => __('Finish sign-up to admit'), 'detail' => $decision->message, 'flags' => $flags],
            AdmissionOutcome::Flag => ['tone' => 'check', 'headline' => __('Check ID — under :age, no alcohol, mark hand', ['age' => MembershipSetting::current()->alcohol_flag_age]), 'detail' => null, 'flags' => $flags],
            AdmissionOutcome::Ok => ['tone' => 'go', 'headline' => __('Ready to admit'), 'detail' => null, 'flags' => $flags],
        };
    }

    // Reflects MembershipSetting::member_search_fields so the help text never
    // promises a lookup the search box won't actually do.
    public function memberSearchFieldsLabel(): string
    {
        return Member::searchableFieldsLabel();
    }

    /**
     * Active PaperworkTypes that gate an add-on and that the selected member
     * has no valid signing for -- the Pool Waiver, at launch. Backs both the
     * warning lines below and recordGatedPaperworkAction().
     *
     * Only for an add-on that's actually in play: one still on sale, or one
     * tonight's event still charges for (a pool_fee set before the Pool flag
     * went off). With Pool switched off there's nothing to sign for, so no
     * warning and no "Record a waiver signature" button.
     *
     * @return Collection<int, PaperworkType>
     */
    public function gatedPaperworkTypesMissing(?Member $member): Collection
    {
        if (! $member) {
            return collect();
        }

        $event = $this->getSelectedEvent();

        return PaperworkType::query()
            ->where('active', true)
            ->whereNotNull('gates_add_on_id')
            ->with('addOn')
            ->get()
            ->filter(fn (PaperworkType $type): bool => $type->addOn?->active
                && ($type->addOn->isCurrentlyPurchasable() || ($event && $type->addOn->priceFor($event) !== null)))
            ->reject(fn (PaperworkType $type): bool => $member->hasValidPaperwork($type))
            ->values();
    }

    /**
     * One warning line per subscribable add-on the selected member can't
     * currently use because a gating PaperworkType (e.g. the Pool Waiver)
     * is missing or expired -- shown in the member-only section so staff
     * know why the add-on's line and charge are absent. See
     * Member::canUseAddOn() / PricingService.
     *
     * @return string[]
     */
    public function getGatedAddOnWarnings(): array
    {
        return $this->gatedPaperworkTypesMissing($this->getSelectedMember())
            ->map(fn (PaperworkType $type) => __(':addon unavailable — :waiver missing or expired. Not charged; do not admit to :addon until it is renewed.', ['addon' => $type->addOn->name, 'waiver' => $type->name]))
            ->all();
    }

    /**
     * Note lines for the "Sell a subscription or day pass" fold: a day pass
     * for a priced_per_event add-on (Pool) is only offered once its gating
     * waiver is on file, so purchaseAddOnDayPassAction is simply hidden
     * without it. Rather than leave staff wondering where the button went,
     * this spells out that a signature is the prerequisite -- recorded via
     * recordGatedPaperworkAction in the member section above.
     *
     * @return string[]
     */
    public function getDayPassPaperworkNotes(): array
    {
        return $this->gatedPaperworkTypesMissing($this->getSelectedMember())
            ->filter(fn (PaperworkType $type) => $type->addOn->subscribable && $type->addOn->priced_per_event)
            ->map(fn (PaperworkType $type) => __(':addon day pass — requires a signed :waiver first. Record the signature above to enable it.', ['addon' => $type->addOn->name, 'waiver' => $type->name]))
            ->values()
            ->all();
    }

    // A member who wants a gated add-on (e.g. Pool) but has no valid waiver
    // for it can sign it right at the desk -- mirrors confirmPaperworkAction
    // (standard paperwork on file) and saveAndPromoteAction (Prospective
    // identity). Records a member_paperwork signing as of the chosen date;
    // the add-on's line returns to pricing on the next render. Door+ (the
    // whole page is Door+), same trust level as confirming standard
    // paperwork.
    public function recordGatedPaperworkAction(): Action
    {
        $missing = $this->gatedPaperworkTypesMissing($this->getSelectedMember());

        return Action::make('recordGatedPaperwork')
            ->label(__('Record a waiver signature'))
            ->visible(fn (): bool => $missing->isNotEmpty())
            ->schema([
                Select::make('paperwork_type_id')
                    ->label(__('Waiver'))
                    ->options($missing->pluck('name', 'id')->all())
                    ->default($missing->count() === 1 ? $missing->first()->id : null)
                    ->required(),
                DatePicker::make('signed_on')
                    ->label(__('Signed on'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: waiver signature simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                // Re-checked server-side: the submitted type must still be an
                // active gating type this member actually lacks valid
                // paperwork for -- a forged id can't create an arbitrary row.
                $type = $this->gatedPaperworkTypesMissing($member)->firstWhere('id', (int) $data['paperwork_type_id']);
                abort_unless($type, 403);

                $member->paperwork()->create([
                    'paperwork_type_id' => $type->id,
                    'signed_on' => $data['signed_on'],
                    'recorded_by' => auth()->id(),
                ]);

                Notification::make()->title(__(':name recorded', ['name' => $type->name]))->success()->send();
            });
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
    // note on this below).
    /**
     * @return array<string, string>
     */
    private function paymentMethodOptions(?RegisterShift $openShift): array
    {
        if ($openShift) {
            return PaymentMethod::options();
        }

        $cashCodes = PaymentMethod::cashCodes();

        return collect(PaymentMethod::options())
            ->reject(fn (string $label, string $code) => $cashCodes->contains($code))
            ->all();
    }

    public function openShiftAction(): Action
    {
        return Action::make('openShift')
            ->label(__('Open Box'))
            ->schema([
                TextInput::make('opening_count')
                    ->label(__('Opening count'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(MembershipSetting::current()->default_opening_float)
                    ->required(),
            ])
            ->visible(fn (): bool => $this->getCurrentRegister() && ! $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $register = $this->getCurrentRegister();
                abort_unless($register, 404);

                app(RegisterShiftService::class)->openShift($register, auth()->user(), (float) $data['opening_count']);

                Notification::make()->title(__('Box opened'))->success()->send();
            });
    }

    public function recordDropAction(): Action
    {
        return Action::make('recordDrop')
            ->label(__('Record a drop'))
            ->schema([
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('reason')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordDrop($shift, auth()->user(), (float) $data['amount'], $data['reason'] ?? null);

                Notification::make()->title(__('Drop recorded'))->success()->send();
            });
    }

    // Cash (or other payment) taken at the register for something that never
    // touches Attendance/Subscription at all -- a vendor payment, a private
    // rental, a donation. notation is required free text rather than a fixed
    // reason enum, since these are inherently one-off. See
    // MiscellaneousPayment / RegisterShiftService::recordMiscPayment().
    public function recordMiscPaymentAction(): Action
    {
        return Action::make('recordMiscPayment')
            ->label(__('Record other payment'))
            ->schema([
                Select::make('payment_method')
                    ->options(PaymentMethod::options())
                    ->required(),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('notation')
                    ->label(__('What was this for?'))
                    ->required()
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordMiscPayment(
                    $shift,
                    auth()->user(),
                    (float) $data['amount'],
                    $data['payment_method'],
                    $data['notation'],
                );

                Notification::make()->title(__('Payment recorded'))->success()->send();
            });
    }

    public function closeShiftAction(): Action
    {
        return Action::make('closeShift')
            ->label(__('Close Box'))
            ->schema([
                TextInput::make('closing_count')
                    ->label(__('Final count'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required(),
                Textarea::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                $service = app(RegisterShiftService::class);
                $closed = $service->closeShift($shift, auth()->user(), (float) $data['closing_count'], $data['notes'] ?? null);
                $variance = $service->varianceCents($closed) ?? 0;
                $label = $variance === 0 ? __('exact') : ($variance > 0 ? __('over') : __('short'));

                Notification::make()->title(__('Box closed — :amount :label', ['amount' => $this->formatCurrency(Cents::toFloat(abs($variance))), 'label' => $label]))->success()->send();
            });
    }

    public function saveAndPromoteAction(): Action
    {
        return Action::make('saveAndPromote')
            ->label(__('Save & promote to Irregular'))
            ->schema([
                TextInput::make('preferred_name')->required()->maxLength(60),
                TextInput::make('first_name')->required()->maxLength(60),
                TextInput::make('last_name')->required()->maxLength(60),
                TextInput::make('email')->required()->email()->maxLength(120),
                Checkbox::make('appears_under_21')
                    ->label(__('Appears to be under :age', ['age' => MembershipSetting::current()->alcohol_flag_age]))
                    ->live(),
                DatePicker::make('dob')
                    ->label(__('Date of birth'))
                    ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                    ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
            ])
            // Member-only, not $this->getDecision() -- Capture doesn't depend on an
            // event, so this needs to be available the moment a member is selected,
            // before any event is picked (AdmissionPolicy::needsCapture()).
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsCapture($member))
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: promote to Irregular simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $irregular = Category::where('name', 'Irregular')->firstOrFail();

                $member->update([
                    'preferred_name' => $data['preferred_name'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'dob' => $data['dob'] ?? $member->dob,
                    'category_id' => $irregular->id,
                ]);

                Notification::make()->title(__('Member promoted to Irregular'))->success()->send();
            });
    }

    // A plain confirmation, not a data-collection form like
    // saveAndPromoteAction() -- staff have physically seen the waiver on
    // file, so this clears the flag and records a Standard Paperwork
    // signing (member_paperwork) as of today. AdmissionPolicy::
    // needsPaperworkCapture() decides visibility, member-only for the same
    // reason as saveAndPromoteAction().
    public function confirmPaperworkAction(): Action
    {
        return Action::make('confirmPaperwork')
            ->label(__('Confirm paperwork on file'))
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsPaperworkCapture($member))
            ->action(function (): void {
                if ($this->haltForTraining(__('Practice: paperwork confirmation simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                if ($standard = PaperworkType::where('name', 'Standard Paperwork')->first()) {
                    $member->paperwork()->create([
                        'paperwork_type_id' => $standard->id,
                        'signed_on' => today(),
                        'recorded_by' => auth()->id(),
                    ]);
                }

                $member->update(['missing_paperwork' => false]);

                Notification::make()->title(__('Paperwork confirmed'))->success()->send();
            });
    }

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
                    ->helperText(fn (Get $get): ?string => PaymentMethod::feeHelperText($get('payment_method'))),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: subscription purchase simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $addOn = AddOn::find($data['add_on_id']);
                abort_unless($addOn, 404);

                // Re-checked here, not just via the filtered Select options
                // above -- same defensive pattern as every other standalone
                // action in this app. Door can't reach the Settings page to
                // flip pool_enabled, so hiding the option alone isn't
                // enough. Entry is always purchasable.
                $purchasableIds = AddOn::subscribable()->get()->filter(fn (AddOn $a) => $a->isCurrentlyPurchasable())->pluck('id');
                abort_unless($addOn->id === AddOn::entry()->id || $purchasableIds->contains($addOn->id), 403);

                $months = (int) $data['duration_months'];
                $desiredStart = Carbon::parse($data['desired_start']);
                $paymentMethod = $data['payment_method'] ?? null;

                $rows = app(SubscriptionBundleService::class)->purchase(
                    $member,
                    $addOn,
                    $months,
                    $desiredStart,
                    auth()->user(),
                    $paymentMethod,
                    $this->getOpenShift(),
                );

                // Folded onto the first covered month's row rather than
                // tracked as its own ledger — one flat fee per transaction,
                // not per month, and this is the same "fold into the
                // existing column" choice already made for Event Add-Ons.
                // Deliberately never calls recordOneTimeMethodUsage() here:
                // a subscription/membership purchase is never one-time-
                // restricted, so there's nothing to stamp.
                $first = $rows->first();
                $transactionFee = $rows->sum('amount_paid') > 0 ? PaymentMethod::feeFor($paymentMethod) : 0.0;
                if ($transactionFee > 0) {
                    $first->update(['amount_paid' => $first->amount_paid + $transactionFee]);
                }

                $last = $rows->last();
                $rangeLabel = $first->covered_month->isSameMonth($last->covered_month)
                    ? $first->covered_month->translatedFormat('F Y')
                    : $first->covered_month->translatedFormat('F Y').' – '.$last->covered_month->translatedFormat('F Y');

                Notification::make()
                    ->title(__('Subscription recorded — :amount covering :range', ['amount' => $this->formatCurrency((float) $rows->sum('amount_paid')), 'range' => $rangeLabel]))
                    ->success()
                    ->send();
            });
    }

    // A one-time purchase covering a subscribable add-on for exactly one
    // event -- distinct from a subscription (a whole calendar month)
    // purchasable above via purchaseSubscriptionAction(). Only meaningful
    // for a priced_per_event add-on (Pool, at launch): a flat add-on's price
    // never varies by event, so a "day pass" for one would be identical to
    // just checking it at check-in. Not gated by isSubscriptionEligible()
    // -- that rule only applies where a `subscriptions` row gets created,
    // and this deliberately never creates one. Its own event Select, not
    // coupled to whatever event is currently selected on the page, so staff
    // can sell a pass for a different night while mid-transaction on
    // tonight's. See docs/BLUEPRINT.md "Fee pipeline".
    public function purchaseAddOnDayPassAction(): Action
    {
        $member = $this->getSelectedMember();
        $openShift = $this->getOpenShift();
        $lockedOneTimeCodes = $member?->hasUsedOneTimeMethod() ? PaymentMethod::oneTimeCodes()->all() : [];
        $dayPassableAddOns = AddOn::subscribable()->where('priced_per_event', true)->orderBy('sort_order')->get()
            ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
            // A member without valid paperwork for a gated add-on (e.g. a
            // lapsed Pool Waiver) can't buy a day pass for it -- a day pass
            // is pool use. Re-checked server-side in the closure too.
            ->filter(fn (AddOn $addOn) => $member && $member->canUseAddOn($addOn))
            // Not tied to the event currently selected on the page (see
            // above), but if literally no event anywhere -- today or
            // future -- has a nonzero fee for this add-on, there's nothing
            // to sell a pass for at all: the button would just open onto an
            // empty Event Select. Real desk report: tonight's event had a
            // $0 pool fee and staff were still offered "Buy Day Pass" with
            // no qualifying event behind it.
            ->filter(fn (AddOn $addOn) => static::addOnDayPassEventOptionsQuery($addOn)->exists());
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
                    ->options(function (Get $get) {
                        $addOn = $get('add_on_id') ? AddOn::find($get('add_on_id')) : null;
                        if (! $addOn) {
                            return [];
                        }

                        return static::addOnDayPassEventOptionsQuery($addOn)
                            ->orderBy('event_date')
                            ->get()
                            ->mapWithKeys(fn (Event $event) => [$event->id => "{$event->name} — {$event->event_date->translatedFormat('M j, Y')} ({$this->formatCurrency((float) $addOn->priceFor($event))})"]);
                    })
                    ->required()
                    ->searchable(),
                Select::make('payment_method')
                    ->live()
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true))
                    ->helperText(fn (Get $get): ?string => PaymentMethod::feeHelperText($get('payment_method'))),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: day pass simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $addOn = AddOn::find($data['add_on_id']);
                abort_unless($addOn, 404);
                // Re-checked here, not just via the filtered Select options
                // above -- same defense-in-depth as purchaseSubscriptionAction().
                $dayPassablePurchasableIds = AddOn::subscribable()->where('priced_per_event', true)->get()->filter(fn (AddOn $a) => $a->isCurrentlyPurchasable())->pluck('id');
                abort_unless($dayPassablePurchasableIds->contains($addOn->id), 403);
                // Paperwork gate (e.g. a valid Pool Waiver), re-checked
                // server-side -- a forged add_on_id can't route around the
                // filtered options above.
                abort_unless($member->canUseAddOn($addOn), 403);

                $event = Event::find($data['event_id']);
                abort_unless($event, 404);
                // The picker only offers unarchived events; a forged id
                // for an archived one stops here.
                abort_if($event->isArchived(), 403);

                $price = $addOn->priceFor($event);
                abort_unless($price !== null, 422);

                $paymentMethod = $data['payment_method'] ?? null;
                $transactionFee = $price > 0 ? PaymentMethod::feeFor($paymentMethod) : 0.0;

                try {
                    $pass = AddOnDayPass::create([
                        'member_id' => $member->id,
                        'event_id' => $event->id,
                        'add_on_id' => $addOn->id,
                        'amount_paid' => $price + $transactionFee,
                        'payment_method' => $paymentMethod,
                        'register_shift_id' => $this->getOpenShift()?->id,
                        'recorded_by' => auth()->id(),
                    ]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title(__(':username already has a :addon day pass for that event.', ['username' => $member->username, 'addon' => $addOn->name]))->danger()->send();

                    return;
                }

                if (in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true)) {
                    $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
                }

                Notification::make()
                    ->title(__(':addon day pass recorded — :amount for :event', ['addon' => $addOn->name, 'amount' => $this->formatCurrency((float) $pass->amount_paid), 'event' => $event->name]))
                    ->success()
                    ->send();
            });
    }

    public function registerGuestAction(): Action
    {
        $sponsor = $this->getSelectedMember();
        $attendance = $this->getExistingAttendance();

        return Action::make('registerGuest')
            ->label(__('Register a guest'))
            ->schema([
                TextInput::make('username')
                    ->required()
                    ->maxLength(60)
                    ->unique(table: 'members', column: 'username'),
                TextInput::make('preferred_name')->required()->maxLength(60),
                TextInput::make('first_name')->required()->maxLength(60),
                TextInput::make('last_name')->required()->maxLength(60),
                TextInput::make('email')->email()->required()->maxLength(120),
                Checkbox::make('appears_under_21')
                    ->label(__('Appears to be under :age', ['age' => MembershipSetting::current()->alcohol_flag_age]))
                    ->live(),
                DatePicker::make('dob')
                    ->label(__('Date of birth'))
                    ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                    ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
            ])
            ->visible(fn (): bool => $sponsor
                && $attendance?->checked_in_at
                && $sponsor->canSponsorGuests())
            ->action(function (array $data) use ($sponsor): void {
                if ($this->haltForTraining(__('Practice: guest registration simulated.'))) {
                    return;
                }

                abort_unless($sponsor && $sponsor->canSponsorGuests(), 403);

                $guestCategory = Category::where('name', 'Guest')->firstOrFail();
                $sponsorLabel = $sponsor->displayName();

                // The unique() rule above already checked at validation time —
                // this only catches the narrow race where two registers claim
                // the same username in the gap between that check and this
                // insert. Unlike member_number, a username collision here
                // isn't silently retried with a different value: it's manual
                // and required, so staff need to see it and pick another one.
                try {
                    $guest = Member::create([
                        'username' => $data['username'],
                        'preferred_name' => $data['preferred_name'],
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'email' => $data['email'],
                        'dob' => $data['dob'] ?? null,
                        'category_id' => $guestCategory->id,
                        'sponsor_id' => $sponsor->id,
                        'notes' => "Guest of {$sponsorLabel}.",
                    ]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title(__('That username was just taken — please choose another.'))->danger()->send();

                    return;
                }

                $this->form->fill([
                    'event_id' => $this->data['event_id'] ?? null,
                    'member_id' => $guest->id,
                ]);

                Notification::make()->title(__("Registered :guest as :sponsor's guest", ['guest' => "{$guest->first_name} {$guest->last_name}", 'sponsor' => $sponsor->username]))->success()->send();
            });
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
            ->label(__('Check in'))
            ->schema([
                ...($requiresAcknowledgement ? [
                    Checkbox::make('acknowledged')
                        ->label(__('I have notified :label per the watchlist note.', ['label' => config('membership.watchlist_notify_label')]))
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
                    ->helperText(fn (Get $get): ?string => PaymentMethod::feeHelperText($get('payment_method'))),
                TextInput::make('on_behalf_note')
                    ->label(__('On behalf of / guest note'))
                    ->maxLength(120),
                TextInput::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => ! $this->getExistingAttendance()
                && ! ($this->getDecision()?->blocksCheckIn() ?? true)
                && $this->getDecision()?->outcome !== AdmissionOutcome::Capture
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
                $this->pricingData = ['add_on_ids' => []];

                $title = __('Checked in — :amount due', ['amount' => $this->formatCurrency(Cents::toFloat($result->breakdown->amountPaidCents + $result->addOnTotalCents))]);
                if ($result->subscriptionTotalCents > 0) {
                    $title .= ' + '.__(':amount Subscription', ['amount' => $this->formatCurrency(Cents::toFloat($result->subscriptionTotalCents))]);
                }
                if ($result->addOnTotalCents > 0) {
                    $title .= ' + '.__(':amount Add-ons', ['amount' => $this->formatCurrency(Cents::toFloat($result->addOnTotalCents))]);
                }
                if ($result->voucherAppliedCents > 0) {
                    $title .= ' − '.__(':amount voucher', ['amount' => $this->formatCurrency(Cents::toFloat($result->voucherAppliedCents))]);
                }

                Notification::make()->title($title)->success()->send();
            });
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

    public function markArrivedAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $requiresAcknowledgement = $attendance
            && is_null($attendance->checked_in_at)
            && $this->getDecision()?->requiresAcknowledgement();

        return Action::make('markArrived')
            ->label(__('Mark arrived'))
            ->schema($requiresAcknowledgement ? [
                Checkbox::make('acknowledged')
                    ->label(__('I have notified :label per the watchlist note.', ['label' => config('membership.watchlist_notify_label')]))
                    ->accepted()
                    ->required(),
            ] : [])
            ->visible(fn (): bool => (bool) ($attendance && is_null($attendance->checked_in_at)))
            ->action(function (): void {
                if ($this->haltForTraining(__('Practice: marked arrived.'))) {
                    return;
                }

                $attendance = $this->getExistingAttendance();
                $member = $this->getSelectedMember();
                $event = $this->getSelectedEvent();
                abort_unless($attendance && $member && $event, 404);

                $decision = app(AdmissionPolicy::class)->decide($member, $event);

                if ($decision->blocksCheckIn()) {
                    Notification::make()
                        ->title($decision->message)
                        ->danger()
                        ->send();

                    return;
                }

                $attendance->update(['checked_in_at' => now()]);

                Notification::make()->title(__('Marked arrived'))->success()->send();
            });
    }

    // The reverse of ActivePatrons::departAction() -- a departed attendance
    // row drops off that page's own query entirely (whereNull('departed_at')),
    // so there's no undo surface there; this is where staff would naturally
    // notice someone's back anyway, since they'd search for the member here
    // the same way as any other arrival. Clears departed_at on the same row
    // rather than creating a new one -- they already paid for tonight, so
    // this is "still here," not a second visit. Same gate as Depart itself.
    public function markAsReturnedAction(): Action
    {
        $attendance = $this->getExistingAttendance();

        return Action::make('markAsReturned')
            ->label(__('Mark as returned'))
            ->color('success')
            ->visible(fn (): bool => (bool) ($attendance?->departed_at) && Gate::allows('record-departures'))
            ->action(function (): void {
                if ($this->haltForTraining(__('Practice: mark-as-returned simulated.'))) {
                    return;
                }

                // Re-checked here, not just via ->visible() -- same
                // defensive pattern as ActivePatrons::departAction() and
                // every other standalone action on this page.
                abort_unless(Gate::allows('record-departures'), 403);

                $attendance = $this->getExistingAttendance();
                abort_unless($attendance && $attendance->departed_at !== null, 404);

                $attendance->update(['departed_at' => null]);

                Notification::make()->title(__('Marked as returned'))->success()->send();
            });
    }

    /**
     * Back check-in: everyone still on the prepay list for the selected
     * event, with a one-click "mark arrived" per row — a second entry point
     * onto the same data as markArrivedAction(), for working through the
     * whole expected roster rather than one specific person at a time.
     */
    public function table(Table $table): Table
    {
        $event = $this->getSelectedEvent();

        return $table
            ->query(Attendance::query()->whereNull('checked_in_at')->where('event_id', $event?->id ?? 0))
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

    protected function markArrivedRowAction(): Action
    {
        return Action::make('markArrived')
            ->label(__('Mark arrived'))
            ->schema(function (Attendance $record) {
                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                return $decision->requiresAcknowledgement() ? [
                    Checkbox::make('acknowledged')
                        ->label(__('I have notified :label per the note.', ['label' => config('membership.watchlist_notify_label')]))
                        ->accepted()
                        ->required(),
                ] : [];
            })
            ->action(function (Attendance $record): void {
                if ($this->haltForTraining(__('Practice: marked arrived.'))) {
                    return;
                }

                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                if ($decision->blocksCheckIn()) {
                    Notification::make()->title($decision->message)->danger()->send();

                    return;
                }

                $record->update(['checked_in_at' => now()]);

                Notification::make()->title(__('Marked arrived'))->success()->send();
            });
    }

    protected static function defaultEventId(): ?int
    {
        $currentEventIds = static::currentEventQuery()->pluck('id');

        return $currentEventIds->count() === 1 ? $currentEventIds->first() : null;
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

    /**
     * No point selling a day pass for an event that's already ended, or one
     * with no price for this add-on at all. Reads event.pool_fee directly,
     * same limitation as AddOn::priceFor() — Pool is the only
     * priced_per_event add-on today.
     */
    protected static function addOnDayPassEventOptionsQuery(AddOn $addOn): Builder
    {
        return Event::currentOrFutureQuery()->where('pool_fee', '>', 0);
    }

    protected static function eventLabel(Event $event): string
    {
        return $event->label();
    }

    /**
     * The dropdown echoes back exactly the fields staff can search on
     * (Member::searchableColumns()) — username alone by default, more only
     * where the club has opted in. See Member::pickerLabel().
     */
    protected static function memberLabel(Member $member): string
    {
        return Member::pickerLabel($member);
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
