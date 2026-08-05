import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * The kitchen timer, and the one rule that makes it survive being backgrounded:
 * **a timer is a deadline, never a countdown.**
 *
 * A phone freezes or throttles a hidden page's `setInterval` — that is not a bug
 * to work around, it is how every mobile browser saves battery. So nothing here
 * counts down. The state holds the wall-clock moment the step is due (`endsAt`),
 * and what is left is always recomputed from the clock. The interval below only
 * decides how often the screen redraws; if it stops ticking for four minutes
 * while the app is in the background, the number on screen when you come back is
 * still exactly right.
 *
 * The same reason it is persisted: closing the app, or a reload, or opening the
 * dish on the other phone, all land on a deadline that is still true.
 */
export interface TimerState {
    recipeSlug: string;
    stepPosition: number;
    /** What is being waited for, shown in the notification. */
    label: string;
    durationSeconds: number;
    /** Epoch milliseconds when it is due. Null only while paused. */
    endsAt: number | null;
    /** What was left when it was paused. Null while running. */
    pausedMs: number | null;
    /** Set once the cook has seen it go off, so it stops shouting. */
    acknowledged: boolean;
}

const STORAGE_KEY = 'kuchnia:cook-timer';

/** How often the screen redraws. Nothing about the deadline depends on it. */
const TICK_MS = 250;

export function remainingMs(state: TimerState, now: number): number {
    if (state.pausedMs !== null) {
        return state.pausedMs;
    }

    return Math.max(0, (state.endsAt ?? now) - now);
}

export function isFinished(state: TimerState, now: number): boolean {
    return state.pausedMs === null && remainingMs(state, now) === 0;
}

/** mm:ss, and h:mm:ss only once there is an hour to show. */
export function formatRemaining(ms: number): string {
    const total = Math.ceil(ms / 1000);
    const seconds = total % 60;
    const minutes = Math.floor(total / 60) % 60;
    const hours = Math.floor(total / 3600);
    const pad = (value: number): string => String(value).padStart(2, '0');

    if (hours > 0) {
        return `${hours}:${pad(minutes)}:${pad(seconds)}`;
    }

    return `${minutes}:${pad(seconds)}`;
}

function read(): TimerState | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const raw = window.localStorage.getItem(STORAGE_KEY);

    if (raw === null) {
        return null;
    }

    try {
        return JSON.parse(raw) as TimerState;
    } catch {
        // A half-written or outdated entry is not worth a broken screen.
        window.localStorage.removeItem(STORAGE_KEY);

        return null;
    }
}

function write(state: TimerState | null): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (state === null) {
        window.localStorage.removeItem(STORAGE_KEY);

        return;
    }

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

/**
 * Hands the deadline to the service worker so the alarm can go off while the
 * app is in the background.
 *
 * Best effort, and deliberately not the only mechanism: a worker can be shut
 * down by the phone at any moment, and on iOS it usually is. The clock is
 * exact regardless — this only decides whether you are *told* without looking.
 */
function tellWorker(message: Record<string, unknown>): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    void navigator.serviceWorker.ready
        .then((registration) => registration.active?.postMessage(message))
        .catch(() => {
            // No worker (dev, or an unsupported browser): the page's own alarm
            // still fires whenever the screen is on, which is the common case.
        });
}

async function notify(state: TimerState): Promise<void> {
    if (
        typeof Notification === 'undefined' ||
        Notification.permission !== 'granted'
    ) {
        return;
    }

    const options: NotificationOptions = {
        body: state.label,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: 'kuchnia-cook-timer',
        data: { url: `/przepis/${state.recipeSlug}/gotowanie` },
        requireInteraction: true,
    };

    if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.getRegistration();

        if (registration) {
            await registration.showNotification('Minutnik skończył', options);

            return;
        }
    }

    // Without a worker this only shows while the page is alive, which is why it
    // is the fallback rather than the plan.
    new Notification('Minutnik skończył', options);
}

/**
 * The sound. Built from an oscillator rather than a file: three notes are not
 * worth an asset, and one that failed to load would be a silent alarm.
 *
 * Created on the tap that starts a timer, because a context made without a
 * gesture starts suspended and would never make a sound.
 */
class Chime {
    private context: AudioContext | null = null;

    public arm(): void {
        if (this.context !== null || typeof AudioContext === 'undefined') {
            return;
        }

        this.context = new AudioContext();
    }

    public ring(): void {
        const context = this.context;

        if (context === null) {
            return;
        }

        void context.resume();

        // Three rising beeps: long enough to hear from the sink, short enough
        // not to be the thing you remember about the app.
        [0, 0.28, 0.56].forEach((offset, index) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const at = context.currentTime + offset;

            oscillator.frequency.value = 660 + index * 220;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(0.3, at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.22);

            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start(at);
            oscillator.stop(at + 0.24);
        });
    }
}

