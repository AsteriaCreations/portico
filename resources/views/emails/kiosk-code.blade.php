<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<body style="font-family: sans-serif; font-size: 14px; color: #111;">
    <p>{{ __('Hi :name,', ['name' => $member->displayName()]) }}</p>

    <p>{{ __('This is your code for the self check-in kiosk by the door:') }}</p>

    <p><img src="{{ $message->embedData($qrPng, 'kiosk-code.png', 'image/png') }}" width="240" height="240" alt="{{ __('Your kiosk QR code') }}"></p>

    <p>{{ __('Hold it up to the kiosk camera, on your phone or printed. A screenshot works, and you don\'t need the internet at the door.') }}</p>

    <p>{{ __('It checks you in only while you have an active subscription and nothing to pay; otherwise the kiosk sends you to the front desk. Keep it private: it checks in as you. If you lose it, ask at the desk for a new one.') }}</p>

    @if (filled($orgName))
        <p>— {{ $orgName }}</p>
    @endif
</body>
</html>
