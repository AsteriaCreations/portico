<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Staffing')">
        <p>{!! __('Each event this week, with the shifts it has. Click an event (or <strong>Staff it</strong>) to open its shifts below.') !!}</p>
        <p>{!! __('<strong>Set standard shifts</strong> sets Open and Close (or one shift for the whole night) and how many people each role needs. <strong>Add setup or extra shift</strong> adds a shift with its own times, such as setup or late cover. Once a shift has started, <strong>Who worked</strong> records who actually worked it.') !!}</p>
        <p>{{ __('Who works each shift is picked on the Staff schedule.') }}</p>
    </x-screen-instructions>

    {{ $this->getTable()->render() }}

    @if ($event = $this->selectedEvent())
        <x-filament::section :heading="__(':event, :date', ['event' => $event->name, 'date' => ($event->starts_at ?? $event->event_date)?->isoFormat('ddd ll')])">
            @livewire(\App\Filament\Admin\Resources\Events\RelationManagers\ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => \App\Filament\Admin\Pages\Staffing::class], key('staffing-'.$event->id))
        </x-filament::section>
    @endif
</x-filament-panels::page>