export interface CookTimer {
    state: Ref<TimerState | null>;
    /** Redrawn on every tick; the truth is `state.endsAt`. */
    remaining: Ref<number>;
    finished: Ref<boolean>;
    running: Ref<boolean>;
    start: (step: { position: number; label: string; seconds: number }) => void;
    pause: () => void;
    resume: () => void;
    extend: (seconds: number) => void;
    stop: () => void;
}

/**
 * One timer at a time, on purpose. Two pots is a real thing a cook does, but a
 * second clock needs somewhere to live on a phone screen already holding a step,
 * and a timer you cannot see is worse than none — this can grow when the screen
 * has been earned, not before.
 */
export function useCookTimer(recipeSlug: string): CookTimer {
    const state = ref<TimerState | null>(null);
    const remaining = ref(0);
    const finished = ref(false);
    const running = ref(false);
    const chime = new Chime();

    let ticker: ReturnType<typeof setInterval> | null = null;

    function refresh(): void {
        const current = state.value;

        if (current === null) {
            remaining.value = 0;
            finished.value = false;
            running.value = false;

            return;
        }

        const now = Date.now();
        remaining.value = remainingMs(current, now);
        running.value = current.pausedMs === null && remaining.value > 0;

        const justFinished = isFinished(current, now);

        // The alarm fires here rather than on a timeout, so it is correct
        // whether the page ticked all the way through or was frozen and thawed.
        if (justFinished && !finished.value) {
            finished.value = true;

            if (!current.acknowledged) {
                /*
                 * Marked before ringing, and persisted: coming back to this
                 * screen mounts the composable again, and an alarm that went off
                 * while you were away must greet you once — not every time you
                 * look at the dish.
                 */
                current.acknowledged = true;
                write(current);

                chime.ring();
                navigator.vibrate?.([300, 120, 300]);
                void notify(current);
            }
        }

        if (!justFinished) {
            finished.value = false;
        }
    }

    function commit(next: TimerState | null): void {
        state.value = next;
        write(next);
        refresh();
    }

    function start(step: {
        position: number;
        label: string;
        seconds: number;
    }): void {
        chime.arm();

        // Asked for on the tap that needs it, never on page load: a permission
        // prompt nobody has earned is how people say no for good.
        if (
            typeof Notification !== 'undefined' &&
            Notification.permission === 'default'
        ) {
            void Notification.requestPermission();
        }

        const endsAt = Date.now() + step.seconds * 1000;

        commit({
            recipeSlug,
            stepPosition: step.position,
            label: step.label,
            durationSeconds: step.seconds,
            endsAt,
            pausedMs: null,
            acknowledged: false,
        });

        tellWorker({
            type: 'cook-timer:set',
            endsAt,
            body: step.label,
            url: `/przepis/${recipeSlug}/gotowanie`,
        });
    }

    function pause(): void {
        const current = state.value;

        if (current === null || current.pausedMs !== null) {
            return;
        }

        commit({
            ...current,
            pausedMs: remainingMs(current, Date.now()),
            endsAt: null,
        });
        tellWorker({ type: 'cook-timer:clear' });
    }

    function resume(): void {
        const current = state.value;

        if (current === null || current.pausedMs === null) {
            return;
        }

        const endsAt = Date.now() + current.pausedMs;

        commit({ ...current, endsAt, pausedMs: null });
        tellWorker({
            type: 'cook-timer:set',
            endsAt,
            body: current.label,
            url: `/przepis/${recipeSlug}/gotowanie`,
        });
    }

    /** "Jeszcze minutka" — the commonest thing that happens to a kitchen timer. */
    function extend(seconds: number): void {
        const current = state.value;

        if (current === null) {
            return;
        }

        if (current.pausedMs !== null) {
            commit({ ...current, pausedMs: current.pausedMs + seconds * 1000 });

            return;
        }

        // From now when it has already gone off, so "+1 min" is a minute rather
        // than a deadline in the past plus a minute, which would still be past.
        const from = Math.max(current.endsAt ?? Date.now(), Date.now());
        const endsAt = from + seconds * 1000;

        commit({ ...current, endsAt, acknowledged: false });
        tellWorker({
            type: 'cook-timer:set',
            endsAt,
            body: current.label,
            url: `/przepis/${recipeSlug}/gotowanie`,
        });
    }

    function stop(): void {
        commit(null);
        tellWorker({ type: 'cook-timer:clear' });
    }

    onMounted(() => {
        const stored = read();

        // Somebody else's dish, still running: leave it alone rather than
        // showing this recipe a clock that belongs to another one.
        state.value =
            stored !== null && stored.recipeSlug === recipeSlug ? stored : null;

        // It may well have gone off while the app was closed; `refresh()` is
        // what notices, exactly as it does on any other tick.
        refresh();
        ticker = setInterval(refresh, TICK_MS);
        document.addEventListener('visibilitychange', refresh);
    });

    onBeforeUnmount(() => {
        if (ticker !== null) {
            clearInterval(ticker);
        }

        document.removeEventListener('visibilitychange', refresh);
    });

    return {
        state,
        remaining,
        finished,
        running,
        start,
        pause,
        resume,
        extend,
        stop,
    };
}
