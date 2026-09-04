<x-filament-panels::page>
    <x-screen-instructions title="How to use the Cleaning Checklist">
        <p>Each row is one recurring task. Click <strong>Mark done</strong> once you've completed it.</p>
        <p>Tasks are tracked individually — different crew members can knock out different tasks off the same list, and each one shows who completed it.</p>
        <p>Everything resets automatically at the start of a new week, so a task marked done last week shows as open again now.</p>
    </x-screen-instructions>

    {{ $this->getTable()->render() }}
</x-filament-panels::page>
