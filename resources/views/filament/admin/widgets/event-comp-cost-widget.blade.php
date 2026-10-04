<x-filament-widgets::widget>
    @php($lineItems = $this->getLineItems())

    @if ($lineItems)
        <x-filament::section :heading="__('Comp list cost')">
            <table class="w-full text-sm tabular-nums">
                <thead>
                    <tr>
                        <th scope="col" class="px-3 py-2 text-start">{{ __('Reason') }}</th>
                        <th scope="col" class="px-3 py-2 text-end">{{ __('Arrived') }}</th>
                        <th scope="col" class="px-3 py-2 text-end">{{ __('Foregone') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lineItems as $lineItem)
                        <tr>
                            <td class="px-3 py-2">{{ $lineItem->name }}</td>
                            <td class="px-3 py-2 text-end">{{ $lineItem->comps }}</td>
                            <td class="px-3 py-2 text-end">{{ \App\Models\MembershipSetting::formatMoney($lineItem->foregone) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="2" class="px-3 py-2">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-end">{{ \App\Models\MembershipSetting::formatMoney($lineItems->sum('foregone')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
