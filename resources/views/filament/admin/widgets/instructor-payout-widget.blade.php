<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section :heading="__('Instructor payout')">
            <table class="w-full text-sm tabular-nums">
                <thead>
                    <tr>
                        <th scope="col" class="px-3 py-2 text-start">{{ __('Coverage') }}</th>
                        <th scope="col" class="px-3 py-2 text-end">{{ __('Count') }}</th>
                        <th scope="col" class="px-3 py-2 text-end">{{ __('Rate') }}</th>
                        <th scope="col" class="px-3 py-2 text-end">{{ __('Subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->lineItems as $lineItem)
                        <tr>
                            <td class="px-3 py-2">{{ $lineItem['source']->getLabel() }}</td>
                            <td class="px-3 py-2 text-end">{{ $lineItem['count'] }}</td>
                            <td class="px-3 py-2 text-end">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($lineItem['rateCents'])) }}</td>
                            <td class="px-3 py-2 text-end">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($lineItem['subtotalCents'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="3" class="px-3 py-2">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-end">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->totalCents)) }}</td>
                    </tr>
                </tfoot>
            </table>

            @php($history = $this->getHistory())
            @php($netPaidCents = collect($history)->sum(fn (array $row): int => \App\Support\Cents::of($row['payout']->amount)))
            <div class="mt-3 px-3 text-sm">
                @forelse ($history as $entry)
                    @php($payout = $entry['payout'])
                    <p>
                        {{ $entry['kind'] === 'payment'
                            ? __('Paid at the desk: :amount by :name, :time', ['amount' => \App\Models\MembershipSetting::formatMoney($payout->amount), 'name' => $payout->recordedBy->name, 'time' => $payout->created_at->isoFormat('lll')])
                            : __('Correction: :amount by :name, :time', ['amount' => \App\Models\MembershipSetting::formatMoney($payout->amount), 'name' => $payout->recordedBy->name, 'time' => $payout->created_at->isoFormat('lll')]) }}
                        @if ($entry['kind'] === 'payment' && \App\Support\Cents::of($payout->amount) !== \App\Support\Cents::of($payout->calculated_amount))
                            <span class="text-gray-500">{{ __('(calculated then: :amount)', ['amount' => \App\Models\MembershipSetting::formatMoney($payout->calculated_amount)]) }}</span>
                        @endif
                        @if ($payout->notes)
                            <span class="text-gray-500">— {{ $payout->notes }}</span>
                        @endif
                    </p>
                @empty
                    <p class="text-gray-500">{{ __('Not paid at the desk yet.') }}</p>
                @endforelse

                @if (count($history) > 1)
                    <p class="font-semibold">
                        {{ $netPaidCents === 0
                            ? __('Net paid: :amount (reversed — not paid)', ['amount' => \App\Models\MembershipSetting::formatMoney(0)])
                            : __('Net paid: :amount', ['amount' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($netPaidCents))]) }}
                    </p>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
