<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdmissionOutcome;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
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
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\RegisterShiftService;
use App\Services\SubscriptionBundleService;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckIn extends Page implements HasTable
{
    use InteractsWithTable;

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
    }

    // Sticky per user: fires automatically off wire:model.live="registerId"
    // in the Blade view, so the next visit to this page pre-selects it.
    public function updatedRegisterId(?int $value): void
    {
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
                    ->label('Member')
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $member) => [$member->id => static::memberLabel($member)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($member = Member::find($value)) ? static::memberLabel($member) : null)
                    ->required(),
                Select::make('event_id')
                    ->label('Event')
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
                    $payer->voucherBalance(),
                    (float) ($this->pricingData['voucher_amount'] ?? 0),
                );
            }
        }

        return $breakdown;
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

        return (float) AddOn::whereIn('id', static::normalizeAddOnIds($this->pricingData['add_on_ids'] ?? null))
            ->where('active', true)
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
        $regularOptions = ($member && $event && $eligible) ? $this->subscriptionOptions(AddOn::entry(), $member, $event) : ['none' => 'No subscription payment'];
        // One Select per subscribable add-on (Pool, at launch) rather than a
        // hardcoded pair of fields -- a club that flags a second add-on
        // subscribable gets a duration picker for it with no code change.
        $addOnSubscriptionSelects = AddOn::subscribable()->orderBy('sort_order')->get()
            ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
            ->map(function (AddOn $addOn) use ($member, $event, $eligible, $canPreviewPricing) {
                $options = ($member && $event && $eligible) ? $this->subscriptionOptions($addOn, $member, $event) : ['none' => 'No subscription payment'];

                return Select::make("subscription_addon_{$addOn->id}_duration")
                    ->label("{$addOn->name} Subscription")
                    ->live()
                    ->options($options)
                    ->default('none')
                    ->visible($canPreviewPricing && $eligible && count($options) > 1);
            })
            ->values()
            ->all();
        $canGrantComp = Gate::allows('grant-event-comp');
        $entryFee = $this->getPriceBreakdown()?->entryFee ?? 0.0;
        $ownBalance = $member?->voucherBalance() ?? 0.0;
        $voucherLabel = $member
            ? "Apply voucher credit — \${$this->formatCurrency($ownBalance)} available on {$member->preferred_name}'s account"
            : 'Apply voucher credit';

        return $schema
            ->statePath('pricingData')
            ->components([
                CheckboxList::make('add_on_ids')
                    ->label('Add-ons')
                    // Subscribable add-ons (Pool) are never in this list --
                    // they're priced automatically via PricingService
                    // whenever the event has a price for them, the same
                    // "no checkbox needed" behavior pool_fee always had.
                    // Only flat, non-subscribable extras are opt-in here.
                    ->options(fn () => AddOn::where('active', true)
                        ->where('subscribable', false)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (AddOn $addOn) => [
                            $addOn->id => $addOn->name.' — $'.number_format($addOn->price, 2)
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
                    ->label('Regular Subscription')
                    ->live()
                    ->options($regularOptions)
                    ->default('none')
                    ->visible($canPreviewPricing && $eligible && count($regularOptions) > 1),
                ...$addOnSubscriptionSelects,
                Checkbox::make('comp_entry')
                    ->label('Comp this entry (e.g. worked the event)')
                    ->live()
                    ->visible($canPreviewPricing && $canGrantComp && $entryFee > 0),
                Select::make('comp_reason_id')
                    ->label('Reason')
                    ->options(fn () => CompReason::where('active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(fn (Get $get): bool => (bool) $get('comp_entry'))
                    ->visible(fn (Get $get): bool => $canPreviewPricing && $canGrantComp && (bool) $get('comp_entry')),
                Checkbox::make('apply_voucher')
                    ->label($voucherLabel)
                    ->live()
                    ->visible($canPreviewPricing && $member && MembershipSetting::current()->vouchers_enabled && ($this->getLivePriceBreakdownBeforeVoucher()?->amountPaid ?? 0) > 0),
                Select::make('voucher_payer_id')
                    ->label("Apply from a different member's balance (optional)")
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchMembers($search)
                        ->mapWithKeys(fn (Member $payer) => [$payer->id => static::memberLabel($payer).' — $'.$this->formatCurrency($payer->voucherBalance()).' available'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($payer = Member::find($value)) ? static::memberLabel($payer) : null)
                    ->helperText("Leave blank to use {$member?->preferred_name}'s own balance.")
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                TextInput::make('voucher_amount')
                    ->label('Voucher amount to apply')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->default(min($ownBalance, $this->getLivePriceBreakdownBeforeVoucher()?->amountPaid ?? 0) ?: null)
                    ->required(fn (Get $get): bool => (bool) $get('apply_voucher'))
                    ->helperText('Capped automatically at the balance available and what\'s still due — a partial amount is fine.')
                    ->visible(fn (Get $get): bool => $canPreviewPricing && (bool) $get('apply_voucher')),
                TextInput::make('voucher_reason')
                    ->label('Reason (required — kept on the ledger)')
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
            $flags[] = 'On watchlist'.($showReason && $member->watchlist_reason ? ' — '.$member->watchlist_reason : '');
        }
        if ($policy->needsCapture($member)) {
            $flags[] = 'Prospective — sign-up incomplete';
        }
        if ($policy->needsPaperworkCapture($member)) {
            $flags[] = 'Paperwork not confirmed';
        }

        $event = $this->getSelectedEvent();

        if (! $event) {
            $provisional = match (true) {
                $member->is_deceased, $member->isCurrentlyBanned() => ['stop', 'Do not admit'],
                $member->on_watchlist => ['check', 'Watchlist — notify '.config('membership.watchlist_notify_label').', then confirm at check-in'],
                $policy->needsCapture($member) => ['check', 'Prospective — finish sign-up to admit'],
                $policy->needsPaperworkCapture($member) => ['check', 'Missing paperwork — confirm on file to admit'],
                default => ['go', "No flags yet — pick tonight's event"],
            };

            return ['tone' => $provisional[0], 'headline' => $provisional[1], 'detail' => null, 'flags' => $flags];
        }

        $decision = $this->getDecision();

        // detail carries the actionable instruction only; the ban/watchlist
        // reason already appears in $flags below (reason-gated), so it's not
        // re-appended here.
        return match ($decision->outcome) {
            AdmissionOutcome::Block => ['tone' => 'stop', 'headline' => 'Do not admit', 'detail' => null, 'flags' => $flags],
            AdmissionOutcome::Warn => ['tone' => 'check', 'headline' => 'Acknowledge before admitting', 'detail' => $decision->message, 'flags' => $flags],
            AdmissionOutcome::Capture => ['tone' => 'check', 'headline' => 'Finish sign-up to admit', 'detail' => $decision->message, 'flags' => $flags],
            AdmissionOutcome::Flag => ['tone' => 'check', 'headline' => 'Check ID — under 21, no alcohol, mark hand', 'detail' => null, 'flags' => $flags],
            AdmissionOutcome::Ok => ['tone' => 'go', 'headline' => 'Ready to admit', 'detail' => null, 'flags' => $flags],
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
     * @return Collection<int, PaperworkType>
     */
    public function gatedPaperworkTypesMissing(?Member $member): Collection
    {
        if (! $member) {
            return collect();
        }

        return PaperworkType::query()
            ->where('active', true)
            ->whereNotNull('gates_add_on_id')
            ->with('addOn')
            ->get()
            ->reject(fn (PaperworkType $type) => ! $type->addOn || $member->hasValidPaperwork($type))
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
            ->map(fn (PaperworkType $type) => "{$type->addOn->name} unavailable — {$type->name} missing or expired. Not charged; do not admit to {$type->addOn->name} until it is renewed.")
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
            ->map(fn (PaperworkType $type) => "{$type->addOn->name} day pass — requires a signed {$type->name} first. Record the signature above to enable it.")
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
            ->label('Record a waiver signature')
            ->visible(fn (): bool => $missing->isNotEmpty())
            ->schema([
                Select::make('paperwork_type_id')
                    ->label('Waiver')
                    ->options($missing->pluck('name', 'id')->all())
                    ->default($missing->count() === 1 ? $missing->first()->id : null)
                    ->required(),
                DatePicker::make('signed_on')
                    ->label('Signed on')
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
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

                Notification::make()->title("{$type->name} recorded")->success()->send();
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
            ->label('Open Box')
            ->schema([
                TextInput::make('opening_count')
                    ->label('Opening count')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(MembershipSetting::current()->default_opening_float)
                    ->required(),
            ])
            ->visible(fn (): bool => $this->getCurrentRegister() && ! $this->getOpenShift())
            ->action(function (array $data): void {
                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $register = $this->getCurrentRegister();
                abort_unless($register, 404);

                app(RegisterShiftService::class)->openShift($register, auth()->user(), (float) $data['opening_count']);

                Notification::make()->title('Box opened')->success()->send();
            });
    }

    public function recordDropAction(): Action
    {
        return Action::make('recordDrop')
            ->label('Record a drop')
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
                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordDrop($shift, auth()->user(), (float) $data['amount'], $data['reason'] ?? null);

                Notification::make()->title('Drop recorded')->success()->send();
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
            ->label('Record other payment')
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
                    ->label('What was this for?')
                    ->required()
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
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

                Notification::make()->title('Payment recorded')->success()->send();
            });
    }

    public function closeShiftAction(): Action
    {
        return Action::make('closeShift')
            ->label('Close Box')
            ->schema([
                TextInput::make('closing_count')
                    ->label('Final count')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required(),
                Textarea::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                $service = app(RegisterShiftService::class);
                $closed = $service->closeShift($shift, auth()->user(), (float) $data['closing_count'], $data['notes'] ?? null);
                $variance = $service->variance($closed) ?? 0.0;
                $label = $variance == 0.0 ? 'exact' : ($variance > 0 ? 'over' : 'short');

                Notification::make()->title('Box closed — $'.number_format(abs($variance), 2)." {$label}")->success()->send();
            });
    }

    public function saveAndPromoteAction(): Action
    {
        return Action::make('saveAndPromote')
            ->label('Save & promote to Irregular')
            ->schema([
                TextInput::make('first_name')->required()->maxLength(60),
                TextInput::make('last_name')->required()->maxLength(60),
                TextInput::make('email')->required()->email()->maxLength(120),
                Checkbox::make('appears_under_21')
                    ->label('Appears to be under 21')
                    ->live(),
                DatePicker::make('dob')
                    ->label('Date of birth')
                    ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                    ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
            ])
            // Member-only, not $this->getDecision() -- Capture doesn't depend on an
            // event, so this needs to be available the moment a member is selected,
            // before any event is picked (AdmissionPolicy::needsCapture()).
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsCapture($member))
            ->action(function (array $data): void {
                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $irregular = Category::where('name', 'Irregular')->firstOrFail();

                $member->update([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'dob' => $data['dob'] ?? $member->dob,
                    'category_id' => $irregular->id,
                ]);

                Notification::make()->title('Member promoted to Irregular')->success()->send();
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
            ->label('Confirm paperwork on file')
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsPaperworkCapture($member))
            ->action(function (): void {
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

                Notification::make()->title('Paperwork confirmed')->success()->send();
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
        $lockedOneTimeCodes = $member?->hasUsedOneTimeMethod() ? PaymentMethod::oneTimeCodes()->all() : [];

        return Action::make('purchaseSubscription')
            ->label('Buy Subscription')
            ->visible(fn (): bool => (bool) $member?->isSubscriptionEligible())
            ->schema([
                Select::make('add_on_id')
                    ->label('Plan')
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

                        return Plan::currentOptionsFor($addOn, now())
                            ->mapWithKeys(fn (Plan $plan) => [$plan->duration_months => "{$plan->duration_months} month(s) — \$".number_format($plan->price, 2)])
                            ->all();
                    })
                    ->required(),
                Select::make('payment_method')
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true)),
            ])
            ->action(function (array $data): void {
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

                if (in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true)) {
                    $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
                }

                $first = $rows->first();
                $last = $rows->last();
                $rangeLabel = $first->covered_month->isSameMonth($last->covered_month)
                    ? $first->covered_month->format('F Y')
                    : $first->covered_month->format('F Y').' – '.$last->covered_month->format('F Y');

                Notification::make()
                    ->title('Subscription recorded — $'.number_format((float) $rows->sum('amount_paid'), 2)." covering {$rangeLabel}")
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
            ->filter(fn (AddOn $addOn) => $member && $member->canUseAddOn($addOn));
        $defaultAddOnId = $dayPassableAddOns->count() === 1 ? $dayPassableAddOns->first()->id : null;

        return Action::make('purchaseAddOnDayPass')
            ->label('Buy Day Pass')
            ->visible(fn (): bool => (bool) $member && $dayPassableAddOns->isNotEmpty())
            ->schema([
                Select::make('add_on_id')
                    ->label('Add-on')
                    ->options($dayPassableAddOns->pluck('name', 'id')->all())
                    ->default($defaultAddOnId)
                    ->required()
                    ->live(),
                Select::make('event_id')
                    ->label('Event')
                    ->options(function (Get $get) {
                        $addOn = $get('add_on_id') ? AddOn::find($get('add_on_id')) : null;
                        if (! $addOn) {
                            return [];
                        }

                        return static::addOnDayPassEventOptionsQuery($addOn)
                            ->orderBy('event_date')
                            ->get()
                            ->mapWithKeys(fn (Event $event) => [$event->id => "{$event->name} — {$event->event_date->toFormattedDateString()} (\$".number_format((float) $addOn->priceFor($event), 2).')']);
                    })
                    ->required()
                    ->searchable(),
                Select::make('payment_method')
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true)),
            ])
            ->action(function (array $data): void {
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

                $price = $addOn->priceFor($event);
                abort_unless($price !== null, 422);

                $paymentMethod = $data['payment_method'] ?? null;

                try {
                    $pass = AddOnDayPass::create([
                        'member_id' => $member->id,
                        'event_id' => $event->id,
                        'add_on_id' => $addOn->id,
                        'amount_paid' => $price,
                        'payment_method' => $paymentMethod,
                        'register_shift_id' => $this->getOpenShift()?->id,
                        'recorded_by' => auth()->id(),
                    ]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title("{$member->username} already has a {$addOn->name} day pass for that event.")->danger()->send();

                    return;
                }

                if (in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true)) {
                    $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
                }

                Notification::make()
                    ->title("{$addOn->name} day pass recorded — \$".number_format((float) $pass->amount_paid, 2)." for {$event->name}")
                    ->success()
                    ->send();
            });
    }

    public function registerGuestAction(): Action
    {
        $sponsor = $this->getSelectedMember();
        $attendance = $this->getExistingAttendance();

        return Action::make('registerGuest')
            ->label('Register a guest')
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
                    ->label('Appears to be under 21')
                    ->live(),
                DatePicker::make('dob')
                    ->label('Date of birth')
                    ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                    ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
            ])
            ->visible(fn (): bool => $sponsor
                && $attendance?->checked_in_at
                && ! $sponsor->isOnProbation())
            ->action(function (array $data) use ($sponsor): void {
                abort_unless($sponsor && ! $sponsor->isOnProbation(), 403);

                $guestCategory = Category::where('name', 'Guest')->firstOrFail();
                $sponsorLabel = $sponsor->preferred_name ?: $sponsor->username;

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

                    Notification::make()->title('That username was just taken — please choose another.')->danger()->send();

                    return;
                }

                $this->form->fill([
                    'event_id' => $this->data['event_id'] ?? null,
                    'member_id' => $guest->id,
                ]);

                Notification::make()->title("Registered {$guest->first_name} {$guest->last_name} as {$sponsor->username}'s guest")->success()->send();
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
        $options = ['none' => 'No subscription payment'];
        $eventMonth = $event->event_date->clone()->startOfMonth();

        $monthlyPlan = Plan::currentFor($addOn, $event->event_date);
        if ($monthlyPlan && ! $member->hasActiveSubscriptionFor($addOn, $eventMonth)) {
            $options[1] = 'This month — $'.number_format($monthlyPlan->price, 2);
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
                ? $resolution->start->format('F Y')
                : $resolution->start->format('F Y').' – '.$endMonth->format('F Y');

            $label = "{$plan->duration_months} months — \$".number_format($plan->price, 2)." ({$rangeLabel})";

            if (! empty($resolution->skippedMonths)) {
                $skippedLabel = collect($resolution->skippedMonths)->map(fn ($m) => $m->format('F Y'))->implode(', ');
                $label .= " — {$skippedLabel} already covered";
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
            ->label('Check in')
            ->schema([
                ...($requiresAcknowledgement ? [
                    Checkbox::make('acknowledged')
                        ->label('I have notified the staff channel per the watchlist note.')
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
                    ->options(collect($this->paymentMethodOptions($openShift))
                        ->map(fn (string $label, string $code) => in_array($code, $lockedOneTimeCodes, true) ? "{$label} (cannot use — already used)" : $label)
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $lockedOneTimeCodes, true)),
                TextInput::make('on_behalf_note')
                    ->label('On behalf of / guest note')
                    ->maxLength(120),
                TextInput::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => ! $this->getExistingAttendance()
                && ! ($this->getDecision()?->blocksCheckIn() ?? true)
                && $this->getDecision()?->outcome !== AdmissionOutcome::Capture
                && ($event === null || app(CapacityService::class)->hasRoom($event->event_date)))
            ->action(function (array $data): void {
                $member = $this->getSelectedMember();
                $event = $this->getSelectedEvent();
                abort_unless($member && $event, 404);

                // getSelectedEvent() does a raw Event::find() with no relation
                // to eventSelectQuery()'s own dropdown filtering -- a forged
                // event_id for a non-active (prepay-only) event must still be
                // rejected once prepay is disabled, even though the picker
                // itself already stopped offering it. A currently-active
                // event is never affected either way.
                abort_unless($event->isCurrentlyActive() || MembershipSetting::current()->prepay_enabled, 403);

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
                        ->title('Already checked in — another register just recorded this.')
                        ->warning()
                        ->send();

                    return;
                }

                $decision = app(AdmissionPolicy::class)->decide($member, $event);
                abort_if($decision->blocksCheckIn(), 403);
                abort_unless(app(CapacityService::class)->hasRoom($event->event_date), 403);

                // Re-fetched at submit time, not render time. A forged "cash"
                // submission with no register actually open never reaches
                // this point at all — the schema's own options() closure is
                // rebuilt fresh on every submit, and Filament validates the
                // Select's value against it, rejecting the whole submission
                // before the action() closure runs.
                $openShift = $this->getOpenShift();
                $paymentMethod = $data['payment_method'] ?? null;
                $pricingData = $this->pricingData;

                // Wrapped in a transaction so a race that slips past the
                // check above — two submissions landing close enough that
                // both saw no existing row — fails atomically on the
                // database's own unique constraint rather than leaving a
                // half-written subscription purchase with no attendance row behind.
                try {
                    [$attendance, $breakdown, $subscriptionTotal, $voucherApplied, $addOnTotal] = DB::transaction(function () use ($member, $event, $data, $pricingData, $openShift, $paymentMethod): array {
                        $month = $event->event_date->clone()->startOfMonth();
                        $subscriptionTotal = 0.0;

                        // Filtered to currently-purchasable add-ons (Pool
                        // excluded while pool_enabled is off) -- looping
                        // only over what remains is itself the defense-in-
                        // depth against a forged subscription_addon_{id}_
                        // duration field for a now-disabled add-on, same as
                        // the day-pass/subscription actions above re-
                        // checking the same fresh query. Pricing itself
                        // (below, via PricingService::price()) is
                        // unaffected by this filter -- an event that
                        // already has a pool fee still charges for it,
                        // and an existing subscription still covers it,
                        // regardless of whether new ones can be bought.
                        $targets = collect([['addOn' => AddOn::entry(), 'field' => 'subscription_regular_duration']])
                            ->merge(AddOn::subscribable()->get()
                                ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable())
                                ->map(fn (AddOn $addOn) => ['addOn' => $addOn, 'field' => "subscription_addon_{$addOn->id}_duration"]));

                        foreach ($targets as ['addOn' => $addOn, 'field' => $field]) {
                            $selected = $pricingData[$field] ?? 'none';
                            if ($selected === 'none' || $selected === null || ! $member->isSubscriptionEligible()) {
                                continue;
                            }

                            $months = (int) $selected;

                            if ($months <= 1) {
                                // Unchanged from before bundles existed: a single-month
                                // purchase never shifts to a different month — if
                                // this exact month is already covered, it's just skipped.
                                // (Deliberately NOT routed through
                                // SubscriptionBundleService::purchase() even though it
                                // now handles months=1 correctly price-wise: purchase()
                                // always calls resolveStart(), which SHIFTS forward to
                                // the next free month on a conflict rather than skipping
                                // -- fine for an explicit multi-month bundle purchase,
                                // wrong here, where a stale/forged '1' selection for an
                                // already-covered month must silently no-op, not buy a
                                // different month than what was on screen.)
                                if ($member->hasActiveSubscriptionFor($addOn, $month)) {
                                    continue;
                                }
                                // Priced as of today, not the event's date -- matches
                                // SubscriptionBundleService::purchase()'s own "always
                                // priced as of today" rule, since this is a payment
                                // happening now regardless of which (possibly future,
                                // door-prepay) event is selected.
                                $plan = Plan::currentFor($addOn, now());
                                if (! $plan) {
                                    continue;
                                }
                                Subscription::create([
                                    'member_id' => $member->id,
                                    'add_on_id' => $addOn->id,
                                    'covered_month' => $month->toDateString(),
                                    'amount_paid' => $plan->price,
                                    'paid_on' => now(),
                                    'recorded_by' => auth()->id(),
                                    'payment_method' => $paymentMethod,
                                    'register_shift_id' => $openShift?->id,
                                ]);
                                $subscriptionTotal += (float) $plan->price;

                                continue;
                            }

                            $bundleRows = app(SubscriptionBundleService::class)->purchase(
                                $member,
                                $addOn,
                                $months,
                                $month,
                                auth()->user(),
                                $paymentMethod,
                                $openShift,
                            );
                            $subscriptionTotal += (float) $bundleRows->sum('amount_paid');
                        }

                        $breakdown = app(PricingService::class)->price($member, $event);

                        // Re-fetched server-side, never trusted from the
                        // submitted names/prices — same defense-in-depth as
                        // everywhere else in this closure. A flat add-on
                        // never goes through PricingService: it's a plain
                        // addition to amount_paid, not comped or voucher-
                        // covered. Also re-checked against add_ons_enabled
                        // -- a forged selection from a session where the
                        // field was hidden must be silently ignored, not
                        // honored.
                        $selectedAddOns = MembershipSetting::current()->add_ons_enabled
                            ? AddOn::whereIn('id', static::normalizeAddOnIds($pricingData['add_on_ids'] ?? null))->where('active', true)->where('subscribable', false)->get()
                            : collect();
                        $addOnTotal = (float) $selectedAddOns->sum('price');

                        $compReasonId = null;
                        if (($pricingData['comp_entry'] ?? false) && Gate::allows('grant-event-comp')) {
                            $breakdown = app(PricingService::class)->applyEventComp($breakdown);
                            $compReasonId = $pricingData['comp_reason_id'] ?? null;
                        }

                        $voucherApplied = 0.0;
                        $voucherPayer = null;
                        if (($pricingData['apply_voucher'] ?? false) && MembershipSetting::current()->vouchers_enabled) {
                            $voucherPayerId = ! empty($pricingData['voucher_payer_id']) ? $pricingData['voucher_payer_id'] : $member->id;

                            // Locked for the rest of this transaction: two check-ins
                            // drawing from the same payer's balance at once must not
                            // both read the pre-spend balance before either commits,
                            // or the ledger can go negative with no error raised
                            // (voucherBalance() is a live SUM with no DB constraint
                            // against it going negative).
                            $voucherPayer = Member::where('id', $voucherPayerId)->lockForUpdate()->first();

                            if ($voucherPayer) {
                                $breakdown = app(PricingService::class)->applyVoucher(
                                    $breakdown,
                                    $voucherPayer->voucherBalance(),
                                    (float) ($pricingData['voucher_amount'] ?? 0),
                                );
                                $voucherApplied = $breakdown->voucherCoverage;
                            }
                        }

                        $attendance = Attendance::create([
                            'member_id' => $member->id,
                            'event_id' => $event->id,
                            'checked_in_by' => auth()->id(),
                            // Re-derived from the event, not trusted from the
                            // submission -- a forged checked_in_at for a
                            // future prepay-only event is silently ignored
                            // server-side, the same defense-in-depth as the
                            // payment_method re-check above.
                            'checked_in_at' => $event->isCurrentlyActive() ? ($data['checked_in_at'] ?? now()) : null,
                            'payment_method' => $paymentMethod,
                            'register_shift_id' => $openShift?->id,
                            'on_behalf_note' => $data['on_behalf_note'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'comp_reason_id' => $compReasonId,
                            ...$breakdown->toAttendanceAttributes(),
                            // Overrides the breakdown's own amount_paid so
                            // add-ons land in the same column the register
                            // reconciliation already sums (RegisterShiftService::
                            // cashReceived()/revenueBreakdown()) — no changes
                            // needed there.
                            'amount_paid' => $breakdown->amountPaid + $addOnTotal,
                        ]);

                        foreach ($selectedAddOns as $addOn) {
                            AttendanceAddOn::create([
                                'attendance_id' => $attendance->id,
                                'add_on_id' => $addOn->id,
                                'name' => $addOn->name,
                                'price' => $addOn->price,
                                'is_overnight' => $addOn->is_overnight,
                            ]);
                        }

                        // One row per subscribable add-on priced for this
                        // event (Pool, at launch) -- coverage already
                        // resolved by PricingService::build() above.
                        foreach ($breakdown->addOnAttendanceRows() as $row) {
                            AttendanceAddOn::create(['attendance_id' => $attendance->id, ...$row]);
                        }

                        if (in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true)) {
                            $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
                        }

                        if ($voucherApplied > 0 && $voucherPayer) {
                            Voucher::create([
                                'member_id' => $voucherPayer->id,
                                'amount' => -$voucherApplied,
                                'reason' => $pricingData['voucher_reason'] ?? '',
                                'attendance_id' => $attendance->id,
                                'recorded_by' => auth()->id(),
                            ]);
                        }

                        return [$attendance, $breakdown, $subscriptionTotal, $voucherApplied, $addOnTotal];
                    });
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()
                        ->title('Already checked in — another register just recorded this at the same moment.')
                        ->warning()
                        ->send();

                    return;
                }

                // Cleared so the next transaction (same member, or the next
                // one after registerGuestAction re-selects a new guest) never
                // inherits this one's comp/voucher/subscription choices.
                $this->pricingData = ['add_on_ids' => []];

                $title = 'Checked in — $'.number_format($breakdown->amountPaid + $addOnTotal, 2).' due';
                if ($subscriptionTotal > 0) {
                    $title .= ' + $'.number_format($subscriptionTotal, 2).' Subscription';
                }
                if ($addOnTotal > 0) {
                    $title .= ' + $'.number_format($addOnTotal, 2).' Add-ons';
                }
                if ($voucherApplied > 0) {
                    $title .= ' − $'.number_format($voucherApplied, 2).' voucher';
                }

                Notification::make()->title($title)->success()->send();
            });
    }

    protected function formatCurrency(float $amount): string
    {
        return number_format($amount, 2);
    }

    public function markArrivedAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $requiresAcknowledgement = $attendance
            && is_null($attendance->checked_in_at)
            && $this->getDecision()?->requiresAcknowledgement();

        return Action::make('markArrived')
            ->label('Mark arrived')
            ->schema($requiresAcknowledgement ? [
                Checkbox::make('acknowledged')
                    ->label('I have notified the staff channel per the watchlist note.')
                    ->accepted()
                    ->required(),
            ] : [])
            ->visible(fn (): bool => (bool) ($attendance && is_null($attendance->checked_in_at)))
            ->action(function (): void {
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

                Notification::make()->title('Marked arrived')->success()->send();
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
            ->heading('Prepaid, awaiting arrival')
            ->columns([
                TextColumn::make('member.username')->label('Member'),
                TextColumn::make('amount_paid')->money(),
                TextColumn::make('notes'),
            ])
            ->recordActions([$this->markArrivedRowAction()]);
    }

    protected function markArrivedRowAction(): Action
    {
        return Action::make('markArrived')
            ->label('Mark arrived')
            ->schema(function (Attendance $record) {
                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                return $decision->requiresAcknowledgement() ? [
                    Checkbox::make('acknowledged')
                        ->label('I have notified the staff channel per the note.')
                        ->accepted()
                        ->required(),
                ] : [];
            })
            ->action(function (Attendance $record): void {
                $decision = app(AdmissionPolicy::class)->decide($record->member, $record->event);

                if ($decision->blocksCheckIn()) {
                    Notification::make()->title($decision->message)->danger()->send();

                    return;
                }

                $record->update(['checked_in_at' => now()]);

                Notification::make()->title('Marked arrived')->success()->send();
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
     * desk can take a walk-in prepayment for it.
     */
    protected static function eventSelectQuery(): Builder
    {
        return MembershipSetting::current()->prepay_enabled
            ? static::currentEventQuery()->orWhere('door_prepay_enabled', true)
            : static::currentEventQuery();
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
        return $event->event_date->toFormattedDateString().' — '.($event->name ?? 'Untitled event');
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
