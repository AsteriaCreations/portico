<x-filament-panels::page>
    <x-screen-instructions :title="__('How to read this report')">
        <p>{{ __('Each past event with what its showrunner earns, worked out the same way as the payout box on the event\'s own page.') }}</p>
        <p>{!! __('<strong>Headcount</strong> is everyone who paid at the door, plus subscribers when the entry fee is above the subscription credit. Staff and anyone else who got in free never count.') !!}</p>
        <p>{!! __('It opens on events that had a showrunner. Set the <strong>Showrunner</strong> filter to <strong>All events</strong> to see what every night would have paid, which is useful for checking imported history.') !!}</p>
        <p>{{ __('The total at the top covers every event the filters match, not just this page. A voucher still has to be issued by hand from Vouchers.') }}</p>
    </x-screen-instructions>

    {{ $this->table }}
</x-filament-panels::page>
