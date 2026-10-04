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
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
