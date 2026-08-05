<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AuthShell from '@/components/AuthShell.vue';
import { useQrScanner, tokenFrom } from '@/lib/qr-scan';
import { login } from '@/routes';
import { redeem } from '@/routes/device-link';

const video = ref<HTMLVideoElement | null>(null);

/**
 * A full page load rather than an Inertia visit: what is on the other side is a
 * different account than the one this browser is holding, so the whole app has
 * to be rebuilt around it.
 */
function enter(token: string): void {
    window.location.href = redeem.url({ token });
}

const { status, foreign, start } = useQrScanner(video, enter);

onMounted(() => {
    void start();
});

/**
 * The way in when the camera is not an option — an insecure origin, a refused
 * permission, a laptop with no camera. The other phone shows the same address
 * as text under "Aparat nie czyta kodu?", so there is always something to paste.
 */
const pasted = ref('');
const pasteRejected = ref(false);

function submitPasted(): void {
    const token = tokenFrom(pasted.value);

    if (token === null) {
        pasteRejected.value = true;

        return;
    }

    enter(token);
}
</script>

<template>
    <Head title="Zaloguj kodem QR" />

    <AuthShell
        title="Zeskanuj kod"
        subtitle="Poproś drugą osobę o kod QR z ekranu „Dodaj urządzenie” i skieruj na niego aparat."
    >
        <div
            class="relative aspect-square w-full overflow-hidden rounded-3xl bg-ink"
        >
            <!--
              `playsinline` is not optional: without it iOS takes the preview
              full screen in its own player and the frames stop arriving here.
            -->
            <video
                ref="video"
                class="h-full w-full object-cover"
                autoplay
                muted
                playsinline
            ></video>

            <!-- A frame to aim with; the whole picture is what gets decoded. -->
            <div
                v-if="status === 'scanning'"
                class="pointer-events-none absolute inset-0 grid place-items-center"
                aria-hidden="true"
            >
                <div
                    class="h-2/3 w-2/3 rounded-2xl border-2 border-accent/80"
                ></div>
            </div>

            <div
                v-if="status !== 'scanning'"
                class="absolute inset-0 grid place-items-center bg-ink px-6 text-center"
            >
                <p v-if="status === 'starting'" class="text-sm text-paper">
                    Włączam aparat…
                </p>
                <p
                    v-else-if="status === 'denied'"
                    class="text-sm leading-relaxed text-paper"
                >
                    Brak zgody na aparat. Włącz ją dla tej strony w ustawieniach
                    przeglądarki albo wpisz kod ręcznie poniżej.
                </p>
                <p
                    v-else-if="status === 'unsupported'"
                    class="text-sm leading-relaxed text-paper"
                >
                    Aparat działa tylko na połączeniu HTTPS. Otwórz aplikację
                    przez adres z „https://” albo wpisz kod ręcznie poniżej.
                </p>
                <p v-else class="text-sm leading-relaxed text-paper">
                    Nie udało się uruchomić aparatu. Wpisz kod ręcznie poniżej.
                </p>
            </div>
        </div>

        <!--
          Reported rather than acted on: pointing the phone at some other QR code
          must not navigate anywhere, and must not look like a broken scanner
          either.
        -->
        <p
            v-if="foreign && status === 'scanning'"
            class="mt-4 text-center text-sm text-ink-muted"
            role="status"
        >
            To nie jest kod tej aplikacji. Szukam dalej…
        </p>

        <details class="mt-6" :open="status !== 'scanning'">
            <summary
                class="cursor-pointer text-sm text-ink-muted underline underline-offset-4"
            >
                Wpisz kod ręcznie
            </summary>

            <form class="mt-3 space-y-3" @submit.prevent="submitPasted">
                <label for="paste" class="block text-sm text-ink-muted">
                    Wklej adres z drugiego telefonu
                </label>
                <input
                    id="paste"
                    v-model="pasted"
                    type="text"
                    inputmode="url"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    placeholder="https://…/dolacz/…"
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                    @input="pasteRejected = false"
                />
                <p v-if="pasteRejected" class="text-sm text-accent-strong">
                    To nie wygląda na kod logowania.
                </p>
                <button
                    type="submit"
                    class="h-12 w-full rounded-full bg-accent font-medium text-ink"
                >
                    Zaloguj
                </button>
            </form>
        </details>

        <p class="mt-8 text-center text-sm">
            <Link :href="login.url()" class="text-ink-muted underline-offset-4">
                Wróć do logowania hasłem
            </Link>
        </p>
    </AuthShell>
</template>
