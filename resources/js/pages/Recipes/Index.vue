<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import RecipeCard from '@/components/RecipeCard.vue';
import RecipeModal from '@/components/RecipeModal.vue';
import type { IconName } from '@/lib/icons';
import { home } from '@/routes';
import { show } from '@/routes/recipes';
import type {
    RecipeCategory,
    RecipeDetail,
    RecipeSummary,
} from '@/types/recipe';

const props = defineProps<{
    recipes: RecipeSummary[];
    /** How many recipes match the current search and chip, not how many are shown. */
    total: number;
    hasMore: boolean;
    counts: Record<string, number>;
    categories: RecipeCategory[];
    /** Whether anything is on the shelves — the two pantry filters need it. */
    hasPantry: boolean;
    search: string;
    filter: string;
    recipe?: RecipeDetail;
    /** Only sent with a recipe: the modal asks which list to write to. */
    shoppingLists?: { id: number; name: string; isDefault: boolean }[];
}>();

/**
 * One active filter at a time, the way a food app's category row behaves. A
 * category is `cat:<slug>`; the rest are the standing filters. Allowing several
 * at once would let the user reach an empty intersection by accident and would
 * make the row unreadable.
 */
type Filter = string;

/**
 * The catalogue passed ten thousand recipes, at which point sending all of them
 * to the browser stopped working — so searching, filtering and paging all happen
 * on the server now. These two mirror the props and drive the round trips.
 */
const search = ref(props.search);
const filter = ref<Filter>(props.filter);
const page = ref(1);
const loading = ref(false);

/** Kept in step when the browser's back button restores an earlier query. */
watch(
    () => [props.search, props.filter],
    ([nextSearch, nextFilter]) => {
        search.value = nextSearch;
        filter.value = nextFilter;
        page.value = 1;
    },
);

/**
 * Reload the first page. `reset` throws away the merged list, without which a
 * narrower search would append its results underneath the old ones.
 */
function reload(): void {
    page.value = 1;

    router.get(
        home.url(),
        { search: search.value, filter: filter.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            reset: ['recipes'],
            only: ['recipes', 'total', 'hasMore', 'search', 'filter'],
        },
    );
}

/** Typing must not fire a request per keystroke. */
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 250);
});

function choose(key: Filter): void {
    filter.value = key;
    clearTimeout(searchTimer);
    reload();
}

function clearAll(): void {
    search.value = '';
    filter.value = 'all';
    clearTimeout(searchTimer);
    reload();
}

const remaining = computed<number>(() =>
    Math.max(0, props.total - props.recipes.length),
);

/**
 * Infinite scroll. The sentinel sits below the grid and the observer is given a
 * generous margin, so the next batch is already there by the time the last row
 * is reached rather than after a visible stall.
 *
 * Keyboard scrolling moves the sentinel into view exactly as a finger does, so
 * this stays reachable without a mouse.
 */
const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

function loadMore(): void {
    if (!props.hasMore || loading.value) {
        return;
    }

    loading.value = true;
    page.value += 1;

    router.reload({
        data: { search: search.value, filter: filter.value, page: page.value },
        only: ['recipes', 'hasMore'],
        preserveUrl: true,
        onFinish: () => {
            loading.value = false;
        },
    });
}

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                loadMore();
            }
        },
        { rootMargin: '800px 0px' },
    );

    if (sentinel.value !== null) {
        observer.observe(sentinel.value);
    }
});

/*
 * The sentinel is removed once the list ends and built again when a filter
 * brings more results back. Following the ref keeps the observer pointed at the
 * element that actually exists — watching only the first one silently stops the
 * loading after the first filter change.
 */
watch(sentinel, (element, previous) => {
    if (previous !== null && previous !== undefined) {
        observer?.unobserve(previous);
    }

    if (element !== null) {
        observer?.observe(element);
    }
});

/**
 * The filter row scrolls sideways. A finger does that on its own; a mouse cannot
 * — the row has no visible scrollbar and a wheel scrolls the page. So the wheel
 * is translated to horizontal movement and two arrows appear when there is
 * something past either edge.
 */
const chips = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

function measureChips(): void {
    const row = chips.value;

    if (row === null) {
        canScrollLeft.value = false;
        canScrollRight.value = false;

        return;
    }

    // A fraction of a pixel is left over at the end on some zoom levels.
    canScrollLeft.value = row.scrollLeft > 1;
    canScrollRight.value =
        row.scrollLeft + row.clientWidth < row.scrollWidth - 1;
}

function scrollChips(direction: -1 | 1): void {
    chips.value?.scrollBy({
        left: direction * Math.max(160, (chips.value.clientWidth * 2) / 3),
        behavior: 'smooth',
    });
}

/** A trackpad already sends `deltaX`; only a wheel's vertical delta needs turning. */
function onChipsWheel(event: WheelEvent): void {
    if (event.deltaX !== 0 || event.deltaY === 0 || chips.value === null) {
        return;
    }

    event.preventDefault();
    chips.value.scrollLeft += event.deltaY;
}

