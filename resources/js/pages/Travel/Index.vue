<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import DealCard from '@/components/travel/DealCard.vue';
import DealDetails from '@/components/travel/DealDetails.vue';
import TravelFilters from '@/components/travel/TravelFilters.vue';
import { formatPrice, typeLabel } from '@/lib/travel';
import { index as travel } from '@/routes/travel';
import type {
    AirportOption,
    Deal,
    DealTotals,
    TravelFilters as Filters,
    TravelMeta,
} from '@/types/travel';

/**
 * "Gdzie polecieć, żeby wyszło tanio."
 *
 * A window onto our flight-deals app rather than a second copy of it: this
 * screen stores nothing and writes nothing, and every control is a link. The
 * query string is kept in the URL for the same reason a filtered recipe list
 * keeps its own — a board worth showing the other phone has to be sendable.
 */
const props = defineProps<{
    /** False means the deals app did not answer — a different thing from empty. */
    available: boolean;
    deals: Deal[];
    undatedTrips: Deal[];
    airports: { origins: AirportOption[]; destinations: AirportOption[] };
    totals: Record<string, DealTotals>;
    thresholds: Record<string, number | null>;
    currency: string;
    filters: Filters;
    meta: TravelMeta;
}>();

/** The offer whose details sheet is open. Nothing is fetched to open it. */
const opened = ref<Deal | null>(null);

/**
 * Every change of a control is a fresh request with a new query string.
 *
 * Never a filter applied to the deals in hand: at most a couple of hundred of
 * hundreds of thousands are ever sent, so re-sorting the page would rank the
 * wrong ones and could show an empty screen while the far end is full of
 * matches.
 */
function apply(patch: Partial<Filters>): void {
    const next: Filters = { ...props.filters, ...patch };

    const query: Record<string, string> = {};

    if (next.sort !== null) {
        query.sort = next.sort;
    }

    if (next.type !== null) {
        query.type = next.type;
    }

    if (next.weekends) {
        query.weekends = '1';
    }

    if (next.steals) {
        query.steals = '1';
    }

    if (next.origin !== null) {
        query.origin = next.origin;
    }

    if (next.destination !== null) {
        query.destination = next.destination;
    }

    // Both or neither — half a holiday filters on a bound nobody set.
    if (next.from !== null && next.to !== null) {
        query.from = next.from;
        query.to = next.to;
    }

    router.get(
        travel.url({ query }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

const holiday = computed<boolean>(
    () => props.filters.from !== null && props.filters.to !== null,
);

/**
 * Nothing is collected beyond the deals app's window, so a holiday further out
 * finds nothing *by construction*. Saying so is the difference between an answer
 * and an empty screen somebody re-asks three times.
 */
const beyondWindow = computed<boolean>(() => {
    const { from } = props.filters;
    const { windowDays } = props.meta;

    if (from === null || windowDays === null) {
        return false;
    }

    const last = new Date();
    last.setDate(last.getDate() + windowDays);

    return new Date(from) > last;
});

/** Whether anything at all was found, ignoring the filters. */
const anythingAtAll = computed<boolean>(() =>
    Object.values(props.totals).some((total) => total.count > 0),
);

const tiles = computed<{ type: string; total: DealTotals }[]>(() =>
    Object.entries(props.totals)
        .filter(([, total]) => total.count > 0)
        .map(([type, total]) => ({ type, total })),
);

const goodScore = computed<number | null>(() => props.thresholds.score ?? null);
</script>

<template>
    <Head title="Podróż" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader title="Podróż" current="travel">
            <TravelFilters
                v-if="props.available"
                :filters="props.filters"
                :meta="props.meta"
                :origins="props.airports.origins"
                :destinations="props.airports.destinations"
                @apply="apply"
            />
        </AppHeader>

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!--
                The other app is down. Deliberately not the same message as "nic
                nie pasuje": one is a reason to change the dates, the other is a
                reason to go and start something.
            -->
            <p
                v-if="!props.available"
                class="rounded-2xl border border-dashed border-rule px-4 py-12 text-center text-sm text-ink-muted"
            >
                Nie mogę się połączyć z aplikacją od lotów. Sprawdź, czy działa
                pod adresem z <code class="text-ink">TRAVEL_API_URL</code>.
            </p>

            <template v-else>
                <!--
                    Counted across everything kept rather than over the page
                    returned, so the tiles stay true while a filter is applied.
                -->
                <ul
                    v-if="tiles.length > 0"
                    class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <li
                        v-for="tile in tiles"
                        :key="tile.type"
                        class="rounded-2xl border border-rule bg-paper-raised p-4"
                    >
                        <p class="label-caps text-ink-faint">
                            {{ typeLabel(tile.type) }}
                        </p>
                        <p
                            class="mt-1 text-2xl font-semibold text-ink tabular-nums"
                        >
                            {{ tile.total.count }}
                        </p>
                        <p
                            v-if="tile.total.cheapest !== null"
                            class="text-xs text-ink-muted"
                        >
                            od
                            {{
                                formatPrice(tile.total.cheapest, props.currency)
                            }}
                        </p>
                    </li>
                </ul>

                <p
                    v-if="beyondWindow"
                    class="mb-6 flex items-start gap-2 rounded-2xl bg-voyage-soft px-4 py-3 text-sm text-ink"
                >
                    <AppIcon
                        name="calendar"
                        class="mt-0.5 text-voyage-strong"
                    />
                    <span>
                        Zbieram oferty najwyżej na
                        {{ props.meta.windowDays }} dni do przodu, więc na te
                        dni jeszcze nic nie ma — nie znaczy to, że nie będzie.
                    </span>
                </p>

                <p
                    v-if="props.deals.length === 0 && !anythingAtAll"
                    class="rounded-2xl border border-dashed border-rule px-4 py-12 text-center text-sm text-ink-muted"
                >
                    Aplikacja od lotów nie ma jeszcze żadnych ofert — poczekaj
                    na najbliższe skanowanie.
                </p>

                <p
                    v-else-if="props.deals.length === 0"
                    class="rounded-2xl border border-dashed border-rule px-4 py-12 text-center text-sm text-ink-muted"
                >
                    Nic nie pasuje do tych filtrów. Poluzuj któryś — oferty są,
                    tylko nie takie.
                </p>

                <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="deal in props.deals" :key="deal.id">
                        <DealCard
                            :deal="deal"
                            :good-score="goodScore"
                            @details="opened = deal"
                        />
                    </li>
                </ul>

                <!--
                    Under their own heading, never mixed in: most blog articles
                    never name their terms, so these offers cannot be shown to
                    fit the holiday — or to miss it.
                -->
                <section
                    v-if="holiday && props.undatedTrips.length > 0"
                    class="mt-10"
                >
                    <h2 class="label-caps text-ink-faint">
                        Bez podanych terminów
                    </h2>
                    <p class="mt-1 mb-3 text-sm text-ink-muted">
                        Te wyjazdy nie mówią, kiedy są — więc nie wiem, czy
                        zmieszczą się w tym urlopie.
                    </p>

                    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="deal in props.undatedTrips" :key="deal.id">
                            <DealCard
                                :deal="deal"
                                :good-score="goodScore"
                                @details="opened = deal"
                            />
                        </li>
                    </ul>
                </section>
            </template>
        </main>

        <DealDetails v-if="opened" :deal="opened" @close="opened = null" />

        <AppNav current="travel" />
    </div>
</template>
