<x-filament-panels::page>
    <x-screen-instructions title="How to use Upstream Updates">
        <p>Shows commits on the configured upstream remote/branch that aren't in this checkout yet — check here before running an update (see docs/DEPLOYMENT.md §7 "Updating").</p>
        <p>This page never contacts the network itself. A scheduled <code>upstream:check</code> command periodically fetches in the background; "Check for updates now" above runs that fetch on demand.</p>
        <p>"Run update now" hands off to a Windows Scheduled Task that runs fully independently of this page — including restarting the web server — so the result below can take a few minutes to update.</p>
    </x-screen-instructions>

    @php
        $deployTrigger = $this->getLastDeployTrigger();
        $deployResult = $this->getLastDeployResult();
    @endphp

    {{-- role="status" so the eventual result gets announced once it lands, not
    just silently redrawn -- the same idiom as the checked-in roster's own
    isolated polling heading. --}}
    <div wire:poll.15s role="status">
        <x-filament::section>
            <x-slot name="heading">Last deploy</x-slot>

            @if (! $deployTrigger)
                <p class="text-sm text-gray-500 dark:text-gray-400">Never triggered from this page.</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($deployTrigger->last_failure_at?->gt($deployTrigger->last_success_at ?? now()->subCentury()))
                        Failed to trigger {{ $deployTrigger->last_failure_at->diffForHumans() }}: {{ $deployTrigger->last_failure_message }}
                    @else
                        Triggered {{ $deployTrigger->last_success_at->diffForHumans() }}.
                    @endif
                </p>

                @if ($deployResult)
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($deployResult->last_failure_at?->gt($deployResult->last_success_at ?? now()->subCentury()))
                            Deploy failed {{ $deployResult->last_failure_at->diffForHumans() }}: {{ $deployResult->last_failure_message }}
                        @else
                            Deploy succeeded {{ $deployResult->last_success_at->diffForHumans() }}.
                        @endif
                    </p>
                @endif
            @endif
        </x-filament::section>
    </div>

    @php
        $settings = \App\Models\MembershipSetting::current();
        $lastRun = $this->getLastRun();
        $pending = $this->getPendingCommits();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Status</x-slot>

        @if (blank($settings->upstream_remote))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No upstream remote configured. Set <strong>Upstream remote</strong> and <strong>Upstream branch</strong>
                on <a href="{{ \App\Filament\Admin\Pages\MembershipSettings::getUrl() }}" class="underline">Membership Settings</a> —
                the remote must already exist on this server via <code>git remote add</code>; this app never adds one itself.
            </p>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Tracking <strong>{{ $settings->upstream_remote }}/{{ $settings->upstream_branch }}</strong>.
                Last check: {{ $lastRun?->last_success_at?->diffForHumans() ?? 'never' }}.
            </p>

            @if ($pending === null)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Not resolvable yet — run a check (or wait for the scheduled one) before this can show pending commits.
                </p>
            @elseif (empty($pending))
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Up to date — no pending commits.</p>
            @else
                <div class="fi-ta-content mt-4 overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Commit</th>
                                <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Subject</th>
                                <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Author</th>
                                <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $commit)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="px-3 py-2 text-sm font-mono">{{ $commit['short_hash'] }}</td>
                                    <td class="px-3 py-2 text-sm">{{ $commit['subject'] }}</td>
                                    <td class="px-3 py-2 text-sm">{{ $commit['author'] }}</td>
                                    <td class="px-3 py-2 text-sm">{{ $commit['date']->toFormattedDateString() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
