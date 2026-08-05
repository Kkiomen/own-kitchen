<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import ShopPicker from '@/components/ShopPicker.vue';
import type { ShopChoice } from '@/components/ShopPicker.vue';
import { formatMoney, formatUnitPrice, formatValidTo } from '@/lib/money';
import { formatQuantity } from '@/lib/quantity';
import { index as shopping, plan as planRoute } from '@/routes/shopping';

interface Offer {
    shop: string;
    title: string;
    price: number;
    regularPrice: number | null;
    discount: number | null;
    unitPrice: number | null;
    unitLabel: string | null;
    validTo: string | null;
    url: string;
}

interface ListEntry {
    id: number;
    name: string;
    emoji: string;
    quantity: number | null;
    unit: string | null;
}

interface Buy extends ListEntry {
    packs: number;
    cost: number;
    savings: number | null;
    offer: Offer;
    alternatives: Offer[];
}

interface Stop {
    slug: string;
    name: string;
    subtotal: number;
    savings: number | null;
    buys: Buy[];
}

const props = defineProps<{
    stops: Stop[];
    withoutPromotion: ListEntry[];
    total: number;
    savings: number | null;
    promotedCount: number;
    strategies: { key: string; label: string; active: boolean }[];
    shops: ShopChoice[];
    shopsNarrowed: boolean;
    hasPromotions: boolean;
    listCount: number;
}>();

/** Which lines have their "inne sklepy" list open, by shopping list item id. */
const expanded = ref<Set<number>>(new Set());

