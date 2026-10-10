<?php

use App\Models\Event;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Services\PrepayCashService;
use App\Services\RegisterShiftService;
use Livewire\Attributes\Computed;
use Livewire\Component;

// Isolated from CheckIn's own Livewire component on purpose: this polls on its
// own clock to reflect drops/closes made from other terminals on the same
// shared cashbox. Polling used to live on the parent page, and Livewire
// cancels a same-component request that's in flight when an unrelated action
// (Open Box, Close Box, ...) is clicked, silently dropping the click. A
// separate component has its own message-bus scope, so its poll can never
// collide with a click on the parent page again.
new class extends Component
{
    public int $registerId;

    #[Computed]
    public function shift(): ?RegisterShift
    {
        $register = Register::find($this->registerId);

        return $register ? app(RegisterShiftService::class)->currentOpenShift($register) : null;
    }

    #[Computed]
    public function expected(): ?float
    {
        return $this->shift ? app(RegisterShiftService::class)->expectedClosingCount($this->shift) : null;
    }

    // All payment methods, not just cash -- a "what came in tonight" picture
    // for the desk, distinct from expected()'s box-reconciliation figure.
    #[Computed]
    public function breakdown(): ?array
    {
        return $this->shift ? app(RegisterShiftService::class)->revenueBreakdown($this->shift) : null;
    }

    // The non-cash part of breakdown(), per method -- counted in those
    // figures but never in the box.
    #[Computed]
    public function electronic(): array
    {
        return $this->shift ? app(RegisterShiftService::class)->electronicByMethodCents($this->shift) : [];
    }

    // Cash handed to instructors out of this box -- already taken off
    // expected(), shown so the lower figure explains itself.
    #[Computed]
    public function paidOutCents(): int
    {
        return $this->shift ? app(RegisterShiftService::class)->totalInstructorPayoutsCents($this->shift) : 0;
    }

    // Prepaid cash for later events taken on this shift -- also off
    // expected(), since it goes in each event's own envelope.
    #[Computed]
    public function prepaidAheadCents(): int
    {
        return $this->shift ? app(RegisterShiftService::class)->heldPrepayCashCents($this->shift) : 0;
    }

    // Tonight's side of the same thing: what was prepaid in cash for the
    // event(s) running now, held outside every box until tonight.
    #[Computed]
    public function heldForTonightCents(): int
    {
        return app(PrepayCashService::class)->heldCashCents(Event::currentQuery()->pluck('id')->all());
    }
};
?>

{{-- role="status" on each line so a poll-driven change (a drop or close from another
terminal sharing this cashbox) is announced to screen readers without staff needing to
re-find these lines after every refresh. --}}
<div wire:poll.10s>
    @if ($this->shift)
        <p role="status" class="text-sm text-gray-500">
            {{ __('Open since :time by :name — opened :opening, expected now :expected', [
                'time' => $this->shift->created_at->isoFormat('LT'),
                'name' => $this->shift->openedBy->name,
                'opening' => \App\Models\MembershipSetting::formatMoney($this->shift->opening_count),
                'expected' => \App\Models\MembershipSetting::formatMoney($this->expected),
            ]) }}
        </p>
        <p role="status" class="text-sm text-gray-500">
            {{ __('Event :event · Prepaid :prepay · Subscription :subscription · Other :other', [
                'event' => \App\Models\MembershipSetting::formatMoney($this->breakdown['event']),
                'prepay' => \App\Models\MembershipSetting::formatMoney($this->breakdown['prepay']),
                'subscription' => \App\Models\MembershipSetting::formatMoney($this->breakdown['subscription']),
                'other' => \App\Models\MembershipSetting::formatMoney($this->breakdown['other']),
            ]) }}
        </p>
        @if ($this->electronic !== [])
            <p role="status" class="text-sm text-gray-500">
                {{ __('Electronic, included above but not in the box: :methods', [
                    'methods' => collect($this->electronic)
                        ->map(fn (int $cents, string $label) => $label.' '.\App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($cents)))
                        ->implode(' · '),
                ]) }}
            </p>
        @endif
        @if ($this->prepaidAheadCents !== 0)
            <p role="status" class="text-sm text-gray-500">
                {{ __('Prepaid cash for later events :amount — in each event\'s prepay envelope, not this box', ['amount' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($this->prepaidAheadCents))]) }}
            </p>
        @endif
        @if ($this->paidOutCents !== 0)
            <p role="status" class="text-sm text-gray-500">
                {{ __('Paid out to instructors :amount', ['amount' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($this->paidOutCents))]) }}
            </p>
        @endif
    @endif
    @if ($this->heldForTonightCents !== 0)
        <p role="status" class="text-sm text-gray-500">
            {{ __('Prepaid cash held for tonight :amount — in the prepay envelope, not this box', ['amount' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($this->heldForTonightCents))]) }}
        </p>
    @endif
</div>
