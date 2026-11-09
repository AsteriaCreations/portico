<x-screen-instructions :title="__('How to use Shift Roles')">
    <p>{{ __('The jobs staff are scheduled for (Door, DM, Bar, Setup…), and who can work each one. These are separate from login roles: a login role decides what someone can do in the app, a shift role is a job on the schedule.') }}</p>
    <p>{!! __('Add the people who can work a role under <strong>Who can work it</strong>. Only they can give availability for, or be scheduled on, its shifts.') !!}</p>
    <p>{!! __('Turn on <strong>Anyone can work it</strong> for jobs like Setup that just need people: everyone with any shift role is offered its shifts.') !!}</p>
    <p>{!! __('How many people each event needs is set on the event\'s <strong>Staffing</strong> tab: its Open and Close (or whole-night) shifts with a count for each role here, plus any setup or extra shifts.') !!}</p>
    <p>{!! __('To stop using a role, turn off <strong>Active</strong> rather than deleting it. A role with shifts on events can\'t be deleted.') !!}</p>
</x-screen-instructions>
