{{-- $cohorts is RetentionAnalytics::cohorts(). Every cell prints its
     percentage; the shading only repeats it. --}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('Who keeps coming back')"
        :description="__('Each row is the people whose first visit fell in that month. Each column is the share of them who visited again one month later, two months later, and so on. A dash means that month hasn\'t come yet.')"
    >
        <div style="overflow-x: auto">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('Share of each month\'s first-time visitors who visited in the months after') }}</caption>
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th scope="col" class="py-1 text-start font-semibold">{{ __('First visit') }}</th>
                        <th scope="col" class="py-1 text-end font-semibold">{{ __('People') }}</th>
                        @foreach (range(1, $monthsAfter) as $after)
                            <th scope="col" class="py-1 text-end font-semibold">{{ trans_choice(':count month later|:count months later', $after) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cohorts as $cohort)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <th scope="row" class="py-1 text-start font-normal">{{ $cohort['label'] }}</th>
                            <td class="py-1 text-end tabular-nums">{{ $cohort['size'] }}</td>
                            @foreach ($cohort['retained'] as $percent)
                                <td
                                    class="py-1 text-end tabular-nums"
                                    @if ($percent) style="background-color: rgba(34, 197, 94, {{ round(min($percent, 100) / 100 * 0.45, 2) }})" @endif
                                >
                                    {{ $percent === null ? '—' : __(':percent%', ['percent' => $percent]) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
