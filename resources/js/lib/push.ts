import { router } from '@inertiajs/vue3';
import { subscribe, unsubscribe } from '@/routes/push';

/**
 * Turning this phone's notifications on and off.
 *
 * Two things have to agree and neither can be inferred from the other: the
 * browser's permission, which only the browser knows, and our row, which only
 * the server knows. Every function here writes both, and `currentState()` asks
 * the browser rather than the server — a permission revoked in iOS settings
 * leaves a row behind, and believing the row would show a switch that is on and
 * a phone that never rings.
 */

/**
 * Which phone this is, in our own terms.
 *
 * Minted once and kept for good. It is what stops the notification coming back
 * to the hand that just wrote the task, so it must survive a reload — and it
 * must not be the push endpoint, which the browser is free to replace without
 * telling anybody.
 */
const DEVICE_KEY = 'kuchnia:device';

export type PushState =
    /** No service worker, or a browser without the Push API. Offer nothing. */
    | 'unsupported'
    /** Refused, and only the phone's settings can undo it. Say so. */
    | 'blocked'
    | 'off'
    | 'on';

export function deviceId(): string {
    let id = window.localStorage.getItem(DEVICE_KEY);

    if (id === null) {
        id =
            typeof crypto.randomUUID === 'function'
                ? crypto.randomUUID()
                : Math.random().toString(36).slice(2) + Date.now().toString(36);

        window.localStorage.setItem(DEVICE_KEY, id);
    }

    return id;
}

/**
 * Whether the switch is worth drawing at all.
 *
 * Note this is false during development: `app.ts` registers the worker only in
 * production, so a stale cache cannot shadow a rebuild. Notifications therefore
 * cannot be tried against `npm run dev` — it takes a build.
 */
export function pushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        typeof Notification !== 'undefined'
    );
}

export async function currentState(): Promise<PushState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    const registration = await navigator.serviceWorker.getRegistration();

    if (!registration) {
        return 'unsupported';
    }

    const existing = await registration.pushManager.getSubscription();

    return existing ? 'on' : 'off';
}

/**
 * Ask, subscribe, and tell the server — in that order, and all on the tap.
 *
 * The permission prompt has to come from a gesture; iOS ignores one raised on
 * page load, and Chrome holds it against the origin. This is the same rule the
 * cooking timer follows when it asks on the tap that starts a countdown.
 */
export async function enablePush(publicKey: string): Promise<PushState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return permission === 'denied' ? 'blocked' : 'off';
    }

    const registration = await navigator.serviceWorker.getRegistration();

    if (!registration) {
        return 'unsupported';
    }

    /*
     * Reusing an existing subscription rather than replacing it: re-subscribing
     * mints a new endpoint and orphans the row we already hold, so a phone that
     * merely re-opened the screen would accumulate dead rows.
     */
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            // Required by every browser, and the promise the `push` handler in
            // sw.js keeps by always showing something.
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(publicKey),
        }));

    const keys = subscription.toJSON().keys;

    if (!keys?.p256dh || !keys.auth) {
        return 'off';
    }

    await post(subscribe.url(), {
        endpoint: subscription.endpoint,
        public_key: keys.p256dh,
        auth_token: keys.auth,
        device: deviceId(),
        label: deviceLabel(),
    });

    return 'on';
}

/**
 * Off means off on both sides. The browser subscription is dropped first: if the
 * request then fails, the server holds a row for an endpoint that no longer
 * accepts anything, which the next delivery cleans up on its own (404/410). The
 * other order would leave a phone that still rings with the switch showing off.
 */
export async function disablePush(): Promise<PushState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    const registration = await navigator.serviceWorker.getRegistration();
    const subscription = await registration?.pushManager.getSubscription();

    if (!subscription) {
        return 'off';
    }

    const { endpoint } = subscription;

    await subscription.unsubscribe();

    if (navigator.clearAppBadge) {
        void navigator.clearAppBadge().catch(() => undefined);
    }

    await post(unsubscribe.url(), { endpoint }, 'delete');

    return 'off';
}

/**
 * Inertia rather than `fetch`, so the CSRF token and the session travel exactly
 * as they do everywhere else. `only` keeps it to the one cheap prop: nothing on
 * the tasks screen changed, and re-running the list query would be work for a
 * result nobody is waiting to see.
 */
function post(
    url: string,
    data: Record<string, string>,
    method: 'post' | 'delete' = 'post',
): Promise<void> {
    return new Promise((resolve, reject) => {
        router[method](url, data, {
            preserveScroll: true,
            preserveUrl: true,
            only: ['openTasks'],
            onSuccess: () => resolve(),
            onError: () =>
                reject(new Error('Nie udało się zapisać ustawienia.')),
        });
    });
}

/** Something to recognise the row by. A hint, never an identity. */
function deviceLabel(): string {
    const agent = navigator.userAgent;

    if (/iPhone|iPad/.test(agent)) {
        return 'iPhone';
    }

    if (/Android/.test(agent)) {
        return 'Android';
    }

    return 'Przeglądarka';
}

/**
 * The VAPID public key as the Push API wants it: raw bytes, not the URL-safe
 * base64 it is transported and stored as.
 */
function applicationServerKey(publicKey: string): Uint8Array<ArrayBuffer> {
    const padded = publicKey.padEnd(
        publicKey.length + ((4 - (publicKey.length % 4)) % 4),
        '=',
    );
    const binary = window.atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
    /*
     * Backed by a plain ArrayBuffer, stated rather than inferred: `Uint8Array`
     * alone widens to `ArrayBufferLike`, which includes SharedArrayBuffer and is
     * therefore not a `BufferSource` the Push API will take.
     */
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}