function toggleAlternatives(id: number): void {
    // Replaced rather than mutated: a Set mutated in place does not re-render.
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

function choose(key: string): void {
    router.get(
        planRoute.url({ query: { plan: key } }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <Head title="Gdzie kupić taniej" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader
            title="Gdzie kupić taniej"
            current="shopping"
            :back="{ href: shopping.url(), label: 'Co kupić' }"
        />

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!-- Nothing has been crawled yet: a different problem from "no offers". -->
            <p
                v-if="!props.hasPromotions"
                class="rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
            >
                Nie mam jeszcze żadnych gazetek. Uruchom
                <code class="text-ink">php artisan promotions:import</code>,
                żebym wiedział, co jest w promocji.
            </p>

            <p
                v-else-if="props.listCount === 0"
                class="rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
            >
                Lista zakupów jest pusta — nie ma czego szukać w gazetkach.
            </p>

            <template v-else>
                <!--
                    Above the strategies, because it changes what they are
                    choosing between: "najtaniej" means something different once
                    the household has said it will not drive to Auchan.
                -->
                <ShopPicker
                    :shops="props.shops"
                    :narrowed="props.shopsNarrowed"
                />

                <div class="mb-6 flex flex-wrap gap-2">
                    <button
                        v-for="option in props.strategies"
                        :key="option.key"
                        type="button"
                        class="flex h-11 items-center rounded-full border px-4 text-sm"
                        :class="
                            option.active
                                ? 'border-accent bg-accent font-medium text-ink'
                                : 'border-rule-strong text-ink-muted'
                        "
                        :aria-pressed="option.active"
                        @click="choose(option.key)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <section
                    class="mb-8 rounded-2xl border border-rule bg-paper-raised px-4 py-4"
                >
                    <p class="text-sm text-ink-muted">
                        {{ props.promotedCount }} z
                        {{ props.listCount }}
                        {{ props.listCount === 1 ? 'produktu' : 'produktów' }}
                        w promocji, w
                        {{ props.stops.length }}
                        {{ props.stops.length === 1 ? 'sklepie' : 'sklepach' }}.
                    </p>

                    <p
                        class="mt-1 text-2xl font-semibold text-ink tabular-nums"
                    >
                        {{ formatMoney(props.total) }}
                    </p>

                    <p
                        v-if="props.savings !== null"
                        class="mt-1 text-sm font-medium text-accent-strong tabular-nums"
                    >
                        Oszczędzasz {{ formatMoney(props.savings) }}
                    </p>

                    <!--
                        Said plainly rather than hidden: the total covers the
                        promoted lines only. We know what a leaflet charges for
                        butter this week; we do not know the shelf price of the
                        flour that is not on offer, and adding a guess would make
                        the confident number the wrong one.
                    -->
                    <p class="mt-3 text-xs text-ink-faint">
                        To koszt tylko tych pozycji, które są w promocji. Ceny
                        pochodzą z gazetek i mogą się różnić od kasy.
                    </p>

                    <!--
                        Zero stops with a narrowed selection is the one case that
                        looks like a fault and is not: there may well be offers,
                        just not in the chains they said they would drive to.
                    -->
                    <p
                        v-if="props.stops.length === 0 && props.shopsNarrowed"
                        class="mt-1 text-xs text-flag"
                    >
                        Liczę tylko zaznaczone sieci — w innych mogą być
                        promocje, których tu nie widać.
                    </p>
                </section>

                <section
                    v-for="stop in props.stops"
                    :key="stop.slug"
                    class="mb-8"
                >
                    <div class="mb-2 flex items-end justify-between gap-3">
                        <h2 class="flex items-center gap-2 label-caps text-ink">
                            <AppIcon name="store" />
                            {{ stop.name }}
                        </h2>
                        <p class="text-sm text-ink-muted tabular-nums">
                            {{ formatMoney(stop.subtotal) }}
                            <span
                                v-if="stop.savings !== null"
                                class="text-accent-strong"
                            >
                                (−{{ formatMoney(stop.savings) }})
                            </span>
                        </p>
                    </div>

                    <ul
                        class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                    >
                        <li v-for="buy in stop.buys" :key="buy.id" class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-ink">
                                        <IngredientLabel
                                            :emoji="buy.emoji"
                                            :name="buy.name"
                                        />
                                    </p>
                                    <p class="pl-7 text-sm text-ink-muted">
                                        {{ buy.offer.title }}
                                    </p>
                                    <p
                                        class="pl-7 text-xs text-ink-faint tabular-nums"
                                    >
                                        <span v-if="buy.quantity !== null">
                                            potrzeba
                                            {{
                                                formatQuantity(
                                                    buy.quantity,
                                                    buy.unit,
                                                )
                                            }}
                                            ·
                                        </span>
                                        <span v-if="buy.packs > 1">
                                            {{ buy.packs }} × ·
                                        </span>
                                        <span
                                            v-if="buy.offer.unitPrice !== null"
                                        >
                                            {{
                                                formatUnitPrice(
                                                    buy.offer.unitPrice,
                                                    buy.offer.unitLabel,
                                                )
                                            }}
                                            ·
                                        </span>
                                        {{ formatValidTo(buy.offer.validTo) }}
                                    </p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p
                                        class="font-semibold text-ink tabular-nums"
                                    >
                                        {{ formatMoney(buy.cost) }}
                                    </p>
                                    <p
                                        v-if="buy.offer.regularPrice !== null"
                                        class="text-xs text-ink-faint tabular-nums line-through"
                                    >
                                        {{
                                            formatMoney(buy.offer.regularPrice)
                                        }}
                                    </p>
                                    <p
                                        v-if="buy.offer.discount !== null"
                                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent-strong"
                                    >
                                        <AppIcon name="tag" />
                                        −{{ buy.offer.discount }}%
                                    </p>
                                </div>
                            </div>

                            <!--
                                The runners-up are worth showing: the person at
                                the shelf can see the pack size the leaflet never
                                printed, which is the one thing the ranking could
                                not take into account.
                            -->
                            <template v-if="buy.alternatives.length > 0">
                                <button
                                    type="button"
                                    class="mt-2 flex h-11 items-center text-sm text-accent-strong"
                                    :aria-expanded="expanded.has(buy.id)"
                                    @click="toggleAlternatives(buy.id)"
                                >
                                    {{
                                        expanded.has(buy.id)
                                            ? 'Ukryj inne sklepy'
                                            : `Inne sklepy (${buy.alternatives.length})`
                                    }}
                                </button>

                                <ul
                                    v-if="expanded.has(buy.id)"
                                    class="space-y-1 border-t border-rule pt-2"
                                >
                                    <li
                                        v-for="other in buy.alternatives"
                                        :key="`${other.shop}-${other.title}`"
                                        class="flex items-baseline justify-between gap-3 text-sm"
                                    >
                                        <span class="min-w-0 text-ink-muted">
                                            {{ other.shop }} ·
                                            {{ other.title }}
                                        </span>
                                        <span
                                            class="shrink-0 text-ink tabular-nums"
                                        >
                                            {{ formatMoney(other.price) }}
                                            <span
                                                v-if="other.unitPrice !== null"
                                                class="text-ink-faint"
                                            >
                                                ({{
                                                    formatUnitPrice(
                                                        other.unitPrice,
                                                        other.unitLabel,
                                                    )
                                                }})
                                            </span>
                                        </span>
                                    </li>
                                </ul>
                            </template>
                        </li>
                    </ul>
                </section>

                <section v-if="props.withoutPromotion.length > 0">
                    <h2 class="mb-2 label-caps text-ink-faint">
                        Bez promocji — kup gdziekolwiek
                    </h2>

                    <ul
                        class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                    >
                        <li
                            v-for="item in props.withoutPromotion"
                            :key="item.id"
                            class="flex min-h-13 items-center justify-between gap-3 px-4 py-3"
                        >
                            <span class="min-w-0 text-ink">
                                <IngredientLabel
                                    :emoji="item.emoji"
                                    :name="item.name"
                                />
                            </span>
                            <span
                                v-if="item.quantity !== null"
                                class="shrink-0 text-xs text-ink-muted tabular-nums"
                            >
                                {{ formatQuantity(item.quantity, item.unit) }}
                            </span>
                        </li>
                    </ul>
                </section>
            </template>
        </main>

        <AppNav current="shopping" />
    </div>
</template>
