<x-filament-widgets::widget>
    @php($lineItems = $this->getLineItems())

    @if ($lineItems)
        <x-filament::section heading="Comp list cost">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="text-left">Reason</th>
                        <th scope="col" class="text-right">Arrived</th>
                        <th scope="col" class="text-right">Foregone</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lineItems as $lineItem)
                        <tr>
                            <td>{{ $lineItem->name }}</td>
                            <td class="text-right">{{ $lineItem->comps }}</td>
                            <td class="text-right">${{ number_format($lineItem->foregone, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-right">${{ number_format($lineItems->sum('foregone'), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
