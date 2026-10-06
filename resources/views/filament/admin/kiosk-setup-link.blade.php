{{-- Body of Feature Flags' "Kiosk setup link" (FeatureFlags::showKioskSetupLinkAction()). --}}
<div class="flex flex-col items-center gap-4 text-center">
    @if ($url)
        <img src="{{ $qrDataUri }}" width="220" height="220" alt="{{ __('QR code of the kiosk setup link') }}">

        <ol class="list-decimal space-y-1 ps-5 text-start text-sm text-gray-600 dark:text-gray-300">
            <li>{{ __('On the kiosk tablet, scan this QR code with the camera app, or type the link below into its browser.') }}</li>
            <li>{{ __('The kiosk screen opens and remembers this tablet. Allow camera access when asked.') }}</li>
            <li>{{ __('Bookmark the page or add it to the home screen. The link\'s key disappears from the address bar; the tablet keeps it.') }}</li>
        </ol>

        <p class="w-full break-all rounded-lg bg-gray-100 p-3 font-mono text-xs text-gray-800 dark:bg-white/5 dark:text-gray-200">{{ $url }}</p>

        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('This link is shown only now. Keep it private: anyone with it can set up a kiosk. If it\'s lost, make a new one.') }}</p>
    @endif
</div>
