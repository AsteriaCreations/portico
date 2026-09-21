<x-screen-instructions :title="__('How to use Payment Methods')">
    <p>{{ __('The pick-list offered everywhere staff take payment — check-in, subscription purchases, the register box.') }}</p>
    <p>{!! __('A method\'s <strong>code</strong> locks once created (it\'s already stamped on historical records), so pick it carefully the first time — everything else stays editable.') !!}</p>
    <p>{!! __('Check <strong>Requires register shift</strong> for a method that needs an open cash drawer to be selectable and that should count toward end-of-shift reconciliation (e.g. Cash) — leave it off for something like Venmo that doesn\'t touch the box.') !!}</p>
</x-screen-instructions>
