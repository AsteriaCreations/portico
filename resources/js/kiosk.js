// The kiosk tablet's scanner (resources/views/kiosk/scanner.blade.php).
// Reads QR codes from the camera with jsQR and posts each one to the scan
// endpoint. The tablet is identified by an HttpOnly cookie the server set
// when the setup link was opened, so this script never sees a secret.
import jsQR from 'jsqr';

const root = document.getElementById('kiosk');
const SCAN_EVERY_MS = 200;
const SAME_CODE_COOLDOWN_MS = 8000;
const SHOW_RESULT_MS = { admitted: 4000, already_checked_in: 4000 };
const SHOW_OTHER_RESULT_MS = 7000;
const CLOSED_RELOAD_MS = 60000;

function start() {
    if (!root) {
        return;
    }

    // Not set up, or no event running: nothing to scan. Check again later.
    if (root.dataset.open !== '1') {
        window.setTimeout(() => window.location.reload(), CLOSED_RELOAD_MS);

        return;
    }

    const strings = JSON.parse(root.dataset.strings);
    const scanUrl = root.dataset.scanUrl;
    const screens = {
        message: root.querySelector('[data-screen="message"]'),
        scan: root.querySelector('[data-screen="scan"]'),
    };
    const result = root.querySelector('[data-result]');
    const video = root.querySelector('[data-video]');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    let busy = false;
    let lastCode = null;
    let lastCodeAt = 0;
    let resultTimer = null;

    function show(name) {
        screens.message.hidden = name !== 'message';
        screens.scan.hidden = name !== 'scan';
    }

    // A lasting problem only staff can fix: stop scanning and say so.
    function showMessage(message) {
        busy = true;
        screens.message.querySelector('[data-message]').textContent = message;
        show('message');
    }

    function showResult(status, message, durationMs, then) {
        window.clearTimeout(resultTimer);
        result.dataset.status = status;
        result.textContent = message;
        result.hidden = false;
        show(null);

        resultTimer = window.setTimeout(() => {
            result.hidden = true;
            then();
        }, durationMs);
    }

    function backToScanning() {
        show('scan');
        busy = false;
    }

    async function submit(token) {
        busy = true;
        lastCode = token;
        lastCodeAt = Date.now();

        let response;
        try {
            response = await fetch(scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ token }),
            });
        } catch {
            showResult('error', strings.offline, SHOW_OTHER_RESULT_MS, backToScanning);

            return;
        }

        if (response.status === 403) {
            showMessage(strings.badKey);

            return;
        }
        if (response.status === 404) {
            showResult('closed', strings.turnedOff, SHOW_OTHER_RESULT_MS, () => window.location.reload());

            return;
        }
        if (response.status === 429) {
            showResult('error', strings.busy, SHOW_OTHER_RESULT_MS, backToScanning);

            return;
        }
        if (!response.ok) {
            showResult('error', strings.offline, SHOW_OTHER_RESULT_MS, backToScanning);

            return;
        }

        const body = await response.json();

        if (body.status === 'closed') {
            showResult('closed', body.message, SHOW_OTHER_RESULT_MS, () => window.location.reload());

            return;
        }

        showResult(body.status, body.message, SHOW_RESULT_MS[body.status] ?? SHOW_OTHER_RESULT_MS, backToScanning);
    }

    function scanFrame() {
        if (busy || video.readyState < video.HAVE_ENOUGH_DATA) {
            return;
        }

        // Downscaled: plenty for a code held up to the camera, and much
        // cheaper to decode five times a second.
        const width = Math.min(640, video.videoWidth);
        const height = Math.round(video.videoHeight * (width / video.videoWidth));
        canvas.width = width;
        canvas.height = height;
        context.drawImage(video, 0, 0, width, height);

        const code = jsQR(context.getImageData(0, 0, width, height).data, width, height, { inversionAttempts: 'dontInvert' });
        if (!code || !code.data) {
            return;
        }
        if (code.data === lastCode && Date.now() - lastCodeAt < SAME_CODE_COOLDOWN_MS) {
            return;
        }

        submit(code.data);
    }

    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            video.srcObject = stream;
            await video.play();
        } catch {
            showMessage(strings.noCamera);

            return;
        }

        window.setInterval(scanFrame, SCAN_EVERY_MS);
    }

    // Keeps an unattended tablet's screen on, where the browser allows it.
    async function keepScreenOn() {
        try {
            await navigator.wakeLock?.request('screen');
        } catch {
            // Not supported or refused: the tablet's own sleep setting applies.
        }
    }

    keepScreenOn();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            keepScreenOn();
        }
    });
    startCamera();
}

start();
