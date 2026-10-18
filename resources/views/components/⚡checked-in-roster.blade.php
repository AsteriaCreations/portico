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
            ->with(['member.category', 'member.sponsor', 'member.paidEntrySubscriptions'])
            ->withFirstArrival()
            ->where('event_id', $this->eventId)
            ->whereNotNull('checked_in_at')
            ->orderByDesc('checked_in_at')
            ->get();
    }

    /**
     * Attendance ids that get the subscription icon; see
     * Attendance::isSubscriptionPurchaseVisit().
     *
     * @return array<int, true>
     */
    #[Computed]
    public function boughtSubscription(): array
    {
        return $this->attendances
            ->filter(fn (Attendance $attendance): bool => $attendance->isSubscriptionPurchaseVisit())
            ->mapWithKeys(fn (Attendance $attendance): array => [$attendance->id => true])
            ->all();
    }

    /**
     * The icons beside a person's name, the same list for the phone cards
     * and the table: a guest (with their sponsor), a member promoted from
     * Prospective tonight, and a subscription bought with the visit.
     *
     * @return list<array{icon: string, label: string, color: string}>
     */
    public function markers(Attendance $attendance): array
    {
        $member = $attendance->member;
        $legend = $this->legend();

        return array_values(array_filter([
            $member->isGuest() ? [
                ...$legend['guest'],
                'label' => $member->sponsor ? __('Guest of :sponsor', ['sponsor' => $member->sponsor->displayName()]) : __('Guest'),
            ] : null,
            $attendance->isNewMemberVisit() ? $legend['new_member'] : null,
            isset($this->boughtSubscription[$attendance->id]) ? $legend['subscription'] : null,
        ]));
    }

    /**
     * Every icon markers() can show, with its plain meaning: the key above
     * the list, and the single place each icon and color is defined.
     *
     * @return array<string, array{icon: string, label: string, color: string}>
     */
    public function legend(): array
    {
        return [
            'guest' => ['icon' => 'heroicon-m-user-plus', 'label' => __('Guest'), 'color' => 'text-info-600 dark:text-info-400'],
            'new_member' => ['icon' => 'heroicon-m-sparkles', 'label' => __('New member — first visit'), 'color' => 'text-primary-600 dark:text-primary-400'],
            'subscription' => ['icon' => 'heroicon-m-ticket', 'label' => __('Bought a subscription'), 'color' => 'text-success-600 dark:text-success-400'],
        ];
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
            {{-- Key to the icons beside names. The icons here are decorative (aria-hidden):
            the text beside each says what it means, and the icons in the list carry their own
            aria-label. --}}
            <ul aria-label="{{ __('Icon key') }}" class="flex flex-wrap gap-x-4 gap-y-1 px-3 pb-2 text-xs text-gray-500 dark:text-gray-400">
                @foreach ($this->legend() as $key)
                    <li class="flex items-center gap-1">
                        <span aria-hidden="true" class="{{ $key['color'] }}"><x-filament::icon :icon="$key['icon']" /></span>
                        {{ $key['label'] }}
                    </li>
                @endforeach
            </ul>

            {{-- Each person is listed by pickerLabel() -- the text the member picker shows,
            so it matches what staff searched by -- with displayName() (the desk greeting
            name) as a hover title on the table, or a second line on a card (no hover on a phone).

            Below sm: one stacked card per person, so a phone at the desk
            never needs the sideways scroll the 5-column table forces. --}}
            <div class="checked-in-roster-cards">
                @foreach ($this->attendances as $checkedInAttendance)
                    <div class="border-t border-gray-200 py-2 text-sm dark:border-white/10">
                        <p class="flex items-center gap-1 font-medium">
                            {{ \App\Models\Member::pickerLabel($checkedInAttendance->member) }}
                            @foreach ($this->markers($checkedInAttendance) as $marker)
                                <span role="img" aria-label="{{ $marker['label'] }}" title="{{ $marker['label'] }}" class="{{ $marker['color'] }}"><x-filament::icon :icon="$marker['icon']" /></span>
                            @endforeach
                        </p>
                        @if ($checkedInAttendance->member->displayName() !== \App\Models\Member::pickerLabel($checkedInAttendance->member))
                            <p class="text-gray-500 dark:text-gray-400">{{ $checkedInAttendance->member->displayName() }}</p>
                        @endif
                        <p class="text-gray-500 dark:text-gray-400">{{ $this->event->name }} &middot; {{ $this->event->event_date->isoFormat('ll') }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $checkedInAttendance->checked_in_at->isoFormat('LT') }} &middot; {{ \App\Models\MembershipSetting::formatMoney($checkedInAttendance->amount_paid) }}</p>
                    </div>
                @endforeach
            </div>

            {{-- sm and up: the full columnar table, unchanged. --}}
            <div class="checked-in-roster-table fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full text-start tabular-nums">
                    <thead>
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Member') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Event') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Date') }}</th>
                            <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Checked in') }}</th>
                            <th scope="col" class="px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Paid') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->attendances as $checkedInAttendance)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-sm">
                                    <span class="flex items-center gap-1">
                                        <span title="{{ $checkedInAttendance->member->displayName() }}">{{ \App\Models\Member::pickerLabel($checkedInAttendance->member) }}</span>
                                        @foreach ($this->markers($checkedInAttendance) as $marker)
                                            <span role="img" aria-label="{{ $marker['label'] }}" title="{{ $marker['label'] }}" class="{{ $marker['color'] }}"><x-filament::icon :icon="$marker['icon']" /></span>
                                        @endforeach
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->name }}</td>
                                <td class="px-3 py-2 text-sm">{{ $this->event->event_date->isoFormat('ll') }}</td>
                                <td class="px-3 py-2 text-sm">{{ $checkedInAttendance->checked_in_at->isoFormat('LT') }}</td>
                                <td class="px-3 py-2 text-end text-sm">{{ \App\Models\MembershipSetting::formatMoney($checkedInAttendance->amount_paid) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
