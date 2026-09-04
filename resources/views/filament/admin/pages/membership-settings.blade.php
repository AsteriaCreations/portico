<x-filament-panels::page>
    <x-screen-instructions title="How to use Membership Settings">
        <p>These are club-wide numbers, not on/off switches — for turning whole features on or off, see Feature Flags instead.</p>
        <p>Read each field's helper text before changing it — several (like the event window buffer or probation period) affect what staff see at the desk in real time.</p>
        <p>Displayed organization name is Owner-only; everyone else sees it as read-only or hidden.</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
