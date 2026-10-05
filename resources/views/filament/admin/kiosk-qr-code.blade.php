{{-- Body of the "Kiosk QR code" action (App\Filament\Concerns\ShowsKioskQrCode). --}}
<div class="flex flex-col items-center gap-4 text-center">
    @if ($qrDataUri)
        <img src="{{ $qrDataUri }}" width="240" height="240" alt="{{ __('Kiosk QR code for :name', ['name' => $member->displayName()]) }}">

        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('Scan this at the kiosk by the door. It lets the member in only while they have an active subscription and owe nothing; otherwise the kiosk sends them to the desk. A photo of this screen works too.') }}
        </p>

        <a href="{{ $cardUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-primary-600 underline dark:text-primary-400">
            {{ __('Open a printable card') }}
        </a>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('No kiosk code yet.') }}</p>
    @endif
</div>
