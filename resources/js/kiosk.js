// The kiosk tablet's scanner (resources/views/kiosk/scanner.blade.php).
// Reads QR codes from the camera and posts each one to the scan endpoint.
// The tablet is identified by an HttpOnly cookie the server set when the
// setup link was opened, so this script never sees a secret.
//
// It runs unattended on a battery, so it asks the camera for a small, slow
// picture, decodes with the browser's own QR reader where there is one
// (jsQR otherwise), and can turn the camera off altogether when nobody is
// there ("Kiosk camera rests after" on Membership Settings).
import jsQR from 'jsqr';

const root = document.getElementById('kiosk');
const SCAN_EVERY_MS = 200;
const CLOSED_RELOAD_MS = 60000;
// 20 a minute: well inside the kiosk rate limit, which counts this
// endpoint separately from scans.
const POLL_FOR_CODE_MS = 3000;
// With the camera resting, a code sent from the desk can wait a little
// longer, and the Wi-Fi radio gets to rest too.
const POLL_FOR_CODE_ASLEEP_MS = 10000;
const IDLE_CHECK_MS = 1000;
// Plenty for a code held up to the camera; the default is usually a much
// larger picture at 30 frames a second.
const CAMERA = { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 10, max: 15 } };

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
        sleep: root.querySelector('[data-screen="sleep"]'),
    };
    const showCodeUrl = root.dataset.showCodeUrl;
    const showCodeSeconds = Number(root.dataset.showCodeSeconds) || 60;
    // Membership Settings; the fallbacks are the defaults.
    const successMs = (Number(root.dataset.resultSeconds) || 4) * 1000;
    const resultMs = { admitted: successMs, already_checked_in: successMs };
    const problemResultMs = (Number(root.dataset.problemResultSeconds) || 7) * 1000;
    const sameCodeCooldownMs = (Number(root.dataset.sameCodeCooldownSeconds) || 8) * 1000;
    // 0: the camera never rests.
    const idleMs = screens.sleep ? (Number(root.dataset.idleSeconds) || 0) * 1000 : 0;
    const result = root.querySelector('[data-result]');
    const video = root.querySelector('[data-video]');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    let busy = false;
    let asleep = false;
    let decoding = false;
    let detector = null;
    let scanTimer = null;
    let lastActivityAt = Date.now();
    let lastCode = null;
    let lastCodeAt = 0;
    let resultTimer = null;

    function show(name) {
        Object.entries(screens).forEach(([screen, element]) => {
            if (element) {
                element.hidden = screen !== name;
            }
        });
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
        lastActivityAt = Date.now();
        show(asleep ? 'sleep' : 'scan');
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

    // The browser's own QR reader (Chrome on Android), which costs far less
    // than decoding in script. Null where there isn't one (Safari, Firefox).
    async function nativeDetector() {
        try {
            if ('BarcodeDetector' in window && (await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
                return new window.BarcodeDetector({ formats: ['qr_code'] });
            }
        } catch {
            // Fall through to jsQR.
        }

        return null;
    }

    function decodeWithJsQr() {
        // Downscaled: plenty for a code held up to the camera, and much
        // cheaper to decode five times a second.
        const width = Math.min(640, video.videoWidth);
        const height = Math.round(video.videoHeight * (width / video.videoWidth));
        canvas.width = width;
        canvas.height = height;
        context.drawImage(video, 0, 0, width, height);

        return jsQR(context.getImageData(0, 0, width, height).data, width, height, { inversionAttempts: 'dontInvert' })?.data || null;
    }

    async function decode() {
        if (detector) {
            try {
                return (await detector.detect(video))[0]?.rawValue || null;
            } catch {
                // It stopped working: use jsQR from here on.
                detector = null;
            }
        }

        return decodeWithJsQr();
    }

    async function scanFrame() {
        if (busy || asleep || decoding || video.readyState < video.HAVE_ENOUGH_DATA) {
            return;
        }

        decoding = true;
        let code = null;
        try {
            code = await decode();
        } finally {
            decoding = false;
        }

        // A code was sent from the desk, or the camera went to rest, while
        // this frame was being read.
        if (!code || busy || asleep) {
            return;
        }

        // Someone is here, even if it's a code already scanned.
        lastActivityAt = Date.now();
        if (code === lastCode && Date.now() - lastCodeAt < sameCodeCooldownMs) {
            return;
        }

        submit(code);
    }

    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: CAMERA, audio: false });
            video.srcObject = stream;
            await video.play();
        } catch {
            showMessage(strings.noCamera);

            return false;
        }

        scanTimer = window.setInterval(scanFrame, SCAN_EVERY_MS);

        return true;
    }

    function stopCamera() {
        window.clearInterval(scanTimer);
        video.srcObject?.getTracks().forEach((track) => track.stop());
        video.srcObject = null;
    }

    // Nobody has held up a code for a while: turn the camera off, which is
    // most of what the tablet's battery goes on, until someone taps.
    function restIfIdle() {
        if (busy || asleep || Date.now() - lastActivityAt < idleMs) {
            return;
        }

        asleep = true;
        stopCamera();
        document.body.classList.add('asleep');
        show('sleep');
    }

    async function wake() {
        if (!asleep || busy) {
            return;
        }

        // Held so a code from the desk can't land while the camera starts.
        busy = true;
        asleep = false;
        document.body.classList.remove('asleep');
        if (await startCamera()) {
            backToScanning();
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

    // One poll after another, so the wait can stretch while the camera rests.
    async function pollLoop() {
        await pollForCode();
        window.setTimeout(pollLoop, asleep ? POLL_FOR_CODE_ASLEEP_MS : POLL_FOR_CODE_MS);
    }

    // Keeps an unattended tablet's screen on, where the browser allows it.
    async function keepScreenOn() {
        try {
            await navigator.wakeLock?.request('screen');
        } catch {
            // Not supported or refused: the tablet's own sleep setting applies.
        }
    }

    async function begin() {
        detector = await nativeDetector();
        if (!(await startCamera())) {
            return;
        }

        if (showCodeUrl && screens.code) {
            window.setTimeout(pollLoop, POLL_FOR_CODE_MS);
        }
        if (idleMs > 0) {
            screens.sleep.querySelector('[data-wake]').addEventListener('click', wake);
            window.setInterval(restIfIdle, IDLE_CHECK_MS);
        }
    }

    keepScreenOn();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            keepScreenOn();
        }
    });
    begin();
}

start();
