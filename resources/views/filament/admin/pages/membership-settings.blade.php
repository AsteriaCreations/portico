<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Membership Settings')">
        <p>{{ __('These are club-wide numbers, not on/off switches — for turning whole features on or off, see Feature Flags instead.') }}</p>
        <p>{{ __('Read each field\'s helper text before changing it — several (like the event window buffer or probation period) affect what staff see at the desk in real time.') }}</p>
        <p>{{ __('Settings are grouped by what they affect. Kiosk and Updates & deploy are set once and start collapsed; click a heading to open it. One Save settings button at the bottom saves every group.') }}</p>
        <p>{{ __('Displayed organization name and Organization logo are Owner-only and hidden from everyone else.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
