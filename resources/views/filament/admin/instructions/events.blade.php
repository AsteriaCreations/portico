<x-screen-instructions :title="__('How to use Events')">
    <p>{{ __('The calendar every check-in, subscription, and report ultimately hangs off of. Anyone Manager+ can view it; only Admin+ can create, edit, archive, or delete an event — cost is fixed once set.') }}</p>
    <p>{{ __('An event\'s edit page has five tabs beyond its own fields:') }}</p>
    <p>{!! __('<strong>Attendance</strong> — full history of who\'s checked in. <strong>Prepay List</strong> / <strong>Comp List</strong> — people expected but not yet arrived (paid ahead, or comped ahead). <strong>Comp Requests</strong> — a Showrunner\'s pending nominations awaiting your approval. <strong>Add-On Day Passes</strong> — one-night add-on purchases for this specific event.') !!}</p>
    <p>{!! __('Turn on <strong>Allow prepay ahead of the door</strong> to let this event show up in the check-in picker before its own date — useful for taking payment early.') !!}</p>
    <p>{!! __('<strong>Archive</strong> retires an event: it leaves the check-in desk, Active Patrons, the Showrunner screen and this list (use the <strong>Archived</strong> filter to see it again), and becomes read-only. Everything recorded for it is kept, and <strong>Unarchive</strong> brings it back. A past event can always be archived; an upcoming one only while nobody is on it.') !!}</p>
    <p>{!! __('<strong>Delete</strong> only appears for an event nothing has been recorded against — one created by mistake. Once anyone has checked in, prepaid, been comped or requested for it, archive it instead.') !!}</p>
</x-screen-instructions>
