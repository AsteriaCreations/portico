{{-- Printable kiosk card (route kiosk-qr-card). A standalone page outside the
panel, so it carries its own CSS rather than Tailwind utilities. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Kiosk card — :name', ['name' => $member->displayName()]) }}</title>
    <style>
        body { margin: 0; padding: 24px 16px; background: #fff; color: #111; font-family: system-ui, sans-serif; }
        .card { max-width: 3.5in; margin: 0 auto; padding: 16px; border: 1px dashed #999; border-radius: 8px; text-align: center; }
        .org { margin: 0; font-size: 14px; color: #444; }
        .name { margin: 4px 0 8px; font-size: 20px; font-weight: 600; }
        .qr { width: 100%; max-width: 240px; height: auto; }
        .help { margin: 8px 0 0; font-size: 12px; line-height: 1.4; color: #333; }
        .actions { margin-top: 16px; text-align: center; }
        .actions button { font: inherit; padding: 8px 16px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="card">
        @if (filled($orgName))
            <p class="org">{{ $orgName }}</p>
        @endif
        <p class="name">{{ $member->displayName() }}</p>
        <img class="qr" src="{{ $qrDataUri }}" width="240" height="240" alt="{{ __('Kiosk QR code') }}">
        <p class="help">{{ __('Scan at the kiosk by the door to check in. Works while your subscription is active. Keep it private: it checks in as you.') }}</p>
    </div>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
</body>
</html>
