<x-screen-instructions :title="__('How to use Technical')">
    <p>{{ __('The widget shows the health of this app\'s scheduled jobs (nightly backup, comp-reward voucher grants, and the upstream-update check when that\'s turned on) — when each last ran and whether it succeeded.') }}</p>
    <p>{{ __('Use the two buttons above it to run a job on demand right now, instead of waiting for the next scheduled tick — useful right after fixing whatever made a job fail.') }}</p>
    <p>{{ __('A manual run here is recorded exactly the same way a scheduled one is, so the widget\'s history stays accurate either way.') }}</p>
</x-screen-instructions>
