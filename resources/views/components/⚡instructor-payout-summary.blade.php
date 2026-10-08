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
     * @return Collection<int, array{event: Event, result: \App\Services\InstructorPayoutResult, history: list<array{payout: \App\Models\InstructorPayout, kind: string}>, netPaidCents: int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $service = app(InstructorPayoutService::class);

        return $service->currentEventsQuery()->get()->map(function (Event $event) use ($service): array {
            $history = $service->history($event);

            return [
                'event' => $event,
                'result' => $service->calculate($event),
                'history' => $history,
                'netPaidCents' => collect($history)->sum(fn (array $row): int => \App\Support\Cents::of($row['payout']->amount)),
            ];
        });
    }
};
?>

{{-- role="status" so a poll-driven change (another terminal checks someone in, or pays the
instructor) is announced without staff having to re-find the line. --}}
<div wire:poll.10s>
    @foreach ($this->rows as $row)
        @php($money = fn (int $cents): string => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($cents)))
        @php($people = collect($row['result']->lineItems)->sum('count'))
        <div role="status" class="text-sm">
            @foreach ($row['history'] as $entry)
                @php($payout = $entry['payout'])
                <p @class(['text-gray-500' => $row['netPaidCents'] === 0])>
                    <span @class(['font-semibold' => $row['netPaidCents'] !== 0])>{{ $entry['kind'] === 'payment'
                        ? __(':event instructor paid :amount at :time by :name', ['event' => $row['event']->name, 'amount' => $money(\App\Support\Cents::of($payout->amount)), 'time' => $payout->created_at->isoFormat('LT'), 'name' => $payout->recordedBy->name])
                        : __('Correction :amount at :time by :name', ['amount' => $money(\App\Support\Cents::of($payout->amount)), 'time' => $payout->created_at->isoFormat('LT'), 'name' => $payout->recordedBy->name]) }}</span>
                    @if ($entry['kind'] === 'payment' && \App\Support\Cents::of($payout->amount) !== \App\Support\Cents::of($payout->calculated_amount))
                        <span class="text-gray-500">{{ __('(calculated then: :amount)', ['amount' => $money(\App\Support\Cents::of($payout->calculated_amount))]) }}</span>
                    @endif
                    @if ($payout->notes)
                        <span class="text-gray-500">— {{ $payout->notes }}</span>
                    @endif
                </p>
            @endforeach

            @if (count($row['history']) > 1)
                <p class="font-semibold">
                    {{ $row['netPaidCents'] === 0
                        ? __('Net paid: :amount (reversed — not paid)', ['amount' => $money(0)])
                        : __('Net paid: :amount', ['amount' => $money($row['netPaidCents'])]) }}
                </p>
            @endif

            {{-- Nothing stands paid (never paid, or fully reversed): show what's owed so far. --}}
            @if ($row['netPaidCents'] === 0)
                <p>
                    <span class="font-semibold">{{ __(':event instructor payout so far: :amount', ['event' => $row['event']->name, 'amount' => $money($row['result']->totalCents)]) }}</span>
                    <span class="text-gray-500">{{ trans_choice('(:count person)|(:count people)', $people) }}</span>
                </p>
                @if ($row['result']->lineItems)
                    <p class="text-gray-500">
                        {{ collect($row['result']->lineItems)->map(fn ($line) => __(':source :count × :rate', ['source' => $line['source']->getLabel(), 'count' => $line['count'], 'rate' => $money($line['rateCents'])]))->implode(' · ') }}
                    </p>
                @endif
            @endif
        </div>
    @endforeach
</div>
