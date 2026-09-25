<x-screen-instructions :title="__('How to use Add-Ons')">
    <p>{{ __('The catalog of extras staff can sell on top of entry — a flat one-time charge (a rentable room, a sleepover) by default.') }}</p>
    <p>{!! __('Check <strong>Subscribable</strong> to let a Plan be created for this add-on, so a member can pay for it monthly instead of per visit — this is what generalized the old "Pool subscription" into something any add-on can offer. (<strong>Priced per event</strong> only appears on Pool, whose price is set on each event rather than here.)') !!}</p>
    <p>{!! __('Set <strong>Max per night</strong> for something there\'s only so much of — e.g. one rentable room. It caps sales across all of a night\'s events; leave it blank for unlimited.') !!}</p>
    <p>{!! __('Check <strong>Overnight stay</strong> for an add-on that means the guest is staying the night — it changes how long they\'re tracked as present and who stays accountable for them.') !!}</p>
    <p>{{ __('To require a signed waiver before someone can use an add-on (e.g. the Pool Waiver), set that up on Paperwork Types, not here.') }}</p>
    <p>{{ __('Turning the whole feature off is done from Feature Flags, not here — this page only edits what\'s in the catalog.') }}</p>
</x-screen-instructions>
