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

        <div class="fi-ta-content overflow-x-auto">
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
                    @forelse ($this->attendances as $checkedInAttendance)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->member->preferred_name ?: $checkedInAttendance->member->username }}</td>
                            <td class="px-3 py-2 text-sm">{{ $this->event->name }}</td>
                            <td class="px-3 py-2 text-sm">{{ $this->event->event_date->toFormattedDateString() }}</td>
                            <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->checked_in_at->format('g:i A') }}</td>
                            <td class="px-3 py-2 text-sm">${{ number_format($checkedInAttendance->amount_paid, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No one checked in yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
