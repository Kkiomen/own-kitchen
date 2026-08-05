<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import {
    boardLabel,
    formatDates,
    formatJourney,
    formatPrice,
    nightsLabel,
    scoreVerdict,
    sourceLabel,
    typeChip,
} from '@/lib/travel';
import type { Deal } from '@/types/travel';

const props = defineProps<{
    deal: Deal;
    /** `thresholds.score` — what the deals app itself calls a good rating. */
    goodScore: number | null;
}>();

const emit = defineEmits<{ details: [] }>();

/**
 * Where it goes. A blog trip has no airport codes at all, so it names its
 * destination in words instead — and one of the two is always present.
 */
const route = computed<string | null>(() => {
    const { origin, destination, tripDestination } = props.deal;

    if (origin && destination) {
        return `${origin.city} → ${destination.city}`;
    }

    return tripDestination;
});

/**
 * The whole argument for a steal, and it is only ever shown with the price it
 * beats: "-43%" on its own is a number nobody can check.
 */
const versusTypical = computed<string | null>(() => {
    const { discount, typicalPrice, currency } = props.deal;

    if (discount === null || typicalPrice === null) {
        return null;
    }

    return `-${discount}% wobec zwykłych ${formatPrice(typicalPrice, currency)}`;
});

const verdict = computed<string | null>(() => {
    const { score } = props.deal;

    return score === null || props.goodScore === null
        ? null
        : scoreVerdict(score, props.goodScore);
});

/** Good is green, the rest is a note in the margin — never colour alone. */
const scoreTone = computed<string>(() =>
    verdict.value === 'okazja'
        ? 'bg-accent-soft text-accent-strong'
        : 'bg-paper-sunk text-ink-muted',
);
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-2xl border border-rule bg-paper-raised p-4"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="label-caps text-voyage-strong">
                    {{ typeChip(props.deal.type) }}
                </span>
                <span class="text-xs text-ink-faint">
                    {{ sourceLabel(props.deal.source) }}
                </span>
            </p>

            <!-- Rating and the word for it: a colour alone is not a verdict. -->
            <p
                v-if="props.deal.score !== null && verdict"
                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                :class="scoreTone"
            >
                <span class="tabular-nums">{{ props.deal.score }}</span>
                · {{ verdict }}
            </p>
        </div>

        <h3 class="text-base leading-snug font-semibold text-balance text-ink">
            <a
                :href="props.deal.url"
                target="_blank"
                rel="noopener noreferrer"
                class="hover:text-voyage-strong"
            >
                {{ props.deal.title }}
            </a>
        </h3>

        <p v-if="route" class="flex items-center gap-2 text-sm text-ink-muted">
            <AppIcon name="pin" class="text-voyage-strong" />
            {{ route }}
        </p>

        <!--
            The journey, as an arrow rather than a range: two dates joined by a
            dash read as "somewhere between", which is not what a flight is.
        -->
        <p
            v-if="formatJourney(props.deal)"
            class="flex items-center gap-2 text-sm text-ink-muted"
        >
            <AppIcon name="calendar" class="text-voyage-strong" />
            {{ formatJourney(props.deal) }}
        </p>

        <!-- A blog trip's terms are alternatives, so each is its own line. -->
        <ul
            v-else-if="props.deal.dates.length > 0"
            class="flex flex-wrap gap-1.5"
        >
            <li
                v-for="(dates, index) in props.deal.dates.slice(0, 3)"
                :key="index"
                class="rounded-full bg-voyage-soft px-2.5 py-1 text-xs text-ink"
            >
                {{ formatDates(dates) }}
            </li>
            <li
                v-if="props.deal.dates.length > 3"
                class="self-center text-xs text-ink-faint"
            >
                +{{ props.deal.dates.length - 3 }}
            </li>
        </ul>

        <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
            <p>
                <span class="text-2xl font-semibold text-ink tabular-nums">
                    {{ formatPrice(props.deal.price, props.deal.currency) }}
                </span>
                <!--
                    Always said out loud. The number is the whole offer — both
                    legs of a return, the entire package for a trip — and a price
                    whose scope is guessed at is a price nobody can compare.
                -->
                <span class="ml-1.5 text-xs text-ink-faint">
                    za całość, {{ typeChip(props.deal.type) }}
                </span>
            </p>

            <p
                v-if="versusTypical"
                class="text-xs font-medium text-accent-strong"
            >
                {{ versusTypical }}
            </p>
        </div>

        <ul class="flex flex-wrap gap-1.5 text-xs">
            <li
                v-if="props.deal.weekend"
                class="rounded-full bg-paper-sunk px-2.5 py-1 text-ink-muted"
            >
                na weekend
            </li>
            <li
                v-if="props.deal.steal"
                class="rounded-full bg-accent-soft px-2.5 py-1 font-medium text-accent-strong"
            >
                wyjątkowo tanio
            </li>
            <!-- Dropped entirely when unknown: "? nocy" is not an answer. -->
            <li
                v-if="props.deal.days !== null"
                class="rounded-full bg-paper-sunk px-2.5 py-1 text-ink-muted"
            >
                {{ nightsLabel(props.deal.days) }}
            </li>
            <li
                v-if="props.deal.board"
                class="rounded-full bg-paper-sunk px-2.5 py-1 text-ink-muted"
            >
                {{ boardLabel(props.deal.board) }}
            </li>
            <li
                v-if="props.deal.hotelStars !== null"
                class="rounded-full bg-paper-sunk px-2.5 py-1 text-ink-muted"
            >
                hotel {{ props.deal.hotelStars }}★
            </li>
        </ul>

        <div class="mt-1 flex flex-wrap items-center gap-2">
            <a
                :href="props.deal.url"
                target="_blank"
                rel="noopener noreferrer"
                class="flex h-11 items-center gap-2 rounded-full bg-voyage px-4 text-sm font-semibold text-ink"
            >
                <AppIcon name="source" />
                Otwórz ofertę
            </a>

            <button
                v-if="props.deal.hasDetails"
                type="button"
                class="flex h-11 items-center rounded-full border border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                @click="emit('details')"
            >
                Szczegóły
            </button>
        </div>
    </article>
</template>
