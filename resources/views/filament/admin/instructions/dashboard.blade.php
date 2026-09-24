<x-screen-instructions :title="__('How to use the Dashboard')">
    <p>{!! __('This is the landing page after login — a quick account widget plus <strong>Record Departures</strong>.') !!}</p>
    <p>{{ __('Use Record Departures when people have left the building but nobody logged them out individually — enter a headcount (and an optional reason) to bring tonight\'s occupancy count back down. For departing one specific, known person instead, use Active Patrons.') }}</p>
    <p>{!! __('Print the <a href=":url" target="_blank" rel="noopener" class="underline">desk reference cards</a> — Check-In, Active Patrons, and Record Departures, one per page.', ['url' => route('desk-reference-cards').'#departures']) !!}</p>
</x-screen-instructions>
