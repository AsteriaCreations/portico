<?php

use App\Models\Attendance;
use App\Models\Event;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

// Isolated from CheckIn's own Livewire component for the same reason as
// register-box-summary: this needs to keep polling for check-ins made from
// other terminals without ever contending with an action click on the parent
// page's own Livewire message-bus scope.
new class extends Component
{
    public int $eventId;

    #[Computed]
    public function event(): Event
    {
        return Event::query()->findOrFail($this->eventId);
    }

    #[Computed]
    public function attendances(): Collection
    {
        return Attendance::query()
            ->with('member')
            ->where('event_id', $this->eventId)
            ->whereNotNull('checked_in_at')
            ->orderByDesc('checked_in_at')
            ->get();
    }
};
?>

<div wire:poll.10s>
    <x-filament::section>
        {{-- role="status" so a poll that changes the count (another terminal checking
        someone in) is announced -- the table body below stays outside this region, since
        re-announcing the whole roster on every 10s poll would be noise, not signal. --}}
        <x-slot name="heading">
            <span role="status">Checked in tonight ({{ $this->attendances->count() }})</span>
        </x-slot>

        @if ($this->attendances->isEmpty())
            <p class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No one checked in yet.</p>
        @else
            {{-- Below sm: one stacked card per person, so a phone at the desk
            never needs the sideways scroll the 5-column table forces. --}}
            <div class="sm:hidden">
                @foreach ($this->attendances as $checkedInAttendance)
                    <div class="border-t border-gray-200 py-2 text-sm dark:border-white/10">
                        <p class="font-medium">{{ $checkedInAttendance->member->displayName() }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $this->event->name }} &middot; {{ $this->event->event_date->toFormattedDateString() }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $checkedInAttendance->checked_in_at->format('g:i A') }} &middot; ${{ number_format($checkedInAttendance->amount_paid, 2) }}</p>
                    </div>
                @endforeach
            </div>

            {{-- sm and up: the full columnar table, unchanged. --}}
            <div class="fi-ta-content hidden overflow-x-auto sm:block">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Member</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Event</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Date</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Checked in</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->attendances as $checkedInAttendance)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->member->displayName() }}</td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->name }}</td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->event_date->toFormattedDateString() }}</td>
                                <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->checked_in_at->format('g:i A') }}</td>
                                <td class="px-3 py-2 text-sm">${{ number_format($checkedInAttendance->amount_paid, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
