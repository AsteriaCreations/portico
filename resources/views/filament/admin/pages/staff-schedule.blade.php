<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use the Staff schedule')">
        <p>{!! __('Each shift lists the people who can work its role, best answer first: <strong>Preferred</strong>, then <strong>Available</strong>, then no answer, then <strong>No</strong>. Pick who works each shift, then <strong>Save</strong>. A shift takes no more people than it needs, and nobody can be on two shifts at the same time.') !!}</p>
        <p>{!! __('Staff can\'t see a week until you <strong>Publish week</strong>. Publishing sends everyone on it a notification with their shifts, and stops availability for that week from changing. After that, adding or taking someone off a shift notifies them straight away.') !!}</p>
        <p>{{ __('Which shifts an event has is set on its Staffing tab; who can work a role, on Shift Roles.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
