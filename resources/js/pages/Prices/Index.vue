<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { show } from '@/routes/prices';
import { index as shopping } from '@/routes/shopping';

interface PricedProduct {
    id: number;
    slug: string;
    name: string;
    emoji: string;
    /** How many readings back this product, across every day and shop. */
    readings: number;
    latest: string;
}

const props = defineProps<{ products: PricedProduct[] }>();

const search = ref('');

/**
 * Filtered in the browser over the whole list, like the kitchen's product
 * search: a couple of hundred rows is nothing to ship, and an endpoint would be
 * slower and would stop working on a phone with no signal in a shop.
 */
const matches = computed<PricedProduct[]>(() => {
    const needle = search.value.trim().toLowerCase();

    if (needle === '') {
        return props.products;
    }

    return props.products.filter((product) =>
        product.name.toLowerCase().includes(needle),
    );
});
</script>

<template>
    <Head title="Ceny" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader
            title="Ceny"
            current="shopping"
            :count="props.products.length"
            :back="{ href: shopping.url(), label: 'Co kupić' }"
        />

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!--
                Said once, at the top, rather than beside every figure: these are
                regular prices, which is exactly what makes them worth keeping.
            -->
            <p class="mb-6 max-w-prose text-sm text-ink-muted">
                Ceny regularne — z gazetek (to, co sklep podaje jako cenę sprzed
                promocji) i ze średnich krajowych GUS. Ceny promocyjne nie
                wchodzą tu w ogóle, bo pokazywałyby, ile masło kosztowało przez
                cztery dni wyprzedaży, a nie ile kosztuje.
            </p>

            <label for="search" class="sr-only">Szukaj produktu</label>
            <div class="relative mb-6">
                <AppIcon
                    name="search"
                    class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-ink-faint"
                />
                <input
                    id="search"
                    v-model="search"
                    type="search"
                    placeholder="Szukaj po nazwie…"
                    class="h-11 w-full rounded-full border border-rule-strong bg-paper-raised pr-4 pl-11 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none [&::-webkit-search-cancel-button]:hidden"
                />
            </div>

            <ul
                v-if="matches.length > 0"
                class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
            >
                <li v-for="product in matches" :key="product.id">
                    <Link
                        :href="show.url(product.slug)"
                        class="flex min-h-13 items-center gap-3 px-4 py-3 hover:bg-paper-sunk"
                    >
                        <span class="min-w-0 flex-1 text-ink">
                            <IngredientLabel
                                :emoji="product.emoji"
                                :name="product.name"
                            />
                        </span>
                        <span
                            class="shrink-0 text-xs text-ink-faint tabular-nums"
                        >
                            {{ product.readings }}
                        </span>
                    </Link>
                </li>
            </ul>

            <p
                v-else
                class="rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
            >
                <template v-if="props.products.length === 0">
                    Nie mam jeszcze żadnych odczytów cen. Uruchom
                    <code class="text-ink">php artisan prices:import</code>.
                </template>
                <template v-else>Nic takiego nie znalazłem.</template>
            </p>
        </main>

        <AppNav current="shopping" />
    </div>
</template>
