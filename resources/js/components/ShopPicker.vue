<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { shops as saveShops } from '@/routes/shopping';

export interface ShopChoice {
    id: number;
    slug: string;
    name: string;
    selected: boolean;
    /** How many current offers this chain holds, across the whole catalogue. */
    offerCount: number;
}

const props = defineProps<{
    shops: ShopChoice[];
    /** False when nothing has been chosen, which the planner reads as "all". */
    narrowed: boolean;
}>();

/**
 * Held locally so a tap redraws the chip immediately rather than after the round
 * trip. The server's answer replaces it on the way back, so a rejected change
 * cannot linger on screen as if it had been saved.
 */
const chosen = ref<Set<number>>(
    new Set(props.shops.filter((shop) => shop.selected).map((shop) => shop.id)),
);

watch(
    () => props.shops,
    (shops) => {
        chosen.value = new Set(
            shops.filter((shop) => shop.selected).map((shop) => shop.id),
        );
    },
);

const open = ref(false);

const summary = computed<string>(() => {
    if (!props.narrowed) {
        return 'wszystkie sklepy';
    }

    const names = props.shops
        .filter((shop) => chosen.value.has(shop.id))
        .map((shop) => shop.name);

    if (names.length === 0) {
        return 'żaden sklep';
    }

    // Past three the row is longer than the screen and says less than a number.
    return names.length <= 3 ? names.join(', ') : `${names.length} sklepów`;
});

function toggle(id: number): void {
    // Replaced rather than mutated: a Set mutated in place does not re-render.
    const next = new Set(chosen.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    chosen.value = next;

    router.post(
        saveShops.url(),
        { shops: [...next] },
        {
            preserveScroll: true,
            /*
             * The plan is recomputed from the new selection, so state is *not*
             * preserved — that is the whole point of the tap. The URL is, because
             * the chosen strategy lives in its query string.
             */
            preserveUrl: true,
        },
    );
}
</script>

<template>
    <section class="mb-6">
        <button
            type="button"
            class="flex h-11 w-full items-center justify-between gap-3 rounded-2xl border border-rule bg-paper-raised px-4 text-left text-sm"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2 text-ink-muted">
                <AppIcon name="store" class="text-ink-faint" />
                <span class="truncate">
                    Jadę do:
                    <span class="text-ink">{{ summary }}</span>
                </span>
            </span>
            <span class="shrink-0 label-caps text-ink-faint">
                {{ open ? 'zwiń' : 'zmień' }}
            </span>
        </button>

        <div v-if="open" class="mt-3">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="shop in props.shops"
                    :key="shop.id"
                    type="button"
                    class="flex h-11 items-center gap-2 rounded-full border px-4 text-sm"
                    :class="
                        chosen.has(shop.id)
                            ? 'border-accent bg-accent font-medium text-ink'
                            : 'border-rule-strong text-ink-muted'
                    "
                    :aria-pressed="chosen.has(shop.id)"
                    @click="toggle(shop.id)"
                >
                    {{ shop.name }}
                    <!--
                        The offer count is what makes the choice checkable: a
                        chain whose leaflet nobody has read yet gives a plan that
                        ignores it, and without this that reads as a fault rather
                        than an empty leaflet.
                    -->
                    <span
                        class="tabular-nums"
                        :class="
                            chosen.has(shop.id) ? 'text-ink' : 'text-ink-faint'
                        "
                    >
                        {{ shop.offerCount }}
                    </span>
                </button>
            </div>

            <p class="mt-3 text-xs text-ink-faint">
                Plan i szacowany koszt liczą tylko promocje z zaznaczonych
                sieci. Bez zaznaczenia liczę wszystkie.
            </p>
        </div>
    </section>
</template>
