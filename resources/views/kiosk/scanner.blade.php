{{-- The kiosk tablet's screen (route kiosk). Standalone, outside the panel:
its own CSS, and its own script (resources/js/kiosk.js) which reads the
device secret from the setup link once, keeps it in the tablet's storage,
and posts each scanned code to kiosk.scan. Every message a member sees after
a scan comes from the server; the strings below cover only what happens on
the tablet itself. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Kiosk check-in') }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #111827; color: #f9fafb; font-family: system-ui, sans-serif; }
        main { display: flex; min-height: 100vh; flex-direction: column; align-items: center; justify-content: center; gap: 24px; padding: 24px 16px; text-align: center; }
        h1 { margin: 0; font-size: clamp(28px, 5vw, 48px); font-weight: 700; }
        .org { margin: 0; font-size: 18px; color: #d1d5db; }
        .panel { width: 100%; max-width: 640px; }
        .prompt { margin: 0; font-size: clamp(20px, 3.5vw, 30px); }
        .note { margin: 0; font-size: 18px; color: #d1d5db; }
        .camera { position: relative; width: 100%; max-width: 480px; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 16px; border: 4px solid #374151; background: #000; }
        .camera video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        .result { width: 100%; max-width: 640px; padding: 32px 24px; border-radius: 16px; font-size: clamp(24px, 4vw, 40px); font-weight: 600; }
        .result[data-status="admitted"] { background: #065f46; }
        .result[data-status="already_checked_in"] { background: #1e40af; }
        .result[data-status="see_staff"], .result[data-status="not_recognized"], .result[data-status="error"] { background: #92400e; }
        .result[data-status="closed"] { background: #374151; }
        [hidden] { display: none !important; }
    </style>
    @if ($isBuilt)
        @vite($script)
    @endif
</head>
<body>
    <main
        id="kiosk"
        data-scan-url="{{ route('kiosk.scan') }}"
        data-open="{{ $isOpen ? '1' : '0' }}"
        data-strings="{{ json_encode([
            'setup' => __('This tablet isn\'t set up yet. An Admin opens the kiosk setup link on it from Feature Flags.'),
            'badKey' => __('This tablet\'s kiosk link is no longer valid. An Admin needs to set it up again from Feature Flags.'),
            'turnedOff' => __('Kiosk check-in is turned off. Please see the front desk.'),
            'busy' => __('Too many scans at once. Please wait a moment and try again.'),
            'offline' => __('Can\'t reach the server right now. Please see the front desk.'),
            'noCamera' => __('The camera isn\'t available. Allow camera access for this page, or please see the front desk.'),
        ]) }}"
    >
        <div>
            @if (filled($orgName))
                <p class="org">{{ $orgName }}</p>
            @endif
            <h1>{{ __('Self check-in') }}</h1>
        </div>

        @if (! $isBuilt)
            <div class="panel">
                <p class="prompt">{{ __('The kiosk screen isn\'t built on this server yet.') }}</p>
                <p class="note">{{ __('Run npm run build (or an update without -SkipNpm), then reload this page.') }}</p>
            </div>
        @elseif (! $isOpen)
            <div class="panel" data-screen="closed">
                <p class="prompt">{{ __('There\'s no event running right now.') }}</p>
                <p class="note">{{ __('This screen checks again every minute.') }}</p>
            </div>
        @else
            <div class="panel" data-screen="setup" hidden>
                <p class="prompt" data-setup-message></p>
            </div>

            <div class="panel" data-screen="scan" hidden>
                <div class="camera" style="margin: 0 auto 16px">
                    <video data-video playsinline muted></video>
                </div>
                <p class="prompt">{{ __('Hold your kiosk QR code up to the camera.') }}</p>
                <p class="note">{{ __('No code, or need to pay? Please see the front desk.') }}</p>
            </div>
        @endif

        {{-- Announced to screen readers as each scan's result arrives. --}}
        <div class="result" data-result role="status" aria-live="polite" hidden></div>
    </main>
</body>
</html>
