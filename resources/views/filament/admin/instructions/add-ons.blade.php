<x-screen-instructions :title="__('How to use Add-Ons')">
    <p>{{ __('The catalog of extras staff can sell on top of entry — a flat one-time charge (a rentable room, a sleepover) by default.') }}</p>
    <p>{!! __('Check <strong>Subscribable</strong> to let a Plan be created for this add-on, so a member can pay for it monthly instead of per visit — this is what generalized the old "Pool subscription" into something any add-on can offer. Check <strong>Priced per event</strong> only for an add-on whose price varies event to event rather than one flat catalog price.') !!}</p>
    <p>{!! __('Check <strong>Overnight stay</strong> for an add-on that means the guest is staying the night — it changes how long they\'re tracked as present and who stays accountable for them.') !!}</p>
    <p>{{ __('Turning the whole feature off is done from Feature Flags, not here — this page only edits what\'s in the catalog.') }}</p>
</x-screen-instructions>
