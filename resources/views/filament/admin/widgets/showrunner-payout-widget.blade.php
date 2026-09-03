<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section heading="Showrunner payout">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-gray-500">Cash-paying attendees</dt>
                <dd>{{ $result->cashCount }} (${{ number_format($result->cashRevenue, 2) }})</dd>

                <dt class="text-gray-500">Subscription-covered (SH) attendees</dt>
                <dd>
                    {{ $result->shCount }} (${{ number_format($result->shRevenue, 2) }})
                    &mdash;
                    @if ($result->includeSh)
                        counts toward commission
                    @else
                        excluded (event entry fee doesn't exceed the subscription credit)
                    @endif
                </dd>

                <dt class="text-gray-500">Pool revenue</dt>
                <dd>
                    ${{ number_format($result->poolRevenue, 2) }}
                    ({{ $this->getSettings()->showrunner_door_includes_pool ? 'included' : 'excluded' }})
                </dd>

                <dt class="text-gray-500">Add-on revenue</dt>
                <dd>
                    ${{ number_format($result->addonRevenue, 2) }}
                    ({{ $this->getSettings()->showrunner_door_includes_addons ? 'included' : 'excluded' }})
                </dd>

                <dt class="text-gray-500">Headcount used for tier</dt>
                <dd>{{ $result->headcount }}</dd>

                <dt class="text-gray-500">Door total</dt>
                <dd>${{ number_format($result->doorTotal, 2) }}</dd>

                <dt class="text-gray-500">Tier</dt>
                <dd>
                    @if ($result->tier)
                        {{ $result->tier->min_headcount }}{{ $result->tier->max_headcount !== null ? '–'.$result->tier->max_headcount : '+' }} attendees
                        &mdash;
                        @if ($result->tier->payout_type === \App\Enums\PayoutType::Voucher)
                            ${{ number_format($result->tier->payout_value, 2) }} voucher
                        @else
                            {{ number_format($result->tier->payout_value, 2) }}% of the door
                        @endif
                    @else
                        No tier configured for this headcount
                    @endif
                </dd>

                <dt class="text-gray-500">Payout</dt>
                <dd class="font-semibold">
                    @if ($result->payoutAmount !== null)
                        ${{ number_format($result->payoutAmount, 2) }}
                        @if ($result->tier?->payout_type === \App\Enums\PayoutType::Voucher)
                            &mdash; issue manually via Vouchers
                        @endif
                    @else
                        &mdash;
                    @endif
                </dd>
            </dl>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
