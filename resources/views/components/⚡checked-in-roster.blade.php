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
    {{-- Scoped here rather than Tailwind's sm:hidden / hidden sm:block: those only exist once
    the panel theme is built (resources/css/filament/admin/theme.css), and without them both
    layouts render and each person appears twice -- once as a card above the table, once in
    it. This keeps the roster right even on an install that never ran `npm run build`.
    640px is Tailwind's sm breakpoint. --}}
    <style>
        .checked-in-roster-cards { display: block; }
        .checked-in-roster-table { display: none; }
        @media (min-width: 640px) {
            .checked-in-roster-cards { display: none; }
            .checked-in-roster-table { display: block; }
        }
    </style>

    <x-filament::section>
        {{-- role="status" so a poll that changes the count (another terminal checking
        someone in) is announced -- the table body below stays outside this region, since
        re-announcing the whole roster on every 10s poll would be noise, not signal. --}}
        <x-slot name="heading">
            <span role="status">{{ __('Checked in tonight (:count)', ['count' => $this->attendances->count()]) }}</span>
        </x-slot>

        @if ($this->attendances->isEmpty())
            <p class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No one checked in yet.') }}</p>
        @else
            {{-- Below sm: one stacked card per person, so a phone at the desk
            never needs the sideways scroll the 5-column table forces. --}}
            <div class="checked-in-roster-cards">
                @foreach ($this->attendances as $checkedInAttendance)
                    <div class="border-t border-gray-200 py-2 text-sm dark:border-white/10">
                        <p class="font-medium">{{ $checkedInAttendance->member->displayName() }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $this->event->name }} &middot; {{ $this->event->event_date->translatedFormat('M j, Y') }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $checkedInAttendance->checked_in_at->translatedFormat('g:i A') }} &middot; {{ \App\Models\MembershipSetting::formatMoney($checkedInAttendance->amount_paid) }}</p>
                    </div>
                @endforeach
            </div>

            {{-- sm and up: the full columnar table, unchanged. --}}
            <div class="checked-in-roster-table fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Member') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Event') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Date') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Checked in') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Paid') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->attendances as $checkedInAttendance)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->member->displayName() }}</td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->name }}</td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->event_date->translatedFormat('M j, Y') }}</td>
                                <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->checked_in_at->translatedFormat('g:i A') }}</td>
                                <td class="px-3 py-2 text-sm">{{ \App\Models\MembershipSetting::formatMoney($checkedInAttendance->amount_paid) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
