<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use My tasks')">
        <p>{{ __('The task lists for the roles you work. While a shift of yours is on (from an hour before it starts until an hour after it ends), its list is here: press Done as you finish each task, so everyone can see what is done and who did it.') }}</p>
        <p>{{ __('Lists of other shifts running now, for roles you can work, are here too. Ticking one of those counts as extra, beyond your own shift.') }}</p>
        <p>{{ __('A role that is not on the schedule, such as Cleaning, has a weekly list instead: each task is done once a week, by anyone with the role.') }}</p>
        <p>{{ __('Undo takes back your own tick while the shift or week is still on. History lists everything you have done.') }}</p>
        <p>{{ __('Think a role needs a task that is not on its list? Use Propose a task. Others who work that role vote it up or down at the bottom of this page, and a Manager decides whether it joins the list.') }}</p>
    </x-screen-instructions>

    @if ($display === 'history')
        {{ $this->table }}
    @else
        @php($lists = $this->lists())

        @forelse ($lists as $list)
            <x-filament::section :heading="$list['title']" :description="$list['meta']" wire:key="list-{{ $list['slotId'] ?? 'week-'.$loop->index }}">
                @if ($list['extra'] === true)
                    <x-slot name="afterHeader">
                        <x-filament::badge color="info" icon="heroicon-o-star">{{ __('Not your shift: counts as extra') }}</x-filament::badge>
                    </x-slot>
                @elseif ($list['extra'] === false)
                    <x-slot name="afterHeader">
                        <x-filament::badge color="success" icon="heroicon-o-user">{{ __('Your shift') }}</x-filament::badge>
                    </x-slot>
                @endif

                @foreach ($list['areas'] as $area)
                    <div style="margin-top: {{ $loop->first ? '0' : '1.25rem' }};">
                        @if ($area['name'])
                            <h3 style="font-weight: 600;">{{ $area['name'] }}</h3>
                            @if ($area['notes'])
                                <p style="font-size: 0.875rem; opacity: 0.75; margin-bottom: 0.5rem;">{{ $area['notes'] }}</p>
                            @endif
                        @endif

                        <ul role="list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            @foreach ($area['tasks'] as $task)
                                <li wire:key="task-{{ $list['slotId'] ?? 'w' }}-{{ $task['id'] }}" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                    <div>
                                        <span style="{{ $task['done'] ? 'text-decoration: line-through; opacity: 0.7;' : '' }}">{{ $task['name'] }}</span>
                                        @if ($task['description'])
                                            <span style="display: block; font-size: 0.875rem; opacity: 0.75;">{{ $task['description'] }}</span>
                                        @endif
                                        @if ($task['done'])
                                            <span style="display: block; font-size: 0.875rem;">
                                                <x-filament::icon icon="heroicon-o-check-circle" style="display: inline-block; width: 1rem; height: 1rem; vertical-align: -0.15rem;" />
                                                {{ $task['done'] }}
                                            </span>
                                        @endif
                                    </div>
                                    @if (! $task['done'])
                                        <x-filament::button size="sm" icon="heroicon-o-check" wire:click="tick({{ $task['id'] }}, {{ $list['slotId'] ?? 'null' }})">
                                            {{ __('Done') }}
                                            <span class="sr-only">: {{ $task['name'] }}</span>
                                        </x-filament::button>
                                    @elseif ($task['canUndo'])
                                        <x-filament::button size="sm" color="gray" wire:click="undo({{ $task['completionId'] }})">
                                            {{ __('Undo') }}
                                            <span class="sr-only">: {{ $task['name'] }}</span>
                                        </x-filament::button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-filament::section>
        @empty
            <x-filament::section>
                <p>{{ __('Nothing to do right now. A shift\'s list shows here from an hour before it starts; a weekly list, such as Cleaning, shows all week.') }}</p>
            </x-filament::section>
        @endforelse

        @php($proposals = $this->openProposals())

        @if ($proposals !== [])
            <x-filament::section :heading="__('Proposed tasks for your roles')" :description="__('Vote up the ones that should be on the standard list. A Manager decides.')">
                <ul role="list" style="display: flex; flex-direction: column; gap: 0.75rem;">
                    @foreach ($proposals as $proposal)
                        <li wire:key="proposal-{{ $proposal['id'] }}" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                            <div>
                                <span style="font-weight: 600;">{{ $proposal['name'] }}</span>
                                <span style="display: block; font-size: 0.875rem; opacity: 0.75;">
                                    {{ collect([$proposal['role'], $proposal['area'], __('proposed by :name', ['name' => $proposal['by']])])->filter()->implode(' · ') }}
                                </span>
                                @if ($proposal['description'])
                                    <span style="display: block; font-size: 0.875rem;">{{ $proposal['description'] }}</span>
                                @endif
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                @if ($proposal['mine'])
                                    <x-filament::badge color="gray">{{ __('Yours') }}</x-filament::badge>
                                    <span>{{ __(':up up, :down down', ['up' => $proposal['up'], 'down' => $proposal['down']]) }}</span>
                                @else
                                    <x-filament::button size="sm" icon="heroicon-o-hand-thumb-up" :color="$proposal['myVote'] === 1 ? 'success' : 'gray'" :outlined="$proposal['myVote'] !== 1" wire:click="vote({{ $proposal['id'] }}, 1)" :aria-pressed="$proposal['myVote'] === 1 ? 'true' : 'false'">
                                        {{ $proposal['up'] }}
                                        <span class="sr-only">{{ __('Vote up: :task', ['task' => $proposal['name']]) }}</span>
                                    </x-filament::button>
                                    <x-filament::button size="sm" icon="heroicon-o-hand-thumb-down" :color="$proposal['myVote'] === -1 ? 'danger' : 'gray'" :outlined="$proposal['myVote'] !== -1" wire:click="vote({{ $proposal['id'] }}, -1)" :aria-pressed="$proposal['myVote'] === -1 ? 'true' : 'false'">
                                        {{ $proposal['down'] }}
                                        <span class="sr-only">{{ __('Vote down: :task', ['task' => $proposal['name']]) }}</span>
                                    </x-filament::button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
