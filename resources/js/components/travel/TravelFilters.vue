<script setup lang="ts">
import { computed } from 'vue';
import { sortLabel, typeLabel } from '@/lib/travel';
import type { AirportOption, TravelFilters, TravelMeta } from '@/types/travel';

/**
 * The filter bar.
 *
 * Everything it shows comes from the response: the options from `meta`, the two
 * airport lists from `airports`, and the current state from the filters the API
 * **echoed back** after dropping whatever it could not use. That last one is the
 * point — a hand-edited link that asked for something impossible shows the board
 * it actually got, rather than controls insisting on a filter nothing honoured.
 *
 * Every change is a fresh request. Filtering or sorting the page in hand would
 * rank the wrong deals: hundreds of thousands are stored and at most a couple of
 * hundred are ever sent.
 */
const props = defineProps<{
    filters: TravelFilters;
    meta: TravelMeta;
    origins: AirportOption[];
    destinations: AirportOption[];
}>();

const emit = defineEmits<{ apply: [Partial<TravelFilters>] }>();

/**
 * A blog trip carries no airport code, so both lists come back empty under
 * `type=trip`. Hidden rather than shown empty — a select with nothing in it
 * looks broken, and combining it with `type=trip` would return nothing anyway.
 */
const showAirports = computed<boolean>(() => props.filters.type !== 'trip');

/**
 * A chosen airport can legitimately drop out of its own list when another filter
 * changes. It is kept as an option regardless, or the control silently stops
 * showing what it is doing — and cannot be cleared.
 */
function withChosen(
    options: AirportOption[],
    chosen: string | null,
): AirportOption[] {
    if (chosen === null || options.some((option) => option.code === chosen)) {
        return options;
    }

    return [{ code: chosen, label: chosen }, ...options];
}

const originOptions = computed<AirportOption[]>(() =>
    withChosen(props.origins, props.filters.origin),
);

const destinationOptions = computed<AirportOption[]>(() =>
    withChosen(props.destinations, props.filters.destination),
);

/** A chip that is on turns itself off — the second tap is "pokaż wszystko". */
function toggleType(type: string): void {
    emit('apply', { type: props.filters.type === type ? null : type });
}

/**
 * Both ends or neither: the API matches a round trip on its departure *and* its
 * return, so half a range would filter on a bound nobody set. Half-entered, the
 * screen simply waits.
 */
function setHoliday(from: string | null, to: string | null): void {
    if (from === null || to === null || from === '' || to === '') {
        if (props.filters.from !== null || props.filters.to !== null) {
            emit('apply', { from: null, to: null });
        }

        return;
    }

    emit('apply', { from, to });
}

const anyFilter = computed<boolean>(
    () =>
        props.filters.type !== null ||
        props.filters.weekends ||
        props.filters.steals ||
        props.filters.origin !== null ||
        props.filters.destination !== null ||
        props.filters.from !== null,
);
</script>

