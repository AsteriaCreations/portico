<?php

use App\Models\Register;
use App\Models\RegisterShift;
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
};
?>

{{-- role="status" on each line so a poll-driven change (a drop or close from another
terminal sharing this cashbox) is announced to screen readers without staff needing to
re-find these lines after every refresh. --}}
<div wire:poll.10s>
    @if ($this->shift)
        <p role="status" class="text-sm text-gray-500">
            Open since {{ $this->shift->created_at->format('g:i A') }} by {{ $this->shift->openedBy->name }} —
            opened ${{ number_format($this->shift->opening_count, 2) }},
            expected now ${{ number_format($this->expected, 2) }}
        </p>
        <p role="status" class="text-sm text-gray-500">
            Event ${{ number_format($this->breakdown['event'], 2) }} ·
            Subscription ${{ number_format($this->breakdown['subscription'], 2) }} ·
            Other ${{ number_format($this->breakdown['other'], 2) }}
        </p>
    @endif
</div>
