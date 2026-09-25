<x-screen-instructions :title="__('How to use Payment Methods')">
    <p>{{ __('The pick-list offered everywhere staff take payment — check-in, subscription purchases, the register box.') }}</p>
    <p>{!! __('A method\'s <strong>code</strong> locks once created (it\'s already stamped on historical records), so pick it carefully the first time — everything else stays editable.') !!}</p>
    <p>{!! __('Check <strong>Requires the register to be open</strong> for a method that needs an open cash drawer to be selectable and that should count toward end-of-shift reconciliation (e.g. Cash) — leave it off for something like Venmo that doesn\'t touch the box.') !!}</p>
    <p>{!! __('<strong>One-time use per member</strong> lets each member pay with this method only once, ever, for entry or a day pass — after that it\'s greyed out for them. Subscription purchases are never restricted by it.') !!}</p>
    <p>{!! __('<strong>Transaction fee</strong> is a flat surcharge added to the total whenever this method is chosen (e.g. to cover a processor\'s cut). It isn\'t added when nothing is owed. Leave it at 0 for no fee.') !!}</p>
</x-screen-instructions>