/** The row is rebuilt when the categories arrive, so follow the ref. */
watch(chips, () => {
    measureChips();
});

/** Chips come and go with `hasPantry` and the category counts. */
watch(
    () => props.categories,
    () => {
        requestAnimationFrame(measureChips);
    },
);

onMounted(() => {
    measureChips();
    window.addEventListener('resize', measureChips);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    observer?.disconnect();
    window.removeEventListener('resize', measureChips);
});

/**
 * Cooking from what is already in the kitchen comes first, then the cuisines,
 * then the standing filters — all in one scrolling row rather than a second
 * line of the header. Counts come from the server so they describe the whole
 * catalogue, not the page that happens to be loaded.
 */
const CHIPS = computed(
    (): {
        key: Filter;
        label: string;
        icon: IconName | null;
        count: number;
    }[] => [
        {
            key: 'all',
            label: 'Wszystkie',
            icon: null,
            count: props.counts.all ?? 0,
        },
        /*
         * First when it applies, and absent otherwise. Unlike the two below it
         * this one has a deadline attached, so it earns the front of the row —
         * but a chip reading zero would be a dead button, and here zero is the
         * normal state of a fridge with nothing about to go off.
         */
        ...((props.counts.expiring ?? 0) > 0
            ? ([
                  {
                      key: 'expiring',
                      label: 'Do zużycia',
                      icon: 'clock',
                      count: props.counts.expiring ?? 0,
                  },
              ] as {
                  key: Filter;
                  label: string;
                  icon: IconName | null;
                  count: number;
              }[])
            : []),
        ...(props.hasPantry
            ? ([
                  {
                      /*
                       * "Z lodówki" was ambiguous — it reads as "uses something
                       * from the fridge" rather than "needs nothing you have
                       * not got". This chip is the whole point of the kitchen
                       * screen, so it says exactly what it does.
                       */
                      key: 'cookable',
                      label: 'Mam wszystko',
                      icon: 'ingredients',
                      count: props.counts.cookable ?? 0,
                  },
                  {
                      key: 'almost',
                      label: 'Brakuje 1–2',
                      icon: null,
                      count: props.counts.almost ?? 0,
                  },
              ] as {
                  key: Filter;
                  label: string;
                  icon: IconName | null;
                  count: number;
              }[])
            : []),
        ...props.categories.map((category) => ({
            key: `cat:${category.slug}`,
            label: category.name,
            icon: (category.icon as IconName | null) ?? null,
            count: category.count,
        })),
        {
            key: 'air_fryer',
            label: 'Airfryer',
            icon: null,
            count: props.counts.air_fryer ?? 0,
        },
        {
            key: 'meal_prep',
            label: 'Do pudełka',
            icon: 'box',
            count: props.counts.meal_prep ?? 0,
        },
        {
            key: 'review',
            label: 'Do sprawdzenia',
            icon: 'flag',
            count: props.counts.review ?? 0,
        },
    ],
);

/** Set while the detail is on its way, so the click has a visible consequence. */
const opening = ref<string | null>(null);

/**
 * The detail is its own URL, so a recipe stays shareable and the browser's back
 * button closes the modal. Only the `recipe` prop is fetched; the list is kept.
 */
function open(slug: string): void {
    opening.value = slug;

    router.get(
        show.url(slug),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            // The lists come with the recipe: the modal cannot ask which one
            // to add to if the prop was dropped from the partial visit.
            only: ['recipe', 'shoppingLists'],
            onFinish: () => {
                opening.value = null;
            },
        },
    );
}

/**
 * Deliberately a full visit rather than a partial one: a partial reload would not
 * clear the `recipe` prop, leaving the modal open.
 */
