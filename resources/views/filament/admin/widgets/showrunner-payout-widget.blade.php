<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section :heading="__('Showrunner payout')">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-gray-500">{{ __('Cash-paying attendees') }}</dt>
                <dd>{{ $result->cashCount }} ({{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->cashRevenueCents)) }})</dd>

                <dt class="text-gray-500">{{ __('Subscription-covered (SH) attendees') }}</dt>
                <dd>
                    {{ $result->shCount }} ({{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->shRevenueCents)) }})
                    &mdash;
                    @if ($result->includeSh)
                        {{ __('counts toward commission') }}
                    @else
                        {{ __("excluded (event entry fee doesn't exceed the subscription credit)") }}
                    @endif
                </dd>

                <dt class="text-gray-500">{{ __('Pool revenue') }}</dt>
                <dd>
                    {{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->poolRevenueCents)) }}
                    ({{ $this->getSettings()->showrunner_door_includes_pool ? __('included') : __('excluded') }})
                </dd>

                <dt class="text-gray-500">{{ __('Add-on revenue') }}</dt>
                <dd>
                    {{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->addonRevenueCents)) }}
                    ({{ $this->getSettings()->showrunner_door_includes_addons ? __('included') : __('excluded') }})
                </dd>

                <dt class="text-gray-500">{{ __('Headcount used for tier') }}</dt>
                <dd>{{ $result->headcount }}</dd>

                <dt class="text-gray-500">{{ __('Door total') }}</dt>
                <dd>{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->doorTotalCents)) }}</dd>

                <dt class="text-gray-500">{{ __('Tier') }}</dt>
                <dd>
                    @if ($result->tier)
                        {{ __(':range attendees', ['range' => $result->tier->min_headcount.($result->tier->max_headcount !== null ? '–'.$result->tier->max_headcount : '+')]) }}
                        &mdash;
                        @if ($result->tier->payout_type === \App\Enums\PayoutType::Voucher)
                            {{ __(':amount voucher', ['amount' => \App\Models\MembershipSetting::formatMoney($result->tier->payout_value)]) }}
                        @else
                            {{ __(':percent% of the door', ['percent' => number_format($result->tier->payout_value, 2)]) }}
                        @endif
                    @else
                        {{ __('No tier configured for this headcount') }}
                    @endif
                </dd>

                <dt class="text-gray-500">{{ __('Payout') }}</dt>
                <dd class="font-semibold">
                    @if ($result->payoutAmountCents !== null)
                        {{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->payoutAmountCents)) }}
                        @if ($result->tier?->payout_type === \App\Enums\PayoutType::Voucher)
                            &mdash; {{ __('issue manually via Vouchers') }}
                        @endif
                    @else
                        &mdash;
                    @endif
                </dd>
            </dl>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
