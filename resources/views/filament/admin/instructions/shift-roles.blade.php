<x-screen-instructions :title="__('How to use Shift Roles')">
    <p>{{ __('The jobs staff are scheduled for (Door, DM, Bar, Setup…), and who can work each one. These are separate from login roles: a login role decides what someone can do in the app, a shift role is a job on the schedule.') }}</p>
    <p>{!! __('Add the people who can work a role under <strong>Who can work it</strong>. Only they can give availability for, or be scheduled on, its shifts.') !!}</p>
    <p>{!! __('Which shifts each event needs is set per event type, on the event type\'s <strong>Staff shifts</strong> tab. New events of that type get those shifts automatically, and each event\'s own <strong>Staff shifts</strong> tab can change them for a one-off.') !!}</p>
    <p>{!! __('To stop using a role, turn off <strong>Active</strong> rather than deleting it. A role with shifts on events can\'t be deleted.') !!}</p>
</x-screen-instructions>
