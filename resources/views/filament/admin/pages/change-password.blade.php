<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Change password')">
        <p>{{ __('Enter your current password once, then your new one twice. If your account was flagged to require a new password, you can\'t use the rest of the panel until you do this.') }}</p>
        <p>{{ __('Once saved, you\'ll be sent to the Dashboard. Anywhere else you\'re signed in is signed out.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
