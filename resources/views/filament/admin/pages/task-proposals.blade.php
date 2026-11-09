<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Task proposals')">
        <p>{{ __('Tasks staff have proposed for their roles, on My tasks. The others who work that role vote them up or down; the votes are advice, and the decision is yours.') }}</p>
        <p>{!! __('<strong>Approve</strong> adds the task to the standard list for that role (pick its area and order). <strong>Decline</strong> needs a reason, which the person who proposed it is told. Either way they get a notification.') !!}</p>
        <p>{{ __('The list shows proposals waiting for a decision; change the Status filter to see ones already approved or declined.') }}</p>
    </x-screen-instructions>

    {{ $this->table }}
</x-filament-panels::page>
