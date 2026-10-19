// The kiosk tablet's scanner (resources/views/kiosk/scanner.blade.php).
// Reads QR codes from the camera with jsQR and posts each one to the scan
// endpoint. The tablet is identified by an HttpOnly cookie the server set
// when the setup link was opened, so this script never sees a secret.
import jsQR from 'jsqr';

const root = document.getElementById('kiosk');
const SCAN_EVERY_MS = 200;
const CLOSED_RELOAD_MS = 60000;
// 20 a minute: well inside the kiosk rate limit, which counts this
// endpoint separately from scans.
const POLL_FOR_CODE_MS = 3000;

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
        code: root.querySelector('[data-screen="code"]'),
    };
    const showCodeUrl = root.dataset.showCodeUrl;
    const showCodeSeconds = Number(root.dataset.showCodeSeconds) || 60;
    // Membership Settings; the fallbacks are the defaults.
    const successMs = (Number(root.dataset.resultSeconds) || 4) * 1000;
    const resultMs = { admitted: successMs, already_checked_in: successMs };
    const problemResultMs = (Number(root.dataset.problemResultSeconds) || 7) * 1000;
    const sameCodeCooldownMs = (Number(root.dataset.sameCodeCooldownSeconds) || 8) * 1000;
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
        if (screens.code) {
            screens.code.hidden = name !== 'code';
        }
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
            showResult('error', strings.offline, problemResultMs, backToScanning);

            return;
        }

        if (response.status === 403) {
            showMessage(strings.badKey);

            return;
        }
        if (response.status === 404) {
            showResult('closed', strings.turnedOff, problemResultMs, () => window.location.reload());

            return;
        }
        if (response.status === 429) {
            showResult('error', strings.busy, problemResultMs, backToScanning);

            return;
        }
        if (!response.ok) {
            showResult('error', strings.offline, problemResultMs, backToScanning);

            return;
        }

        const body = await response.json();

        if (body.status === 'closed') {
            showResult('closed', body.message, problemResultMs, () => window.location.reload());

            return;
        }

        showResult(body.status, body.message, resultMs[body.status] ?? problemResultMs, backToScanning);
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
        if (code.data === lastCode && Date.now() - lastCodeAt < sameCodeCooldownMs) {
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
        if (showCodeUrl && screens.code) {
            window.setInterval(pollForCode, POLL_FOR_CODE_MS);
        }
    }

    // "Show on kiosk": staff sent a member's code from the desk. Scanning
    // pauses while it's up, so the camera doesn't read the code back off
    // this screen.
    function showCode(name, qr) {
        busy = true;
        let secondsLeft = showCodeSeconds;
        const image = screens.code.querySelector('[data-code-image]');
        const countdown = screens.code.querySelector('[data-code-countdown]');
        const done = screens.code.querySelector('[data-code-done]');

        screens.code.querySelector('[data-code-name]').textContent = strings.codeFor.replace(':name', name);
        image.src = qr;
        countdown.textContent = strings.hidesIn.replace(':seconds', secondsLeft);
        show('code');

        const ticker = window.setInterval(() => {
            secondsLeft -= 1;
            countdown.textContent = strings.hidesIn.replace(':seconds', secondsLeft);
            if (secondsLeft <= 0) {
                finish();
            }
        }, 1000);

        function finish() {
            window.clearInterval(ticker);
            done.removeEventListener('click', finish);
            image.removeAttribute('src');
            backToScanning();
        }

        done.addEventListener('click', finish);
    }

    // Asks the server whether a code is waiting. Holds `busy` while asking,
    // so a scan can't start in between and the code (handed out only once)
    // is never lost. Failures are silent: the scan path already reports them.
    async function pollForCode() {
        if (busy) {
            return;
        }
        busy = true;

        let body = null;
        try {
            const response = await fetch(showCodeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 200) {
                body = await response.json();
            }
        } catch {
            // Offline for a moment: try again on the next poll.
        }

        if (body?.qr) {
            showCode(body.name, body.qr);

            return;
        }
        busy = false;
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
