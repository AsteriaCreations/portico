<x-screen-instructions :title="__('How to use Categories')">
    <p>{!! __('Member categories — freely editable, with one exception: <strong>Prospective</strong>, <strong>Guest</strong>, and <strong>Irregular</strong> can\'t be renamed or deleted, because other parts of the app look them up by that exact name.') !!}</p>
    <p>{{ __('Everything else about the list — adding a category, reordering, deactivating one — is unrestricted.') }}</p>
    <p>{!! __('Turn on <strong>Comped</strong> for a category whose members never pay entry or pool fees (e.g. staff or honorary members).') !!}</p>
    <p>{!! __('Turn on <strong>Exempt from the one-time payment limit</strong> for a category whose members may pay entry with a one-time payment method (such as Venmo) on every visit — guests, for example.') !!}</p>
    <p>{!! __('To add many at once, use <strong>Download category template</strong>, fill it in, then <strong>Bulk upload categories</strong>.') !!}</p>
</x-screen-instructions>
