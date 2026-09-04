<x-filament-panels::page>
    <x-screen-instructions title="How to request a comp">
        <p>Pick the event you're running, then click <strong>Request a comp</strong> to nominate a member for free admission.</p>
        <p>Choose a reason from the list, or check "Reason isn't in the list" to type your own — an Admin will either approve it as-is or turn it into a standing reason.</p>
        <p>Your request sits <strong>Pending</strong> until an Admin approves it — it doesn't admit the member on its own. The table below shows every request you've made for this event and its current status.</p>
        <p>A banned member can't be requested unless they already have an exception for this specific event.</p>
    </x-screen-instructions>

    {{ $this->form }}

    @if ($this->getSelectedEvent())
        <div class="mt-4">
            {{ $this->requestCompAction }}
        </div>

        {{ $this->getTable()->render() }}
    @endif
</x-filament-panels::page>
