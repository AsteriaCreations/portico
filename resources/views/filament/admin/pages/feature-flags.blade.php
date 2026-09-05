<x-filament-panels::page>
    <x-screen-instructions title="How to use Feature Flags">
        <p>Each toggle turns a whole feature area on or off for this club — read the helper text under a toggle before flipping it, it explains exactly what it hides and what it never touches.</p>
        <p>Turning a feature off only stops <strong>new</strong> use of it — it never deletes, hides, or retroactively rewrites anything already recorded (an existing subscription still works after its add-on is turned off, for example).</p>
        <p>Leave everything on unless you're certain this club doesn't use that feature.</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
