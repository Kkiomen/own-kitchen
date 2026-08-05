import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Reading a login code with the app's own camera, so joining a household is one
 * button on the login screen rather than "leave the app, open the camera app,
 * point it at the other phone, tap the notification".
 *
 * Two rules are load-bearing, and both are about what this must *not* do:
 *
 * - **A scanned code is never followed as a URL.** The camera is pointed at
 *   whatever happens to be in front of it, and a QR code is an address someone
 *   else chose. So only the token is read out of the text and it is handed to
 *   *our* route; anything else is reported as "not a code for this app" and the
 *   scanning simply carries on.
 * - **The token, not the address, is what travels.** The code is generated from
 *   `APP_URL`, which is regularly not the address this phone is browsing (the
 *   Docker note about `localhost:8001` is exactly this). Taking the token out
 *   and rebuilding the link here makes the scan work anyway.
 */

/**
 * `Str::random(48)`, so alphanumeric and long. Matched loosely inside a URL —
 * the server is the authority on whether a code is real — but a bare paste has
 * to look like the whole thing rather than like a word.
 */
const TOKEN_IN_URL = /\/dolacz\/([A-Za-z0-9]{20,})/;
const BARE_TOKEN = /^[A-Za-z0-9]{40,}$/;

export function tokenFrom(scanned: string): string | null {
    const text = scanned.trim();

    if (BARE_TOKEN.test(text)) {
        return text;
    }

    return TOKEN_IN_URL.exec(text)?.[1] ?? null;
}

export type ScanStatus =
    /** No camera to ask for: an insecure origin, or a device without one. */
    | 'unsupported'
    | 'starting'
    | 'scanning'
    /** Asked and refused. Recoverable only in the browser's own settings. */
    | 'denied'
    | 'error';

/**
 * The frame rate to decode at. Well below the camera's own: reading a code is
 * over in a second either way, and a decode on every frame heats the phone for
 * nothing.
 */
const DECODE_INTERVAL_MS = 120;

/** Enough for a code held at arm's length, and four times faster to decode. */
const MAX_DECODE_WIDTH = 640;

interface QrDetector {
    detect(source: CanvasImageSource): Promise<Array<{ rawValue: string }>>;
}

interface BarcodeDetectorApi {
    new (options: { formats: string[] }): QrDetector;
    getSupportedFormats(): Promise<string[]>;
}

/**
 * The native detector where there is one (Android Chrome), jsQR everywhere else
 * (notably iOS, which has no `BarcodeDetector` at all). The fallback is loaded
 * only when it is needed, so the phone that has the native one never downloads
 * the decoder.
 */
async function detector(): Promise<QrDetector> {
    const api = (window as unknown as { BarcodeDetector?: BarcodeDetectorApi })
        .BarcodeDetector;

    if (api !== undefined) {
        try {
            const formats = await api.getSupportedFormats();

            if (formats.includes('qr_code')) {
                return new api({ formats: ['qr_code'] });
            }
        } catch {
            // Present but unusable — fall through to the decoder below.
        }
    }

    const { default: jsQR } = await import('jsqr');

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return {
        async detect(source): Promise<Array<{ rawValue: string }>> {
            const video = source as HTMLVideoElement;
            const scale = Math.min(1, MAX_DECODE_WIDTH / video.videoWidth);
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);

            if (context === null || canvas.width === 0) {
                return [];
            }

            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const image = context.getImageData(
                0,
                0,
                canvas.width,
                canvas.height,
            );
            const found = jsQR(image.data, image.width, image.height, {
                inversionAttempts: 'dontInvert',
            });

            return found === null ? [] : [{ rawValue: found.data }];
        },
    };
}

export interface Scanner {
    status: Ref<ScanStatus>;
    /** Something was read that is not a code for this app. */
    foreign: Ref<boolean>;
    start(): Promise<void>;
    stop(): void;
}

/**
 * @param video The preview element. It is what the frames are read from, so the
 *              camera cannot be started before it exists.
 * @param onToken Called once, with the scanning already stopped.
 */
export function useQrScanner(
    video: Ref<HTMLVideoElement | null>,
    onToken: (token: string) => void,
): Scanner {
    const status = ref<ScanStatus>('starting');
    const foreign = ref(false);

    let stream: MediaStream | null = null;
    let frame = 0;
    let lastDecodeAt = 0;
    let done = false;

    function stop(): void {
        window.cancelAnimationFrame(frame);
        frame = 0;

        stream?.getTracks().forEach((track) => track.stop());
        stream = null;

        if (video.value !== null) {
            video.value.srcObject = null;
        }
    }

    async function read(qr: QrDetector): Promise<void> {
        const element = video.value;

        if (element === null || element.videoWidth === 0) {
            return;
        }

        const codes = await qr.detect(element);
        const scanned = codes[0]?.rawValue;

        if (scanned === undefined) {
            return;
        }

        const token = tokenFrom(scanned);

        if (token === null) {
            // Carry on scanning: a poster on the wall behind the phone is not a
            // failure, and stopping would make it look like one.
            foreign.value = true;

            return;
        }

        done = true;
        stop();
        onToken(token);
    }

    async function start(): Promise<void> {
        // `getUserMedia` exists only on a secure origin. Worth telling apart
        // from a refusal: one is fixed in the browser's settings, the other by
        // opening the app over https.
        if (
            !window.isSecureContext ||
            navigator.mediaDevices?.getUserMedia === undefined
        ) {
            status.value = 'unsupported';

            return;
        }

        status.value = 'starting';
        foreign.value = false;
        done = false;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                // `ideal`, not `exact`: a laptop has only the one camera and
                // demanding the back one there fails outright.
                video: { facingMode: { ideal: 'environment' } },
            });
        } catch (error) {
            const name = (error as DOMException).name;
            status.value =
                name === 'NotAllowedError' || name === 'SecurityError'
                    ? 'denied'
                    : 'error';

            return;
        }

        const element = video.value;

        if (element === null) {
            stop();
            status.value = 'error';

            return;
        }

        element.srcObject = stream;
        await element.play();

        const qr = await detector();
        status.value = 'scanning';

        const tick = (now: number): void => {
            frame = window.requestAnimationFrame(tick);

            if (done || now - lastDecodeAt < DECODE_INTERVAL_MS) {
                return;
            }

            lastDecodeAt = now;
            void read(qr);
        };

        frame = window.requestAnimationFrame(tick);
    }

    // Leaving the screen with the camera light still on is the kind of thing
    // that gets an app deleted.
    onBeforeUnmount(stop);

    return { status, foreign, start, stop };
}
