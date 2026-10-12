<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section :heading="__('Showrunner payout')">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-gray-500">{{ __('Paid at the door') }}</dt>
                <dd>{{ $result->cashCount }}</dd>

                <dt class="text-gray-500">{{ __('Subscription-covered (SH) attendees') }}</dt>
                <dd>
                    {{ $result->shCount }}
                    &mdash;
                    @if ($result->includeSh)
                        {{ __('counted') }}
                    @else
                        {{ __("not counted (event entry fee doesn't exceed the subscription credit)") }}
                    @endif
                </dd>

                <dt class="text-gray-500">{{ __('Headcount') }}</dt>
                <dd>{{ $result->headcount }} {{ __('(staff and other comped entries never count)') }}</dd>

                <dt class="text-gray-500">{{ __('Entry fee') }}</dt>
                <dd>{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->entryFeeCents)) }}</dd>

                @if ($result->voucherTier)
                    <dt class="text-gray-500">{{ __('Small event') }}</dt>
                    <dd>{{ __(':amount voucher', ['amount' => \App\Models\MembershipSetting::formatMoney($result->voucherTier->payout_value)]) }}</dd>
                @endif

                @foreach ($result->bands as $band)
                    <dt class="text-gray-500">
                        {{ __('Attendees :range at :percent%', [
                            'range' => $band->tier->min_headcount.($band->tier->max_headcount !== null ? '–'.$band->tier->max_headcount : '+'),
                            'percent' => rtrim(rtrim(number_format($band->tier->payout_value, 2), '0'), '.'),
                        ]) }}
                    </dt>
                    <dd>
                        {{ __(':count × :fee × :percent% = :amount', [
                            'count' => $band->attendees,
                            'fee' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->entryFeeCents)),
                            'percent' => rtrim(rtrim(number_format($band->tier->payout_value, 2), '0'), '.'),
                            'amount' => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($band->amountCents)),
                        ]) }}
                    </dd>
                @endforeach

                <dt class="text-gray-500">{{ __('Payout') }}</dt>
                <dd class="font-semibold">
                    @if ($result->payoutAmountCents !== null)
                        {{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->payoutAmountCents)) }}
                        @if ($result->voucherTier)
                            &mdash; {{ __('issue manually via Vouchers') }}
                        @endif
                    @else
                        {{ __('No payout tiers configured') }}
                    @endif
                </dd>
            </dl>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