function close(): void {
    router.get(home.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <!--
        The open dish names the tab. The detail has its own shareable URL, so a
        bookmark or a second tab reading "Przepisy" would say nothing about
        which recipe is in it.
    -->
    <Head :title="props.recipe?.title ?? 'Przepisy'" />

    <div class="min-h-dvh bg-paper">
        <!--
          The whole bar is sticky, so on a phone the search and the filters stay
          within thumb reach while scrolling a list of thousands.
        -->
        <AppHeader title="Przepisy" current="recipes" :count="total">
            <label for="search" class="sr-only">Szukaj przepisu</label>
            <div class="relative">
                <AppIcon
                    name="search"
                    class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-ink-faint"
                />
                <input
                    id="search"
                    v-model="search"
                    type="search"
                    inputmode="search"
                    placeholder="Szukaj po nazwie…"
                    class="h-11 w-full rounded-full border border-rule-strong bg-paper-raised pr-12 pl-11 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none [&::-webkit-search-cancel-button]:hidden"
                />
                <!-- Clearing a search on a phone should not mean 20 backspaces. -->
                <button
                    v-if="search !== ''"
                    type="button"
                    class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full text-ink-faint hover:text-ink"
                    aria-label="Wyczyść wyszukiwanie"
                    @click="search = ''"
                >
                    <AppIcon name="clear" />
                </button>
            </div>

            <!--
                  A wrapping filter row pushes the grid down on a narrow screen,
                  so the filters scroll sideways instead. The negative margin lets
                  them bleed to the edges the way a native app's chips do.
                -->
            <div class="relative">
                <nav
                    ref="chips"
                    class="-mx-4 mt-3 no-scrollbar flex gap-2 overflow-x-auto px-4 pb-3 sm:mx-0 sm:px-0"
                    aria-label="Filtry"
                    @scroll.passive="measureChips"
                    @wheel="onChipsWheel"
                >
                    <button
                        v-for="option in CHIPS"
                        :key="option.key"
                        type="button"
                        class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm whitespace-nowrap transition-colors"
                        :class="
                            filter === option.key
                                ? 'border-accent bg-accent text-ink'
                                : 'border-rule-strong text-ink-muted hover:text-ink'
                        "
                        :aria-pressed="filter === option.key"
                        @click="choose(option.key)"
                    >
                        <AppIcon v-if="option.icon" :name="option.icon" />
                        {{ option.label }}
                        <span class="tabular-nums opacity-70">
                            {{ option.count }}
                        </span>
                    </button>
                </nav>

                <!--
                      Arrows for a mouse only: a finger swipes the row already,
                      and on a phone they would sit on top of the chips.
                    -->
                <button
                    v-if="canScrollLeft"
                    type="button"
                    class="absolute top-0 -left-1 hidden h-11 w-11 items-center justify-center rounded-full bg-paper/95 text-ink-muted shadow-sm ring-1 ring-rule hover:text-ink sm:flex"
                    aria-label="Przewiń filtry w lewo"
                    @click="scrollChips(-1)"
                >
                    <AppIcon name="chevronLeft" />
                </button>

                <button
                    v-if="canScrollRight"
                    type="button"
                    class="absolute top-0 -right-1 hidden h-11 w-11 items-center justify-center rounded-full bg-paper/95 text-ink-muted shadow-sm ring-1 ring-rule hover:text-ink sm:flex"
                    aria-label="Przewiń filtry w prawo"
                    @click="scrollChips(1)"
                >
                    <AppIcon name="chevronLeft" class="rotate-180" />
                </button>
            </div>
        </AppHeader>

        <main
            class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:py-10 sm:pb-safe"
        >
            <p class="sr-only" role="status" aria-live="polite">
                Znaleziono {{ total }} przepisów.
            </p>

            <div
                v-if="props.recipes.length > 0"
                class="grid grid-cols-2 gap-x-4 gap-y-7 sm:gap-x-6 md:grid-cols-3 lg:grid-cols-4"
            >
                <RecipeCard
                    v-for="(recipe, index) in props.recipes"
                    :key="recipe.slug"
                    :recipe="recipe"
                    :featured="index === 0 && search === ''"
                    :has-pantry="hasPantry"
                    @open="open"
                />
            </div>

            <div v-else class="py-20 text-center">
                <AppIcon
                    name="empty"
                    class="mx-auto mb-4 h-12 w-12 text-rule-strong"
                />
                <p class="text-lg font-semibold text-ink">Nic tu nie ma</p>
                <p class="mx-auto mt-2 max-w-xs text-sm text-ink-muted">
                    Żaden przepis nie pasuje do tego, czego szukasz. Spróbuj
                    innej nazwy albo zdejmij filtr.
                </p>
                <button
                    type="button"
                    class="mt-6 h-11 rounded-full bg-accent px-6 text-sm font-medium text-ink"
                    @click="clearAll"
                >
                    Wyczyść wyszukiwanie
                </button>
            </div>

            <!--
              The sentinel is what the observer watches; it has to stay in the
              DOM while anything is left, and vanish at the end so the list has a
              bottom rather than loading forever.
            -->
            <div v-if="props.hasMore" ref="sentinel" class="mt-10 text-center">
                <div
                    class="mx-auto h-6 w-6 animate-spin rounded-full border-2 border-rule border-t-accent"
                    role="status"
                    aria-label="Wczytywanie kolejnych przepisów"
                ></div>
                <p class="mt-3 text-sm text-ink-muted">
                    zostało <span class="tabular-nums">{{ remaining }}</span>
                </p>
            </div>

            <p
                v-else-if="props.recipes.length > 0"
                class="mt-12 text-center text-sm text-ink-faint"
            >
                To wszystko —
                <span class="tabular-nums">{{ total }}</span>
                przepisów.
            </p>
        </main>

        <RecipeModal
            v-if="props.recipe"
            :recipe="props.recipe"
            :shopping-lists="props.shoppingLists ?? []"
            @close="close"
        />

        <!-- The detail is a round trip; without this the card click looks dead. -->
        <div
            v-else-if="opening !== null"
            class="fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-accent-soft"
            role="status"
            aria-label="Wczytywanie przepisu"
        >
            <div class="h-full w-1/3 animate-pulse bg-accent"></div>
        </div>

        <AppNav current="recipes" />
    </div>
</template>
