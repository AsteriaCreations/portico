<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section :heading="__('Instructor payout')">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="text-left">{{ __('Coverage') }}</th>
                        <th scope="col" class="text-right">{{ __('Count') }}</th>
                        <th scope="col" class="text-right">{{ __('Rate') }}</th>
                        <th scope="col" class="text-right">{{ __('Subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->lineItems as $lineItem)
                        <tr>
                            <td>{{ $lineItem['source']->getLabel() }}</td>
                            <td class="text-right">{{ $lineItem['count'] }}</td>
                            <td class="text-right">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($lineItem['rateCents'])) }}</td>
                            <td class="text-right">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($lineItem['subtotalCents'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="3">{{ __('Total') }}</td>
                        <td class="text-right">{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($result->totalCents)) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
