<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import qrcode from 'qrcode-generator';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import { home } from '@/routes';
import { create } from '@/routes/device-link';

const props = defineProps<{
    joinUrl: string;
    expiresAt: string;
    lifetimeMinutes: number;
}>();

/**
 * Drawn in the browser rather than fetched: the code is a credential, and one
 * that never leaves the page cannot be logged by a proxy or sit in a cache.
 */
const qrDataUrl = computed((): string => {
    const qr = qrcode(0, 'M');
    qr.addData(props.joinUrl);
    qr.make();

    return qr.createDataURL(8, 2);
});

const secondsLeft = ref(0);
let timer: number | undefined;

function tick(): void {
    const remaining = Math.floor(
        (new Date(props.expiresAt).getTime() - Date.now()) / 1000,
    );

    secondsLeft.value = Math.max(0, remaining);
}

const expired = computed((): boolean => secondsLeft.value === 0);

const countdown = computed((): string => {
    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = secondsLeft.value % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

onMounted(() => {
    tick();
    timer = window.setInterval(tick, 1000);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
});

/** A fresh visit issues a new code and invalidates this one server-side. */
function regenerate(): void {
    router.get(create.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Dodaj urządzenie" />

    <div class="min-h-dvh bg-paper">
        <AppHeader
            title="Dodaj urządzenie"
            current="recipes"
            :back="{ href: home.url(), label: 'Przepisy' }"
        />

        <main class="mx-auto max-w-md px-5 py-6 pb-24 sm:pb-6">
            <p class="text-sm leading-relaxed text-ink-muted">
                Na drugim telefonie otwórz ekran logowania i wybierz „Zaloguj
                się kodem QR”, a potem skieruj aparat na ten kod. Zaloguje na to
                samo konto — bez podawania hasła. Zwykły aparat też zadziała.
            </p>

            <div
                class="mt-6 rounded-3xl border border-rule bg-paper-raised p-6 text-center"
            >
                <div class="relative mx-auto w-fit">
                    <img
                        :src="qrDataUrl"
                        alt="Kod QR do zalogowania drugiego urządzenia"
                        class="h-56 w-56 [image-rendering:pixelated]"
                        :class="expired ? 'opacity-15' : ''"
                    />

                    <div
                        v-if="expired"
                        class="absolute inset-0 grid place-items-center"
                    >
                        <p class="text-sm font-medium text-ink">Kod wygasł</p>
                    </div>
                </div>

                <p
                    v-if="!expired"
                    class="mt-4 text-sm text-ink-muted"
                    role="timer"
                >
                    Ważny jeszcze
                    <span class="font-medium text-ink tabular-nums">
                        {{ countdown }}
                    </span>
                </p>

                <button
                    type="button"
                    class="mt-5 h-11 w-full rounded-full font-medium transition-colors"
                    :class="
                        expired
                            ? 'bg-accent text-ink'
                            : 'border border-rule-strong text-ink-muted hover:text-ink'
                    "
                    @click="regenerate"
                >
                    {{ expired ? 'Wygeneruj nowy kod' : 'Wygeneruj nowy' }}
                </button>
            </div>

            <!--
              Stated rather than buried: the code behaves exactly like a password
              for the few minutes it lives, and the owner should know that.
            -->
            <div class="mt-6 rounded-2xl bg-flag-soft p-4 text-sm text-ink">
                <p class="font-medium">Traktuj ten kod jak hasło</p>
                <p class="mt-1 leading-relaxed text-ink-muted">
                    Kto go zeskanuje albo sfotografuje, wejdzie na konto. Działa
                    tylko raz i wygasa po
                    {{ props.lifetimeMinutes }} minutach, a wygenerowanie nowego
                    unieważnia poprzedni.
                </p>
            </div>

            <details class="mt-6">
                <summary
                    class="cursor-pointer text-sm text-ink-muted underline underline-offset-4"
                >
                    Aparat nie czyta kodu?
                </summary>
                <p class="mt-3 text-sm break-all text-ink-muted">
                    Otwórz na drugim telefonie ten adres:
                    <span class="text-ink">{{ props.joinUrl }}</span>
                </p>
            </details>
        </main>

        <AppNav current="recipes" />
    </div>
</template>
