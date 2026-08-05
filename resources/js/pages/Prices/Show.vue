<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { formatMoney, formatUnitPrice } from '@/lib/money';
import { index as prices } from '@/routes/prices';

interface PriceDay {
    date: string;
    /** The middle reading of that day, in grosze. */
    median: number;
    low: number;
    high: number;
    readings: number;
    shops: string[];
    sources: string[];
    unitPrice: number | null;
    unitPricePer: string | null;
}

const props = defineProps<{
    product: { slug: string; name: string; emoji: string };
    days: PriceDay[];
    /**
     * Only ever a comparison of two per-kilo (or per-litre, per-piece) figures.
     * Null far more often than not, because most readings carry no pack size —
     * and null is the right answer then. See App\Pricing\PriceHistory.
     */
    change: {
        from: string;
        to: string;
        per: string;
        difference: number;
        percent: number;
    } | null;
    typical: {
        pack: number | null;
        unit: number | null;
        unitPer: string | null;
    };
}>();

/** kg / l / szt. — the dimension a per-unit price is quoted in. */
const DIMENSION_LABELS: Record<string, string> = {
    mass: 'kg',
    volume: 'l',
    count: 'szt.',
};

function dimension(per: string | null): string | null {
    return per === null ? null : (DIMENSION_LABELS[per] ?? per);
}

function formatDay(date: string): string {
    return new Date(date).toLocaleDateString('pl-PL', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/**
 * How far apart the shops were that day. Shown only when they actually
 * disagreed: "12,49–12,49" is noise, and the spread is the honest width of the
 * answer rather than decoration.
 */
function spread(day: PriceDay): string | null {
    return day.low === day.high
        ? null
        : `${formatMoney(day.low)} – ${formatMoney(day.high)}`;
}

const changeLabel = computed<string | null>(() => {
    if (props.change === null) {
        return null;
    }

    const { difference, percent, per } = props.change;
    const direction = difference > 0 ? 'w górę' : 'w dół';
    const unit = dimension(per);

    // The unit is part of the claim, not a decoration: this is a change per
    // kilo, and a bare percentage would read as a change per pack.
    return `${direction} o ${formatMoney(Math.abs(difference))}/${unit} (${Math.abs(
        percent,
    )
        .toFixed(1)
        .replace('.', ',')}%)`;
});
</script>

<template>
    <Head :title="`Ceny — ${props.product.name}`" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader
            :title="props.product.name"
            current="shopping"
            :back="{ href: prices.url(), label: 'Ceny' }"
        />

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <p class="text-lg">
                <IngredientLabel
                    :emoji="props.product.emoji"
                    :name="props.product.name"
                />
            </p>

            <!--
                What the shopping list would charge for this, so the two screens
                cannot appear to disagree about the same product.
            -->
            <dl
                v-if="props.typical.pack !== null"
                class="mt-4 flex flex-wrap gap-x-8 gap-y-2 border-y border-rule py-3 text-sm"
            >
                <div class="flex items-center gap-2">
                    <dt class="text-ink-faint">Zwykle za opakowanie</dt>
                    <dd class="text-ink tabular-nums">
                        {{ formatMoney(props.typical.pack) }}
                    </dd>
                </div>
                <div
                    v-if="props.typical.unit !== null"
                    class="flex items-center gap-2"
                >
                    <dt class="text-ink-faint">Za jednostkę</dt>
                    <dd class="text-ink tabular-nums">
                        {{
                            formatUnitPrice(
                                props.typical.unit,
                                dimension(props.typical.unitPer),
                            )
                        }}
                    </dd>
                </div>
                <div v-if="changeLabel" class="flex items-center gap-2">
                    <dt class="text-ink-faint">
                        Od {{ formatDay(props.change?.from ?? '') }}
                    </dt>
                    <dd
                        class="tabular-nums"
                        :class="
                            (props.change?.difference ?? 0) > 0
                                ? 'text-flag'
                                : 'text-accent-strong'
                        "
                    >
                        {{ changeLabel }}
                    </dd>
                </div>
            </dl>

            <h2 class="mt-8 mb-3 label-caps text-ink">Odczyty</h2>

            <ul
                v-if="props.days.length > 0"
                class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
            >
                <li
                    v-for="day in props.days"
                    :key="day.date"
                    class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                >
                    <span class="w-40 shrink-0 text-sm text-ink">
                        {{ formatDay(day.date) }}
                    </span>

                    <span class="text-ink tabular-nums">
                        {{ formatMoney(day.median) }}
                    </span>

                    <span
                        v-if="day.unitPrice !== null"
                        class="text-sm text-ink-muted tabular-nums"
                    >
                        {{
                            formatUnitPrice(
                                day.unitPrice,
                                dimension(day.unitPricePer),
                            )
                        }}
                    </span>

                    <span class="min-w-0 flex-1 text-xs text-ink-faint">
                        <!--
                            The width of the answer, not decoration: one reading
                            and thirteen disagreeing shops both produce a single
                            median, and only this tells them apart.
                        -->
                        <template v-if="spread(day)">
                            {{ spread(day) }} ·
                        </template>
                        {{ day.readings }}
                        {{ day.readings === 1 ? 'odczyt' : 'odczytów' }}
                        <template v-if="day.shops.length > 0">
                            · {{ day.shops.slice(0, 4).join(', ')
                            }}<template v-if="day.shops.length > 4">
                                +{{ day.shops.length - 4 }}
                            </template>
                        </template>
                        <template v-else-if="day.sources.length > 0">
                            · {{ day.sources.join(', ') }}
                        </template>
                    </span>
                </li>
            </ul>

            <p
                v-else
                class="rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
            >
                Nikt jeszcze nie podał ceny tego produktu.
            </p>

            <p class="mt-4 max-w-prose text-sm text-ink-faint">
                Wszystkie liczby to ceny regularne — z gazetek to, co sklep
                deklaruje jako cenę sprzed promocji, plus średnie krajowe GUS.
                Cena promocyjna nigdy tu nie trafia.
            </p>
        </main>

        <AppNav current="shopping" />
    </div>
</template>
