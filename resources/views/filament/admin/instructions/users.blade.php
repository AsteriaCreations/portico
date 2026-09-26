<x-screen-instructions :title="__('How to use Users')">
    <p>{!! __('Staff accounts — Admin+ only. Set a user\'s <strong>role</strong> here, which determines everything they can do across the whole panel.') !!}</p>
    <p>{!! __('Someone locked out? Use <strong>Reset password</strong>. You\'ll see a temporary password once — read it out or pass it on privately. They\'re signed out everywhere and choose their own password when they next sign in.') !!}</p>
    <p>{{ __('You can only manage accounts at or below your own role, and only give roles up to your own — only an Owner can manage Owners.') }}</p>
    <p>{{ __('No active Owner yet (e.g. a new install)? Until there is one, an Admin can give the Owner role — to someone else or to their own account.') }}</p>
    <p>{!! __('Use the <strong>Capabilities</strong> tab for a permission that isn\'t role-based (e.g. Cleaning Crew access) — it\'s granted independently of rank, to a specific person.') !!}</p>
    <p>{!! __('<strong>Behavior Notes Written</strong> shows what this staff member has logged on Active Patrons, for accountability on the notes themselves.') !!}</p>
    <p>{{ __('Role names shown here can be customized per club on the Role Labels settings page — the underlying role and its permissions are unaffected either way.') }}</p>
</x-screen-instructions>
