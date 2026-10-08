<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use My account')">
        <p>{{ __('Your sign-in email is only what you type to sign in, and may not be an inbox anyone reads. Enter the address you actually want club email sent to, then your current password, then Save. Leave it blank to use your sign-in email.') }}</p>
        <p>{{ __('To stop club email altogether, turn off Email me club communications and Save. You will still see notifications in the app.') }}</p>
        <p>{{ __('To choose a new password, use Change password.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Password')">
        {{ $this->changePasswordAction }}
    </x-filament::section>
</x-filament-panels::page>
