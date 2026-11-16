<x-screen-instructions :title="__('How to use Analytics')">
    <p>{{ __('Everything here is read-only reporting — there\'s nothing to fill in or submit.') }}</p>
    <p>{{ __('Pick a Period at the top (or Custom dates for your own range). Every figure that covers a span of time follows it and says so in its heading; the tabs underneath group the figures by subject.') }}</p>
    <p>{{ __('A trend chart shows the twelve weeks or months ending with the period you picked, so there is always something to compare it with. "Tonight", the voucher liability and the membership mix are as of now, whatever period is chosen.') }}</p>
    <p>{{ __('The headline on Overview sets each figure against the same stretch of the period before; a week or month still under way is compared only as far as it has run. Some tiles are links: click one to open the list behind the number.') }}</p>
    <p>{{ __('The Events tab lists each event held in the period. "Against usual" is its headcount next to the average for that type of event over the last twelve months; click a row to open the event, or Export (CSV) to take the list into a spreadsheet.') }}</p>
    <p>{{ __('A stat tied to an optional feature (vouchers, add-ons, register shifts) disappears on its own once that feature is turned off in Feature Flags — nothing to configure here.') }}</p>
</x-screen-instructions>
