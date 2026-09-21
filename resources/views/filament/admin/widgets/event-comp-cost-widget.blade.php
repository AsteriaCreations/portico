<x-filament-widgets::widget>
    @php($lineItems = $this->getLineItems())

    @if ($lineItems)
        <x-filament::section :heading="__('Comp list cost')">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="text-left">{{ __('Reason') }}</th>
                        <th scope="col" class="text-right">{{ __('Arrived') }}</th>
                        <th scope="col" class="text-right">{{ __('Foregone') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lineItems as $lineItem)
                        <tr>
                            <td>{{ $lineItem->name }}</td>
                            <td class="text-right">{{ $lineItem->comps }}</td>
                            <td class="text-right">{{ \App\Models\MembershipSetting::formatMoney($lineItem->foregone) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="2">{{ __('Total') }}</td>
                        <td class="text-right">{{ \App\Models\MembershipSetting::formatMoney($lineItems->sum('foregone')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
