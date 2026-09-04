<x-filament-panels::page>
    <x-screen-instructions title="How to use Role Labels">
        <p>Every club calls its volunteer tiers something different — this page lets what staff <strong>see</strong> match your own vernacular, without touching who can do what.</p>
        <p>Type a name into a field to override that role's displayed label everywhere it appears (the Users form/table, Active Patrons' signed-in-staff line). Leave a field blank to keep the shipped default.</p>
        <p>This is cosmetic only: permissions, gates, and every access rule stay keyed to the underlying role regardless of what it's labeled here.</p>
    </x-screen-instructions>

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
