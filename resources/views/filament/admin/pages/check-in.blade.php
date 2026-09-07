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

    {{-- Training mode: every write action on this page short-circuits while
    it's on (CheckIn::haltForTraining()), so a new volunteer can rehearse the
    whole flow with nothing persisted. Inline CSS variables, not Tailwind
    bg-warning-* utilities -- those aren't in this panel theme's compiled CSS,
    same convention as the status strip and register picker below. --}}
    @if ($this->trainingMode)
        <div
            role="status"
            class="rounded-xl px-5 py-4"
            style="border-left: 5px solid var(--warning-600, #d97706); background-color: color-mix(in srgb, var(--warning-600, #d97706) 12%, transparent);"
        >
            <p class="text-base font-semibold" style="color: var(--warning-600, #d97706);">
                Training mode — practice freely. Nothing you do here is saved.
            </p>
            <p class="mt-1 text-sm" style="opacity: .75;">
                The screen behaves exactly as normal — status line, Due total, confirmations —
                but no records are created, so nothing appears on the roster afterward.
                Use the <strong>Exit training mode</strong> button above for real check-ins.
            </p>
        </div>
    @endif

    <x-screen-instructions title="How to check someone in">
        <p>1. Search for the <strong>member</strong> first, by {{ $this->memberSearchFieldsLabel() }}.</p>
        <p>2. Read the <strong>status line</strong> — green means go, amber means do one thing first, red means stop and get a manager. It shows before you pick an event.</p>
        <p>3. Pick tonight's <strong>event</strong> to see what's due.</p>
        <p>4. Take payment for the amount on the <strong>Due</strong> line, then click <strong>Check in</strong>.</p>
        <p>If a watchlist note asks for a staff-channel message, send it first, then tick the acknowledgement — you can't proceed without it.</p>
        <p>Everything folded away — selling a subscription or day pass, add-ons / vouchers / comps, the cash box — is still here, one click open, and never needed for a normal check-in.</p>
    </x-screen-instructions>

    {{-- No wire:poll here on purpose: this whole section shares one Livewire message-bus
    scope with the page's own actions (Open/Close Box, record a drop). A poll request in
    flight when an action is clicked gets Livewire's own request cancelled in favor of the
    click -- silently dropping it. The "expected now" total still needs to live-refresh from
    other terminals sharing the same cashbox, so that one number is pulled out into an
    isolated register-box-summary component with its own poll and its own scope, which can
    never again collide with a click here. --}}
    @if (\App\Models\MembershipSetting::current()->register_shifts_enabled)
        {{-- This register picker is a hand-rolled <select> (Filament's page
        schema doesn't offer a bare select, and id="registerId" has to stay
        stable for the register_shifts_enabled visibility tests), so it needs
        its own dark-mode colours -- the dark: Tailwind utilities on it don't
        resolve in this panel's compiled CSS, same gap the status strip hit.
        color-scheme also switches the native dropdown popup. --}}
        <style>
            #registerId {
                color-scheme: light;
                background-color: var(--gray-50, #f9fafb);
                color: var(--gray-950, #030712);
                border-color: var(--gray-300, #d1d5db);
            }
            .dark #registerId {
                color-scheme: dark;
                background-color: var(--gray-800, #1f2937);
                color: var(--gray-100, #f3f4f6);
                border-color: var(--gray-600, #4b5563);
            }
        </style>
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <label for="registerId" class="text-sm font-medium">Register</label>
                    <select
                        id="registerId"
                        wire:model.live="registerId"
                        class="fi-input block rounded-lg border text-sm shadow-sm"
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
                        </div>
                    @else
                        <div wire:key="register-shift-closed-{{ $this->getCurrentRegister()->id }}">
                            {{ $this->openShiftAction }}
                        </div>
                    @endif
                @endif
            </div>

            {{-- Once a shift is open, only the box summary stays on the check-in screen;
            drops, other payments, and closing the box are one click away in here -- a
            start/end-of-night task, not a per-member one. --}}
            @if ($this->getCurrentRegister() && $this->getOpenShift())
                <x-desk-fold class="mt-4" title="Cash box" summary="Record a drop, take an off-book payment, or close the box">
                    <div class="flex flex-wrap gap-2">
                        {{ $this->recordDropAction }}
                        {{ $this->recordMiscPaymentAction }}
                        {{ $this->closeShiftAction }}
                    </div>
                </x-desk-fold>
            @endif
        </x-filament::section>
    @endif

    {{ $this->form }}

    @php
        $member = $this->getSelectedMember();
        $event = $this->getSelectedEvent();
        $strip = $this->statusStrip();
    @endphp

    {{-- The one thing a volunteer has to read. Colour and headline carry the
    decision; the raw flags stay underneath as supporting detail. Pre-event
    it mirrors AdmissionPolicy's own priority order; once an event is picked
    it relabels the real AdmissionDecision. See CheckIn::statusStrip(). --}}
    @if ($strip)
        @php
            // Filament's semantic palette CSS vars (with hex fallbacks) --
            // the Tailwind colour utilities (text-danger-600, bg-*-50, ...)
            // aren't in this build's compiled CSS, so colour is set inline.
            $toneColor = [
                'go' => 'var(--success-600, #16a34a)',
                'check' => 'var(--warning-600, #d97706)',
                'stop' => 'var(--danger-600, #dc2626)',
            ][$strip['tone']];
        @endphp
        {{-- wire:key keyed on the member/event/verdict: without it, morphdom
        drifts this bare conditional <div> against the neighbouring conditional
        sections when the Member select changes, and the strip keeps showing the
        previous member's verdict until some other action forces a full render. --}}
        <div
            wire:key="status-strip-{{ $member?->id }}-{{ $event?->id }}-{{ $strip['tone'] }}"
            role="status"
            class="rounded-xl px-5 py-4"
            style="border-left: 5px solid {{ $toneColor }}; background-color: color-mix(in srgb, {{ $toneColor }} 10%, transparent);"
        >
            <p class="text-base" style="color: {{ $toneColor }}; font-weight: 600;">{{ $strip['headline'] }}</p>
            @if ($strip['detail'])
                <p class="mt-1 text-sm" style="opacity: .75;">{{ $strip['detail'] }}</p>
            @endif
            @if (filled($strip['flags']))
                <ul class="mt-2 space-y-0.5 text-sm" style="opacity: .75;">
                    @foreach ($strip['flags'] as $flag)
                        <li>{{ $flag }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Member-only: name, the sign-up prompts that block admission until
    they're done (these stay inline, never folded), and -- folded away --
    the standalone subscription / day-pass sale. Everything here derives
    from the member alone; the per-event outcome is handled below once an
    event is picked. See docs/BLUEPRINT.md "Check-in desk flow". --}}
    @if ($member)
        <x-filament::section>
            <p class="font-medium">{{ $member->preferred_name ?: $member->username }}</p>
            <p class="text-sm text-gray-500">{{ $member->category->name }}</p>

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

            {{-- Folded: standalone transactions, not part of a normal check-in.
            Buying a subscription or day pass here doesn't require an event to
            be selected. --}}
            <x-desk-fold class="mt-4" title="Sell a subscription or day pass" summary="Standalone — no event or check-in needed">
                @if ($member->isSubscriptionEligible())
                    <div>
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
                a one-time add-on day pass is revenue, not a membership perk.
                Only rendered when actually buyable -- otherwise Filament shows
                it disabled, which reads as broken; the note below explains why
                it's absent. --}}
                @if ($this->purchaseAddOnDayPassAction->isVisible())
                    <div>
                        {{ $this->purchaseAddOnDayPassAction }}
                    </div>
                @endif

                {{-- A day pass for a waiver-gated add-on (Pool) isn't offered
                until the signature is on file -- say so, rather than just
                showing nothing where the button would be. --}}
                @foreach ($this->getDayPassPaperworkNotes() as $note)
                    <p class="text-sm" style="opacity: .7;">{{ $note }}</p>
                @endforeach
            </x-desk-fold>
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
                    {{-- The status line above already says "Do not admit"; markArrived
                    is withheld so a blocked prepay can't be waved through. --}}
                @else
                    <div class="mt-4">
                        {{ $this->markArrivedAction }}
                    </div>
                @endif
            </x-filament::section>
        @elseif ($decision && ! $decision->blocksCheckIn() && $decision->outcome !== \App\Enums\AdmissionOutcome::Capture)
            {{-- A blocked member or one needing sign-up is fully covered by the
            status line and the member-only prompts above -- no section here. --}}
            <x-filament::section>
                @php
                    $hasRoom = app(\App\Services\CapacityService::class)->hasRoom($event->event_date);
                @endphp

                {{-- Folded: adjustments to what's owed. Closed by default so a
                normal walk-in is just Due + Check in. The Due line below stays
                outside the fold and reacts live to anything changed in here. --}}
                <x-desk-fold title="Payment options" summary="Subscription, add-ons, voucher, comp">
                    {{ $this->pricingForm }}
                </x-desk-fold>

                {{-- role="status" (implicit aria-live="polite" + aria-atomic) so this
                announces itself to screen readers as pricingForm() selections
                change it, without staff needing to re-navigate to it after every toggle. --}}
                <p role="status" class="mt-3 text-xl font-bold">Due: ${{ number_format($this->getLivePriceBreakdown()->amountPaid + $this->getLiveAddOnTotal(), 2) }}</p>

                @if ($hasRoom)
                    <div class="mt-4">
                        {{ $this->checkInAction }}
                    </div>
                @else
                    <p class="mt-2 text-danger-600">
                        At capacity ({{ $this->getOccupancy() }}/{{ $this->getCapacity() }}) — no new walk-in check-ins until someone leaves.
                    </p>
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
