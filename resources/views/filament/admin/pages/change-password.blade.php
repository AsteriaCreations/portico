<x-filament-panels::page>
    <x-screen-instructions title="How to use Change password">
        <p>Your account has been flagged to require a new password before you can do anything else in the panel. Enter your current password once, then your new one twice.</p>
        <p>Once saved, you'll be sent to the Dashboard and won't see this screen again unless it's flagged again.</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
