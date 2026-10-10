{{-- Body of the Register Shifts list's variance peek (RegisterShiftsTable). $math is RegisterShiftService::varianceMathCents(). --}}
@php
    $money = fn (int $cents): string => \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($cents));
    $lines = [
        [__('Opening count'), '', $math['opening']],
        [__('Event cash'), '+', $math['event']],
        [__('Subscription cash'), '+', $math['subscription']],
        [__('Other cash'), '+', $math['other']],
        [__('Drops'), '−', $math['drops']],
        [__('Paid out to instructors'), '−', $math['payouts']],
    ];
@endphp

<div class="space-y-4 text-sm">
    <table class="w-full">
        <caption class="sr-only">{{ __('How the expected count and variance were worked out') }}</caption>
        <thead class="sr-only">
            <tr>
                <th scope="col">{{ __('Line') }}</th>
                <th scope="col">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as [$label, $sign, $cents])
                <tr>
                    <th scope="row" class="py-1 text-start font-normal text-gray-600 dark:text-gray-300">{{ $label }}</th>
                    <td class="py-1 text-end tabular-nums">{{ $sign }} {{ $money($cents) }}</td>
                </tr>
            @endforeach
            <tr class="border-t border-gray-200 dark:border-white/10">
                <th scope="row" class="py-1 text-start font-semibold">{{ __('Expected in the box') }}</th>
                <td class="py-1 text-end font-semibold tabular-nums">{{ $money($math['expected']) }}</td>
            </tr>
            @if ($math['counted'] !== null)
                <tr>
                    <th scope="row" class="py-1 text-start font-normal text-gray-600 dark:text-gray-300">{{ __('Counted at close') }}</th>
                    <td class="py-1 text-end tabular-nums">{{ $money($math['counted']) }}</td>
                </tr>
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <th scope="row" class="py-1 text-start font-semibold">{{ __('Variance (counted − expected)') }}</th>
                    <td class="py-1 text-end font-semibold tabular-nums">{{ $money($math['variance']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($math['counted'] === null)
        <p class="text-gray-600 dark:text-gray-300">{{ __('This shift is still open, so there is no count or variance yet.') }}</p>
    @endif

    @if ($math['prepay'] !== 0 || $math['electronic'] !== [])
        <div class="space-y-1 text-gray-600 dark:text-gray-300">
            <p class="font-medium">{{ __('Taken on this shift but not in the box') }}</p>
            <ul class="list-disc space-y-1 ps-5">
                @if ($math['prepay'] !== 0)
                    <li>{{ __('Prepaid cash for later events :amount, in each event\'s prepay envelope', ['amount' => $money($math['prepay'])]) }}</li>
                @endif
                @foreach ($math['electronic'] as $label => $cents)
                    <li>{{ __(':method :amount, paid electronically', ['method' => $label, 'amount' => $money($cents)]) }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
