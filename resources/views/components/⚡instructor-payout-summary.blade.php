<?php

use App\Models\Event;
use App\Services\InstructorPayoutService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

// Isolated and self-polling for the same reason as register-box-summary: the
// running payout moves with every check-in on any terminal, and a poll on the
// parent CheckIn page would cancel in-flight clicks there. Read-only -- paying
// happens through CheckIn::payInstructorAction(). The parent only renders this
// while instructor_payouts_enabled is on.
new class extends Component
{
    /**
     * @return Collection<int, array{event: Event, result: \App\Services\InstructorPayoutResult, payouts: Collection}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $service = app(InstructorPayoutService::class);

        return $service->currentEventsQuery()
            ->with(['instructorPayouts' => fn ($query) => $query->with('recordedBy')->orderBy('id')])
            ->get()
            ->map(fn (Event $event) => [
                'event' => $event,
                'result' => $service->calculate($event),
                'payouts' => $event->instructorPayouts,
            ]);
    }
};
?>

{{-- role="status" so a poll-driven change (another terminal checks someone in, or pays the
instructor) is announced without staff having to re-find the line. --}}
<div wire:poll.10s>
    @foreach ($this->rows as $row)
        @php
            $money = fn ($cents) => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($cents));
            $people = collect($row['result']->lineItems)->sum('count');
        @endphp
        <div role="status" class="text-sm">
            @if ($row['payouts']->isEmpty())
                <p>
                    <span class="font-semibold">{{ __(':event instructor payout so far: :amount', ['event' => $row['event']->name, 'amount' => $money($row['result']->totalCents)]) }}</span>
                    <span class="text-gray-500">{{ trans_choice('(:count person)|(:count people)', $people) }}</span>
                </p>
                @if ($row['result']->lineItems)
                    <p class="text-gray-500">
                        {{ collect($row['result']->lineItems)->map(fn ($line) => __(':source :count × :rate', ['source' => $line['source']->getLabel(), 'count' => $line['count'], 'rate' => $money($line['rateCents'])]))->implode(' · ') }}
                    </p>
                @endif
            @else
                @foreach ($row['payouts'] as $payout)
                    <p>
                        <span class="font-semibold">{{ $loop->first
                            ? __(':event instructor paid :amount at :time by :name', ['event' => $row['event']->name, 'amount' => \App\Models\MembershipSetting::formatMoney($payout->amount), 'time' => $payout->created_at->isoFormat('LT'), 'name' => $payout->recordedBy->name])
                            : __('Correction :amount at :time by :name', ['amount' => \App\Models\MembershipSetting::formatMoney($payout->amount), 'time' => $payout->created_at->isoFormat('LT'), 'name' => $payout->recordedBy->name]) }}</span>
                        @if ($loop->first && \App\Support\Cents::of($payout->amount) !== \App\Support\Cents::of($payout->calculated_amount))
                            <span class="text-gray-500">{{ __('(calculated then: :amount)', ['amount' => \App\Models\MembershipSetting::formatMoney($payout->calculated_amount)]) }}</span>
                        @endif
                        @if ($payout->notes)
                            <span class="text-gray-500">— {{ $payout->notes }}</span>
                        @endif
                    </p>
                @endforeach
            @endif
        </div>
    @endforeach
</div>
