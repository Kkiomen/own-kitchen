import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Keeps the screen on while a dish is being cooked.
 *
 * A phone that locks itself every thirty seconds cannot be followed with floury
 * hands, which is the entire situation this screen exists for. Two things worth
 * knowing: the lock is **released by the browser** whenever the page is hidden,
 * so it has to be retaken on the way back; and it is not available everywhere
 * (older iOS in particular), where the honest answer is to carry on without it
 * rather than to complain about a phone the cook cannot change.
 */
export function useWakeLock(): { supported: boolean; active: Ref<boolean> } {
    const supported =
        typeof navigator !== 'undefined' && 'wakeLock' in navigator;
    const active = ref(false);

    let sentinel: WakeLockSentinel | null = null;

    async function request(): Promise<void> {
        if (!supported || document.visibilityState !== 'visible') {
            return;
        }

        try {
            sentinel = await navigator.wakeLock.request('screen');
            active.value = true;
            sentinel.addEventListener('release', () => {
                active.value = false;
            });
        } catch {
            // Refused (battery saver, backgrounded mid-request). Not a failure
            // worth a message: the screen simply dims as it normally would.
            active.value = false;
        }
    }

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            void request();
        }
    }

    onMounted(() => {
        void request();
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        void sentinel?.release();
        sentinel = null;
    });

    return { supported, active };
}
