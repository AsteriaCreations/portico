<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Shifts Worked')">
        <p>{{ __('Each staff member\'s shifts worked this month against the monthly requirement (set on Membership Settings): who\'s short, who\'s met it, and how many shifts each went beyond it. Use Previous month to look back.') }}</p>
        <p>{!! __('A shift counts once it\'s marked worked: automatically when the person signs in during it, or by a Manager with <strong>Who worked</strong> on the Staffing page. Only roles with "Counts toward the monthly requirement" on (Shift Roles) count, and a shift belongs to the month it starts in.') !!}</p>
        <p>{{ __('Tasks done counts the task-list ticks each person made that month on My tasks. Off-shift tasks are the ones done on a shift they were not on, beyond their own: worth recognising with a voucher too.') }}</p>
    </x-screen-instructions>

    {{ $this->getTable()->render() }}
</x-filament-panels::page>
