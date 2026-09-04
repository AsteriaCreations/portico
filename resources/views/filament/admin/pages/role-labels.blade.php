<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-500 mb-4">
            Leave a field blank to use its default. This only changes what's displayed — it never
            changes the underlying role, its permissions, or anything stored in the database.
        </p>

        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
