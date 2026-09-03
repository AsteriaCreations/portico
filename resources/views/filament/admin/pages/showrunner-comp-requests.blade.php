<x-filament-panels::page>
    {{ $this->form }}

    @if ($this->getSelectedEvent())
        <div class="mt-4">
            {{ $this->requestCompAction }}
        </div>

        {{ $this->getTable()->render() }}
    @endif
</x-filament-panels::page>
