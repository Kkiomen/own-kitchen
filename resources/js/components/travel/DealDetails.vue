<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import {
    boardLabel,
    formatDates,
    formatDay,
    formatPrice,
    formatTime,
    nightsLabel,
    sourceLabel,
    typeLabel,
} from '@/lib/travel';
import type { Deal } from '@/types/travel';

/**
 * Everything the board already knows about one offer.
 *
 * It fetches nothing. The dashboard answers with whole deal objects, so opening
 * this is free — and a second request would be worse than free: a deal's id is a
 * fingerprint that changes the moment its price does, so asking for it again is
 * the one call that can answer 404 about something visibly on screen.
 */
const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ close: [] }>();

const closeButton = ref<HTMLButtonElement | null>(null);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    closeButton.value?.focus();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-contain bg-ink/40 sm:p-6"
        @click.self="emit('close')"
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="deal-title"
            class="relative mx-auto min-h-dvh w-full bg-paper-raised sm:min-h-0 sm:max-w-2xl sm:rounded-3xl"
        >
            <button
                ref="closeButton"
                type="button"
                class="absolute top-safe right-3 z-10 mt-3 flex h-11 w-11 items-center justify-center rounded-full bg-paper/90 text-ink backdrop-blur hover:text-voyage-strong sm:top-3 sm:mt-0"
                aria-label="Zamknij ofertę"
                @click="emit('close')"
            >
                <AppIcon name="cross" class="h-5 w-5" />
            </button>

            <div class="px-5 py-8 pb-safe sm:px-10 sm:py-10">
                <p class="label-caps text-voyage-strong">
                    {{ typeLabel(props.deal.type) }}
                </p>

                <h2
                    id="deal-title"
                    class="mt-2 pr-12 text-2xl leading-tight font-semibold text-balance text-ink"
                >
                    {{ props.deal.title }}
                </h2>

                <dl
                    class="mt-6 flex flex-wrap gap-x-8 gap-y-3 border-y border-rule py-4 text-sm"
                >
                    <div>
                        <dt class="label-caps text-ink-faint">
                            Cena za całość
                        </dt>
                        <dd
                            class="mt-0.5 text-xl font-semibold text-ink tabular-nums"
                        >
                            {{
                                formatPrice(
                                    props.deal.price,
                                    props.deal.currency,
                                )
                            }}
                        </dd>
                    </div>

                    <div v-if="props.deal.pricePerDay !== null">
                        <dt class="label-caps text-ink-faint">Za dzień</dt>
                        <dd class="mt-0.5 text-ink tabular-nums">
                            {{
                                formatPrice(
                                    props.deal.pricePerDay,
                                    props.deal.currency,
                                )
                            }}
                        </dd>
                    </div>

                    <!--
                        Only ever together: the median for this route is what
                        turns a percentage into an argument.
                    -->
                    <div
                        v-if="
                            props.deal.typicalPrice !== null &&
                            props.deal.discount !== null
                        "
                    >
                        <dt class="label-caps text-ink-faint">Zwykle</dt>
                        <dd class="mt-0.5 text-ink tabular-nums">
                            {{
                                formatPrice(
                                    props.deal.typicalPrice,
                                    props.deal.currency,
                                )
                            }}
                            <span class="font-medium text-accent-strong">
                                -{{ props.deal.discount }}%
                            </span>
                        </dd>
                    </div>

                    <div v-if="props.deal.days !== null">
                        <dt class="label-caps text-ink-faint">Długość</dt>
                        <dd class="mt-0.5 text-ink">
                            {{ nightsLabel(props.deal.days) }}
                        </dd>
                    </div>
                </dl>

                <!--
                    The two legs, each with its own day and clock. Drawn as
                    "wylot" and "powrót" rather than two identical rows: marking
                    both ends alike is what makes a trip read as two unrelated
                    dates.
                -->
                <section v-if="props.deal.departsAt" class="mt-6">
                    <h3 class="label-caps text-ink-faint">Przelot</h3>

                    <ol class="mt-2 space-y-2 text-sm">
                        <li class="flex flex-wrap items-baseline gap-x-3">
                            <span
                                class="rounded-full bg-voyage-soft px-2.5 py-1 text-xs text-ink"
                            >
                                wylot
                            </span>
                            <span class="text-ink">
                                {{ formatDay(props.deal.departsAt) }},
                                {{ formatTime(props.deal.departsAt) }}
                            </span>
                            <span
                                v-if="
                                    props.deal.origin && props.deal.destination
                                "
                                class="text-ink-muted"
                            >
                                {{ props.deal.origin.city }} →
                                {{ props.deal.destination.city }}
                            </span>
                        </li>

                        <li
                            v-if="props.deal.returnsAt"
                            class="flex flex-wrap items-baseline gap-x-3"
                        >
                            <span
                                class="rounded-full bg-paper-sunk px-2.5 py-1 text-xs text-ink-muted"
                            >
                                powrót
                            </span>
                            <span class="text-ink">
                                {{ formatDay(props.deal.returnsAt) }},
                                {{ formatTime(props.deal.returnsAt) }}
                            </span>
                            <span
                                v-if="
                                    props.deal.origin && props.deal.destination
                                "
                                class="text-ink-muted"
                            >
                                {{ props.deal.destination.city }} →
                                {{ props.deal.origin.city }}
                            </span>
                        </li>
                    </ol>
                </section>

                <!--
                    Alternatives, not one long stay: "4 lipca" *or* "12-15
                    września". Listed as separate rows so they cannot be read as
                    a single window.
                -->
                <section v-if="props.deal.dates.length > 0" class="mt-6">
                    <h3 class="label-caps text-ink-faint">Terminy do wyboru</h3>

                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        <li
                            v-for="(dates, index) in props.deal.dates"
                            :key="index"
                            class="rounded-full bg-voyage-soft px-3 py-1.5 text-sm text-ink"
                        >
                            {{ formatDates(dates) }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="props.deal.departureCities.length > 0"
                    class="mt-6"
                >
                    <h3 class="label-caps text-ink-faint">Wylot z</h3>
                    <p class="mt-2 text-sm text-ink">
                        {{ props.deal.departureCities.join(', ') }}
                    </p>
                </section>

                <section
                    v-if="props.deal.hotel || props.deal.board"
                    class="mt-6"
                >
                    <h3 class="label-caps text-ink-faint">Hotel</h3>
                    <p class="mt-2 text-sm text-ink">
                        <span v-if="props.deal.hotel">
                            {{ props.deal.hotel }}
                        </span>
                        <span v-if="props.deal.hotelStars !== null">
                            {{ props.deal.hotelStars }}★
                        </span>
                        <span v-if="props.deal.board" class="text-ink-muted">
                            · {{ boardLabel(props.deal.board) }}
                        </span>
                    </p>
                </section>

                <section v-if="props.deal.highlights.length > 0" class="mt-6">
                    <h3 class="label-caps text-ink-faint">W ofercie</h3>
                    <ul
                        class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink-muted"
                    >
                        <li
                            v-for="(highlight, index) in props.deal.highlights"
                            :key="index"
                        >
                            {{ highlight }}
                        </li>
                    </ul>
                </section>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a
                        :href="props.deal.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex h-13 items-center gap-2 rounded-full bg-voyage px-6 text-sm font-semibold text-ink"
                    >
                        <AppIcon name="source" />
                        Otwórz ofertę
                    </a>

                    <p class="text-xs text-ink-faint">
                        źródło: {{ sourceLabel(props.deal.source) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
