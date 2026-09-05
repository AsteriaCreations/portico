<x-filament-panels::page>
    {{-- Filament's own page layout skips rendering this for any page implementing HasTable,
    assuming the table's own view provides it instead (vendor/filament/filament/resources/views
    /components/page/index.blade.php). That assumption breaks here: this page's table only
    renders inside the "@if ($event)" block below, since it's a back-check-in roster for
    tonight's event, not an always-present resource list. With no event selected, neither copy
    of the modals placeholder existed anywhere on the page, so every action (Open/Close Box,
    checkIn, ...) silently failed to show its modal -- the server response was always correct,
    there was just nothing listening for it client-side. Rendering it here unconditionally
    fixes that; Filament's own hasActionsModalRendered guard makes it a harmless no-op on the
    rare render where the table's own copy also shows up. --}}
    <x-filament-actions::modals />

    <x-screen-instructions title="How to check someone in">
        <p>1. Search for the <strong>member</strong> first, by username, name, or member number. Their status (banned, watchlist, subscription eligibility, sign-up steps needed) shows immediately — you don't need an event picked yet.</p>
        <p>2. Pick tonight's <strong>event</strong> to see what's due and any comp/voucher/subscription options for it.</p>
        <p>3. If a watchlist note asks for a staff-channel message, send it first, then check the acknowledgement box — you can't proceed without it.</p>
        <p>4. Review the <strong>Due</strong> total, then click <strong>Check in</strong> to record payment and admit them.</p>
        <p>Buying a subscription or a one-night day pass doesn't require an event to be selected — those are separate, standalone transactions. Which add-ons offer a subscription or day pass depends on how this club's catalog is configured.</p>
    </x-screen-instructions>

    {{-- No wire:poll here on purpose: this whole section shares one Livewire message-bus
    scope with the page's own actions (Open/Close Box, record a drop). A poll request in
    flight when an action is clicked gets Livewire's own request cancelled in favor of the
    click -- silently dropping it. The "expected now" total still needs to live-refresh from
    other terminals sharing the same cashbox, so that one number is pulled out into an
    isolated register-box-summary component with its own poll and its own scope, which can
    never again collide with a click here. --}}
    @if (\App\Models\MembershipSetting::current()->register_shifts_enabled)
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <label for="registerId" class="text-sm font-medium">Register</label>
                    <select
                        id="registerId"
                        wire:model.live="registerId"
                        class="fi-input block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">— none —</option>
                        @foreach ($this->getRegisterOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($this->getCurrentRegister())
                    @php
                        $openShift = $this->getOpenShift();
                    @endphp

                    @if ($openShift)
                        {{-- Distinct wire:key from the closed-state branch below: without one, Livewire's
                        morph can reuse this branch's DOM node in place of the bare action button rendered
                        when there's no open shift (and vice versa on the next close), leaving the "new"
                        button's click handler wired to stale Alpine/action state so it silently no-ops. --}}
                        <div wire:key="register-shift-open-{{ $openShift->id }}" class="flex flex-wrap items-center gap-4">
                            <livewire:register-box-summary :register-id="$this->registerId" :key="'register-box-summary-'.$this->registerId" />
                            <div class="flex gap-2">
                                {{ $this->recordDropAction }}
                                {{ $this->recordMiscPaymentAction }}
                                {{ $this->closeShiftAction }}
                            </div>
                        </div>
                    @else
                        <div wire:key="register-shift-closed-{{ $this->getCurrentRegister()->id }}">
                            {{ $this->openShiftAction }}
                        </div>
                    @endif
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->form }}

    @php
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();
    @endphp

    {{-- Member-only: everything here derives from the member alone (raw status
    flags, subscription eligibility, Prospective identity-capture) and its own
    actions (save & promote, buy subscription) genuinely don't need an event -- see
    docs/BLUEPRINT.md "Check-in desk flow". Admission's actual
    per-event outcome (ban exceptions, age relative to the event) and anything
    that touches an attendance row still need $event too, below. --}}
    @if ($member)
        <x-filament::section>
            <p class="font-medium">{{ $member->preferred_name ?: $member->username }}</p>
            <p class="text-sm text-gray-500">{{ $member->category->name }}</p>

            @if ($member->is_deceased)
                <p class="mt-2 text-danger-600">Deceased — blocks admission everywhere.</p>
            @endif

            @if ($member->is_banned)
                <p class="mt-2 text-danger-600">Banned</p>
                @if ($member->ban_reason && $this->canSeeReason())
                    <p class="text-sm text-gray-500">{{ $member->ban_reason }}</p>
                @endif
            @endif

            @if ($member->on_watchlist)
                <p class="mt-2 text-danger-600">On watchlist</p>
                @if ($member->watchlist_reason && $this->canSeeReason())
                    <p class="text-sm text-gray-500">{{ $member->watchlist_reason }}</p>
                @endif
            @endif

            @if ($member->isSubscriptionEligible())
                <div class="mt-4">
                    {{ $this->purchaseSubscriptionAction }}
                </div>
            @else
                @php
                    $attendedCount = $member->attendance()->whereNotNull('checked_in_at')->count();
                    $subscriptionThreshold = \App\Models\MembershipSetting::current()->subscription_eligibility_threshold;
                @endphp
                <p class="text-sm text-gray-500">Not yet subscription-eligible ({{ $attendedCount }}/{{ $subscriptionThreshold }} events attended)</p>
            @endif

            {{-- Unlike Buy Subscription above, not gated by isSubscriptionEligible() --
            a one-time add-on day pass is revenue, not a membership perk. --}}
            <div class="mt-4">
                {{ $this->purchaseAddOnDayPassAction }}
            </div>

            @if (app(\App\Services\AdmissionPolicy::class)->needsCapture($member))
                <p class="mt-4 font-medium">Prospective — complete sign-up to promote to Irregular.</p>
                <div class="mt-2">
                    {{ $this->saveAndPromoteAction }}
                </div>
            @endif

            {{-- Independent of the Prospective capture block above -- both can
            show at once if a member somehow has both gaps. --}}
            @if (app(\App\Services\AdmissionPolicy::class)->needsPaperworkCapture($member))
                <p class="mt-4 font-medium">Missing paperwork — confirm on file before check-in.</p>
                <div class="mt-2">
                    {{ $this->confirmPaperworkAction }}
                </div>
            @endif

            {{-- A gating waiver (e.g. Pool Waiver) is missing/expired: the
            add-on is dropped from pricing entirely, so staff need to know why
            and that the member must not be admitted to it. If they sign it
            now, staff record it here and the add-on returns to pricing. --}}
            @foreach ($this->getGatedAddOnWarnings() as $gatedWarning)
                <p class="mt-4 text-danger-600">{{ $gatedWarning }}</p>
            @endforeach
            <div class="mt-2">
                {{ $this->recordGatedPaperworkAction }}
            </div>
        </x-filament::section>
    @endif

    @if ($member && $event)
        @php
            $attendance = $this->getExistingAttendance();
            $decision = $this->getDecision();
        @endphp

        @if ($attendance && $attendance->checked_in_at)
            <x-filament::section>
                <p class="font-medium text-success-600">
                    Checked in at {{ $attendance->checked_in_at->format('g:i A') }} — paid ${{ number_format($attendance->amount_paid, 2) }}
                </p>

                @if ($member->isOnProbation())
                    <p class="mt-2 text-sm text-gray-500">On probation — cannot bring a guest yet.</p>
                @else
                    <div class="mt-4">
                        {{ $this->registerGuestAction }}
                    </div>
                @endif
            </x-filament::section>
        @elseif ($attendance)
            <x-filament::section>
                <p class="font-medium">Prepaid — not yet arrived. Paid ${{ number_format($attendance->amount_paid, 2) }}.</p>

                @if ($decision?->blocksCheckIn())
                    <p class="mt-2 text-danger-600">{{ $decision->message }}</p>
                    @if ($decision->reason && $this->canSeeReason())
                        <p class="text-sm text-gray-500">{{ $decision->reason }}</p>
                    @endif
                @else
                    <div class="mt-4">
                        {{ $this->markArrivedAction }}
                    </div>
                @endif
            </x-filament::section>
        @elseif ($decision)
            <x-filament::section>
                <p class="font-medium">{{ $decision->message }}</p>

                @if ($decision->reason && $this->canSeeReason())
                    <p class="text-sm text-gray-500">{{ $decision->reason }}</p>
                @endif

                @if ($decision->outcome === \App\Enums\AdmissionOutcome::Capture)
                    {{-- Handled above, in the member-only section -- neither
                    saveAndPromoteAction nor confirmPaperworkAction need an
                    event, so whichever applies is already visible there. --}}
                @elseif (! $decision->blocksCheckIn())
                    @php
                        $hasRoom = app(\App\Services\CapacityService::class)->hasRoom($event->event_date);
                    @endphp

                    {{-- Subscription/comp/voucher choices, live -- see the pricingData property
                    comment on CheckIn.php. The Due line below reacts to every change here. --}}
                    <div class="mt-4">
                        {{ $this->pricingForm }}
                    </div>

                    {{-- role="status" (implicit aria-live="polite" + aria-atomic) so this
                    announces itself to screen readers as pricingForm() selections above
                    change it, without staff needing to re-navigate to it after every toggle. --}}
                    <p role="status" class="mt-2 text-lg font-semibold">Due: ${{ number_format($this->getLivePriceBreakdown()->amountPaid + $this->getLiveAddOnTotal(), 2) }}</p>

                    @if ($hasRoom)
                        <div class="mt-4">
                            {{ $this->checkInAction }}
                        </div>
                    @else
                        <p class="mt-2 text-danger-600">
                            At capacity ({{ $this->getOccupancy() }}/{{ $this->getCapacity() }}) — no new walk-in check-ins until someone leaves.
                        </p>
                    @endif
                @endif
            </x-filament::section>
        @endif
    @endif

    @if ($event)
        {{ $this->getTable()->render() }}

        {{-- Isolated component, not a wire:poll section here -- see the comment above the
        register-box section for why sharing this page's own Livewire scope with a poll
        breaks action clicks. --}}
        <livewire:checked-in-roster :event-id="$event->id" :key="'checked-in-roster-'.$event->id" />
    @endif
</x-filament-panels::page>