<template>
    <div class="space-y-3 pb-3">
        <!-- Sort. One is always on, so these are radio-ish rather than toggles. -->
        <div class="no-scrollbar flex gap-2 overflow-x-auto">
            <button
                v-for="sort in props.meta.sorts"
                :key="sort"
                type="button"
                class="h-11 shrink-0 rounded-full border px-4 text-sm transition-colors"
                :class="
                    props.filters.sort === sort
                        ? 'border-voyage bg-voyage font-medium text-ink'
                        : 'border-rule-strong text-ink-muted hover:text-ink'
                "
                :aria-pressed="props.filters.sort === sort"
                @click="emit('apply', { sort })"
            >
                {{ sortLabel(sort) }}
            </button>

            <span
                class="mx-1 h-11 w-px shrink-0 self-center bg-rule"
                aria-hidden="true"
            />

            <button
                v-for="type in props.meta.types"
                :key="type"
                type="button"
                class="h-11 shrink-0 rounded-full border px-4 text-sm transition-colors"
                :class="
                    props.filters.type === type
                        ? 'border-voyage bg-voyage font-medium text-ink'
                        : 'border-rule-strong text-ink-muted hover:text-ink'
                "
                :aria-pressed="props.filters.type === type"
                @click="toggleType(type)"
            >
                {{ typeLabel(type) }}
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="h-11 rounded-full border px-4 text-sm transition-colors"
                :class="
                    props.filters.weekends
                        ? 'border-voyage bg-voyage font-medium text-ink'
                        : 'border-rule-strong text-ink-muted hover:text-ink'
                "
                :aria-pressed="props.filters.weekends"
                @click="emit('apply', { weekends: !props.filters.weekends })"
            >
                Na weekend
            </button>

            <button
                type="button"
                class="h-11 rounded-full border px-4 text-sm transition-colors"
                :class="
                    props.filters.steals
                        ? 'border-voyage bg-voyage font-medium text-ink'
                        : 'border-rule-strong text-ink-muted hover:text-ink'
                "
                :aria-pressed="props.filters.steals"
                @click="emit('apply', { steals: !props.filters.steals })"
            >
                Wyjątkowo tanio
            </button>

            <template v-if="showAirports">
                <label class="sr-only" for="travel-origin">Skąd</label>
                <select
                    id="travel-origin"
                    class="h-11 rounded-full border border-rule-strong bg-paper px-4 text-sm text-ink"
                    :value="props.filters.origin ?? ''"
                    @change="
                        emit('apply', {
                            origin:
                                ($event.target as HTMLSelectElement).value ||
                                null,
                        })
                    "
                >
                    <option value="">Skąd: dowolne</option>
                    <option
                        v-for="option in originOptions"
                        :key="option.code"
                        :value="option.code"
                    >
                        {{ option.label }}
                    </option>
                </select>

                <label class="sr-only" for="travel-destination">Dokąd</label>
                <select
                    id="travel-destination"
                    class="h-11 rounded-full border border-rule-strong bg-paper px-4 text-sm text-ink"
                    :value="props.filters.destination ?? ''"
                    @change="
                        emit('apply', {
                            destination:
                                ($event.target as HTMLSelectElement).value ||
                                null,
                        })
                    "
                >
                    <option value="">Dokąd: dowolne</option>
                    <option
                        v-for="option in destinationOptions"
                        :key="option.code"
                        :value="option.code"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </template>
        </div>

        <!--
            The holiday. Named "urlop od / do" rather than "daty", because what
            it filters on is the whole journey fitting inside the days off — a
            cheap way out whose return lands after work starts again is exactly
            what a price-sorted list would otherwise put on top.
        -->
        <div class="flex flex-wrap items-center gap-2">
            <label class="text-sm text-ink-muted" for="travel-from">
                Urlop od
            </label>
            <input
                id="travel-from"
                type="date"
                class="h-11 rounded-full border border-rule-strong bg-paper px-4 text-sm text-ink"
                :value="props.filters.from ?? ''"
                @change="
                    setHoliday(
                        ($event.target as HTMLInputElement).value,
                        props.filters.to,
                    )
                "
            />

            <label class="text-sm text-ink-muted" for="travel-to">do</label>
            <input
                id="travel-to"
                type="date"
                class="h-11 rounded-full border border-rule-strong bg-paper px-4 text-sm text-ink"
                :value="props.filters.to ?? ''"
                @change="
                    setHoliday(
                        props.filters.from,
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />

            <button
                v-if="anyFilter"
                type="button"
                class="ml-auto h-11 rounded-full px-4 text-sm text-ink-muted hover:text-ink"
                @click="
                    emit('apply', {
                        type: null,
                        weekends: false,
                        steals: false,
                        origin: null,
                        destination: null,
                        from: null,
                        to: null,
                    })
                "
            >
                Wyczyść filtry
            </button>
        </div>
    </div>
</template>
