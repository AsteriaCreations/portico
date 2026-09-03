<x-filament-widgets::widget>
    @php($result = $this->getResult())

    @if ($result)
        <x-filament::section heading="Instructor payout">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="text-left">Coverage</th>
                        <th scope="col" class="text-right">Count</th>
                        <th scope="col" class="text-right">Rate</th>
                        <th scope="col" class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->lineItems as $lineItem)
                        <tr>
                            <td>{{ $lineItem['source']->getLabel() }}</td>
                            <td class="text-right">{{ $lineItem['count'] }}</td>
                            <td class="text-right">${{ number_format($lineItem['rate'], 2) }}</td>
                            <td class="text-right">${{ number_format($lineItem['subtotal'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="3">Total</td>
                        <td class="text-right">${{ number_format($result->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
