<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ApplianceIcon from '@/components/ApplianceIcon.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import {
    actionLabel,
    applianceLabel,
    formatAmount,
    formatDuration,
    groupBySection,
    stockMarker,
} from '@/lib/recipe-display';
import { show as cook } from '@/routes/cooking';
import { show } from '@/routes/recipes';
import {
    recipe as addRecipeToShoppingList,
    recipeHeld as addRestToShoppingList,
} from '@/routes/shopping';
import type { RecipeDetail } from '@/types/recipe';

interface ShoppingListOption {
    id: number;
    name: string;
    isDefault: boolean;
}

const props = withDefaults(
    defineProps<{
        recipe: RecipeDetail;
        shoppingLists?: ShoppingListOption[];
    }>(),
    { shoppingLists: () => [] },
);
const emit = defineEmits<{ close: [] }>();

/**
 * Writing the shortfall down is the one action this screen offers, and it lands
 * on a different page entirely — so the button has to say what happened, or the
 * tap reads as ignored.
 */
const adding = ref(false);
const added = ref<number | null>(null);
const unknown = ref(0);

/**
 * What the kitchen said it already had, offered as a second, deliberate tap.
 *
 * An entry recorded without an amount means "some, unknown" and honestly is not
 * a shortage — but it is not a promise of 500 g of flour either, and today that
 * line simply vanishes from the list. So the skipped count is reported and can
 * be acted on, rather than the app choosing for everybody.
 */
const skipped = ref(0);
const addingRest = ref(false);
const restAdded = ref<number | null>(null);

/**
 * The ingredient list with each line's kitchen answer already resolved, so the
 * template asks once per line rather than once per thing it renders about it.
 * No kitchen means no answers: on an empty one every line reads "nie masz",
 * which is a column of noise rather than information.
 */
const ingredientGroups = computed(() =>
    groupBySection(props.recipe.ingredients).map((group) => ({
        section: group.section,
        items: group.items.map((line) => ({
            line,
            marker: props.recipe.hasPantry ? stockMarker(line.status) : null,
        })),
    })),
);

/**
 * Reading the recipe for a different number of portions.
 *
 * The server does the arithmetic and sends the whole detail back, rather than
 * the browser multiplying what it already holds. Two answers on this screen are
 * not in the amounts themselves — the "masz" marker beside each line, and the
 * shortfall the button below writes down — and both are computed against the
 * kitchen. Scaling here and leaving those speaking about the original recipe is
 * exactly the kind of quiet disagreement this app keeps out of its numbers.
 */
const MAX_SERVINGS = 50;
const rescaling = ref(false);

const canScale = computed<boolean>(() => props.recipe.scale.base !== null);

/**
 * A portion's calories, or nothing at all.
 *
 * Nothing rather than a zero: a recipe the catalogue cannot count is not a
 * recipe worth no calories, and a "0 kcal" on a plate of pierogi would be the
 * one thing worse than staying quiet.
 */
const nutrition = computed(() => props.recipe.nutrition.perPortion);

const macros = computed<{ label: string; grams: number }[]>(() => {
    const portion = props.recipe.nutrition.perPortion;

    if (portion === null) {
        return [];
    }

    return (
        [
            { label: 'B', grams: portion.protein },
            { label: 'T', grams: portion.fat },
            { label: 'W', grams: portion.carbs },
        ] as { label: string; grams: number | null }[]
    ).filter(
        (macro): macro is { label: string; grams: number } =>
            macro.grams !== null,
    );
});

/** The products behind the caveat, named rather than merely counted. */
const uncountedLabel = computed<string>(() => {
    const unknown = props.recipe.nutrition.unknown;

    if (unknown.length === 0) {
        return 'wszystkiego';
    }

    return (
        unknown.slice(0, 3).join(', ') + (unknown.length > 3 ? ' i innych' : '')
    );
});
const servings = computed<number>(
    () => props.recipe.scale.servings ?? props.recipe.scale.base ?? 0,
);

/** 1 porcja, 2–4 porcje, 5+ porcji — and 12–14 are "porcji", not "porcje". */
const servingsWord = computed<string>(() => {
    const count = servings.value;

    if (count === 1) {
        return 'porcja';
    }

    const last = count % 10;
    const teens = count % 100;

    return last >= 2 && last <= 4 && (teens < 12 || teens > 14)
        ? 'porcje'
        : 'porcji';
});

/**
 * The portions to send with a write. Absent when the recipe is being read as
 * written, so an unscaled dish posts exactly what it always did.
 */
function portions(): { porcje?: number } {
    return props.recipe.scale.isScaled ? { porcje: servings.value } : {};
}

function setServings(next: number): void {
    const target = Math.max(1, Math.min(MAX_SERVINGS, next));

    if (target === servings.value) {
        return;
    }

    rescaling.value = true;

    router.get(
        show.url(props.recipe.slug),
        { porcje: target },
        {
            // Only the recipe: re-rendering the list underneath would throw away
            // the search and the chip, exactly as opening the modal must not.
            only: ['recipe'],
            preserveState: true,
            preserveScroll: true,
            // The number of portions is not a step in anybody's history.
            replace: true,
            onFinish: () => {
                rescaling.value = false;
            },
        },
    );
}

/** How long the result stays before the button offers itself again. */
const CONFIRMATION_MS = 3200;
let confirmationTimer: ReturnType<typeof setTimeout> | null = null;

const shoppingLabel = computed<string>(() => {
    if (adding.value) {
        return 'Dodaję…';
    }

    if (added.value === null) {
        return 'Dodaj do listy zakupów';
    }

    // Nothing added is a real answer: you already have all of it.
    if (added.value === 0) {
        return 'Masz już wszystko';
    }

    // With several lists, "added" is only half the answer — where matters.
    return addedTo.value === null
        ? `Dodano ${added.value}`
        : `Dodano ${added.value} → ${addedTo.value}`;
});

/**
 * The lines the importer never resolved to a product. There is nothing to write
 * down for them, so they are reported rather than invented — silently dropping
 * them would make the list look complete when it is not.
 */
const shoppingNote = computed<string | null>(() => {
    if (adding.value || added.value === null || unknown.value === 0) {
        return null;
    }

    return unknown.value === 1
        ? '1 składnika nie rozpoznano — dopisz go ręcznie'
        : `${unknown.value} składników nie rozpoznano — dopisz je ręcznie`;
});

/**
 * Cooking carries the portions over. Reading a recipe scaled to six and then
 * being walked through the amounts for four is the one way the guided screen
 * could be actively wrong.
 */
const cookHref = computed<string>(() =>
    props.recipe.scale.isScaled && props.recipe.scale.servings !== null
        ? cook.url(props.recipe.slug, {
              query: { porcje: props.recipe.scale.servings },
          })
        : cook.url(props.recipe.slug),
);

const restLabel = computed<string>(() => {
    if (addingRest.value) {
        return 'Dodaję…';
    }

    if (restAdded.value !== null) {
        return `Dodano ${restAdded.value}`;
    }

    return `Dodaj też to, co masz (${skipped.value})`;
});

/*
 * Which list the shortfall lands on.
 *
 * With one list there is nothing to ask, and asking would put a decision in
 * front of the only answer. From two lists up, the tap opens the choice — a
 * dish added to the wrong trolley is found at the till, not before.
 */
const choosingList = ref(false);
const newListName = ref('');
const addedTo = ref<string | null>(null);
const lastListId = ref<number | null>(null);

const hasChoice = computed<boolean>(() => props.shoppingLists.length > 1);

function startAdding(): void {
    if (hasChoice.value) {
        choosingList.value = !choosingList.value;

        return;
    }

    addToShoppingList();
}

function addToShoppingList(target?: { id: number } | { name: string }): void {
    adding.value = true;
    added.value = null;
    restAdded.value = null;
    skipped.value = 0;
    choosingList.value = false;
    newListName.value = '';

    if (confirmationTimer !== null) {
        clearTimeout(confirmationTimer);
    }

    router.post(
        addRecipeToShoppingList.url(props.recipe.slug),
        {
            // What is on screen is what goes in the trolley. Left off, the
            // button would quietly buy for the recipe's own portions while the
            // list above it showed a different set of amounts.
            ...portions(),
            ...(target === undefined
                ? {}
                : 'id' in target
                  ? { shopping_list_id: target.id }
                  : { new_list_name: target.name }),
        },
        {
            preserveScroll: true,
            preserveState: true,
            /*
             * Only the result comes back. Without this the redirect re-renders
             * the list underneath, which throws away the search and the chip the
             * user had set and drops the recipe out of the address bar.
             */
            only: ['flash'],
            preserveUrl: true,
            onSuccess: (page) => {
                const result = page.props.flash?.shopping;
                added.value =
                    typeof result?.added === 'number' ? result.added : 0;
                unknown.value =
                    typeof result?.unknown === 'number' ? result.unknown : 0;
                skipped.value =
                    typeof result?.skipped === 'number' ? result.skipped : 0;
                // Which list it went on, so a household with several can see
                // the answer to the question it was just asked.
                addedTo.value =
                    typeof result?.list === 'string' ? result.list : null;
                // "The rest" has to follow the shortfall onto the same list,
                // including one that was created a moment ago by name.
                lastListId.value =
                    typeof result?.listId === 'number' ? result.listId : null;

                // The confirmation is a moment, not a state: leaving it up for
                // good would make a second helping look like it had failed. The
                // offer below it is not on that timer — it is something to act
                // on, and three seconds is not long enough to decide.
                confirmationTimer = setTimeout(() => {
                    added.value = null;
                    unknown.value = 0;
                    addedTo.value = null;
                }, CONFIRMATION_MS);
            },
            onFinish: () => {
                adding.value = false;
            },
        },
    );
}

/**
 * The rest of it, at the full amount the recipe asks for. Asked for explicitly,
 * which is the only way this is ever sent: the shortfall stays the default.
 */
function addRestToList(): void {
    addingRest.value = true;

    router.post(
        addRestToShoppingList.url(props.recipe.slug),
        {
            ...portions(),
            ...(lastListId.value === null
                ? {}
                : { shopping_list_id: lastListId.value }),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['flash'],
            preserveUrl: true,
            onSuccess: (page) => {
                const result = page.props.flash?.shopping;
                restAdded.value =
                    typeof result?.added === 'number' ? result.added : 0;
                // The offer has been taken: it must not stand there inviting a
                // second, silently doubling helping.
                skipped.value = 0;
            },
            onFinish: () => {
                addingRest.value = false;
            },
        },
    );
}

/*
 * The offer outlives the confirmation, so it has to die with its own recipe:
 * this component is mounted with `v-if` rather than keyed, and an offer to add
 * three things you have would otherwise carry over onto a different dish.
 */
watch(
    () => props.recipe.slug,
    () => {
        added.value = null;
        unknown.value = 0;
        skipped.value = 0;
        restAdded.value = null;
        addedTo.value = null;
        lastListId.value = null;
        choosingList.value = false;
        newListName.value = '';
    },
);

/**
 * Shows the original source line under every parsed one. This screen is also how
 * the import gets verified, so the raw wording has to stay inspectable.
 */
const showRawText = ref(false);
const dialog = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

/** Returned to on close, so keyboard users land back where they were. */
let previouslyFocused: HTMLElement | null = null;

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');

        return;
    }

    if (event.key !== 'Tab' || dialog.value === null) {
        return;
    }

    // Without a trap, Tab walks out of the dialog and into the list behind it.
    const focusable = [
        ...dialog.value.querySelectorAll<HTMLElement>(FOCUSABLE),
    ];
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (first === undefined || last === undefined) {
        return;
    }

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();

        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

onMounted(() => {
    previouslyFocused = document.activeElement as HTMLElement | null;
    document.addEventListener('keydown', onKeydown);
    document.body.classList.add('overflow-hidden');
    closeButton.value?.focus();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('overflow-hidden');
    previouslyFocused?.focus();

    // Closing the modal mid-confirmation must not leave a timer writing to refs
    // that are gone.
    if (confirmationTimer !== null) {
        clearTimeout(confirmationTimer);
    }
});
</script>

<template>
    <!--
      Full screen on a phone, a centred sheet from sm up. An installed app that
      shows a recipe in a small floating box wastes the screen it was given.
    -->
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-contain bg-ink/40 sm:p-6"
        @click.self="emit('close')"
    >
        <div
            ref="dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="recipe-title"
            class="relative mx-auto min-h-dvh w-full bg-paper-raised sm:min-h-0 sm:max-w-3xl sm:rounded-3xl"
        >
            <button
                ref="closeButton"
                type="button"
                class="absolute top-safe right-3 z-10 mt-3 flex h-11 w-11 items-center justify-center rounded-full bg-paper/90 text-ink backdrop-blur transition-colors hover:text-accent-strong sm:top-3 sm:mt-0"
                aria-label="Zamknij przepis"
                @click="emit('close')"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    stroke-linecap="round"
                    class="h-5 w-5"
                    aria-hidden="true"
                >
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>

            <img
                v-if="props.recipe.imageUrl"
                :src="props.recipe.imageUrl"
                alt=""
                class="h-56 w-full object-cover sm:h-80 sm:rounded-t-3xl"
            />

            <div class="px-5 py-8 pb-safe sm:px-12 sm:py-12">
                <header>
                    <p
                        v-if="props.recipe.appliance || props.recipe.isMealPrep"
                        class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 label-caps text-accent-strong"
                    >
                        <span
                            v-if="props.recipe.appliance"
                            class="flex items-center gap-1.5"
                        >
                            <ApplianceIcon
                                :appliance="props.recipe.appliance"
                            />
                            {{ applianceLabel(props.recipe.appliance) }}
                        </span>
                        <span v-if="props.recipe.isMealPrep">do pudełka</span>
                    </p>

                    <h2
                        id="recipe-title"
                        class="text-2xl leading-tight font-semibold text-balance text-ink sm:text-3xl"
                    >
                        {{ props.recipe.title }}
                    </h2>

                    <p
                        v-if="props.recipe.description"
                        class="mt-4 max-w-prose leading-relaxed text-ink-muted"
                    >
                        {{ props.recipe.description }}
                    </p>

                    <dl
                        class="mt-6 flex flex-wrap gap-x-8 gap-y-2 border-y border-rule py-3 text-sm"
                    >
                        <div
                            v-if="props.recipe.servingsLabel"
                            class="flex items-center gap-2"
                        >
                            <dt class="text-ink-faint">
                                <AppIcon name="servings" label="Porcje" />
                            </dt>
                            <dd class="text-ink">
                                {{ props.recipe.servingsLabel }}
                            </dd>
                        </div>
                        <div
                            v-if="props.recipe.totalTimeMinutes"
                            class="flex items-center gap-2"
                        >
                            <dt class="text-ink-faint">
                                <AppIcon name="clock" label="Czas" />
                            </dt>
                            <dd class="text-ink tabular-nums">
                                {{ props.recipe.totalTimeMinutes }} min
                            </dd>
                        </div>
                        <div class="flex items-center gap-2">
                            <dt class="text-ink-faint">
                                <AppIcon name="source" label="Źródło" />
                            </dt>
                            <dd>
                                <a
                                    v-if="props.recipe.sourceUrl"
                                    :href="props.recipe.sourceUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-block py-1 text-ink underline decoration-rule-strong underline-offset-4 hover:decoration-accent"
                                >
                                    {{ props.recipe.sourceName }}
                                </a>
                                <span v-else class="text-ink">
                                    {{ props.recipe.sourceName }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="props.recipe.importedAt" class="flex gap-2">
                            <dt class="text-ink-faint">Zaimportowano</dt>
                            <dd class="text-ink tabular-nums">
                                {{ props.recipe.importedAt }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="props.recipe.tags.length > 0"
                        class="mt-4 text-sm text-ink-faint"
                    >
                        {{ props.recipe.tags.join(' · ') }}
                    </p>
                </header>

                <section class="mt-12">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-ink pb-2"
                    >
                        <h3 class="label-caps text-ink">Składniki</h3>

                        <!--
                            Sits with the amounts rather than up in the meta row,
                            because the amounts are what it changes. Offered only
                            when the source said how many portions it wrote for:
                            without that number there is nothing to scale from.
                        -->
                        <div
                            v-if="canScale"
                            class="flex items-center gap-0.5"
                            role="group"
                            aria-label="Liczba porcji"
                        >
                            <button
                                type="button"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink disabled:opacity-40"
                                :disabled="rescaling || servings <= 1"
                                aria-label="Mniej porcji"
                                @click="setServings(servings - 1)"
                            >
                                <AppIcon name="minus" />
                            </button>

                            <span
                                class="min-w-24 text-center text-sm text-ink tabular-nums"
                                role="status"
                                aria-live="polite"
                            >
                                {{ servings }}
                                {{ servingsWord }}
                            </span>

                            <button
                                type="button"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink disabled:opacity-40"
                                :disabled="
                                    rescaling || servings >= MAX_SERVINGS
                                "
                                aria-label="Więcej porcji"
                                @click="setServings(servings + 1)"
                            >
                                <AppIcon name="plus" />
                            </button>
                        </div>
                    </div>

                    <!--
                        Said out loud, because every amount below now differs from
                        the page the recipe was copied from — and "pokaż oryginalny
                        tekst" is the way back to what the source actually wrote.
                    -->
                    <p
                        v-if="props.recipe.scale.isScaled"
                        class="mt-2 text-sm text-ink-faint"
                    >
                        Ilości przeliczone z {{ props.recipe.scale.base }} na
                        {{ servings }} {{ servingsWord }}.
                    </p>

                    <!--
                        What a portion is worth. Read after the scaling, so it
                        answers about the porcja on screen rather than the one
                        the source happened to write.
                    -->
                    <div
                        v-if="nutrition !== null"
                        class="mt-4 rounded-2xl bg-paper-sunk px-4 py-3"
                    >
                        <p class="mb-2 label-caps text-ink-muted">
                            W jednej porcji
                        </p>

                        <div
                            class="flex flex-wrap items-baseline gap-x-5 gap-y-1"
                        >
                            <p
                                class="text-xl font-semibold text-ink tabular-nums"
                            >
                                {{ nutrition.kcal }}
                                <span
                                    class="text-sm font-normal text-ink-muted"
                                >
                                    kcal
                                </span>
                            </p>

                            <p
                                v-for="macro in macros"
                                :key="macro.label"
                                class="text-sm text-ink tabular-nums"
                            >
                                <span class="text-ink-muted">
                                    {{ macro.label }}
                                </span>
                                {{ macro.grams }} g
                            </p>
                        </div>

                        <!--
                            The honest caveat. A figure short by a fifth reads
                            exactly like a light meal, so where the count is
                            partial it is called a floor and the missing
                            products are named.
                        -->
                        <p
                            v-if="!props.recipe.nutrition.isReliable"
                            class="mt-2 text-sm text-flag"
                        >
                            To dolna granica — nie umiem policzyć
                            {{ uncountedLabel }}.
                        </p>
                        <p
                            v-else-if="props.recipe.nutrition.coverage < 1"
                            class="mt-2 text-sm text-ink-faint"
                        >
                            Bez {{ uncountedLabel }} — może być odrobinę więcej.
                        </p>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <div class="flex flex-col items-end gap-1">
                            <!--
                                Only what the kitchen cannot already supply — but
                                the label says the plain thing, because "braki"
                                only makes sense once you know the fridge is
                                being consulted at all.
                            -->
                            <button
                                type="button"
                                :disabled="adding"
                                class="flex h-11 items-center gap-2 rounded-full bg-accent px-4 text-sm font-medium text-ink transition-transform duration-150 active:scale-95 disabled:opacity-70"
                                :class="{ 'animate-confirmed': added !== null }"
                                :aria-expanded="
                                    hasChoice ? choosingList : undefined
                                "
                                @click="startAdding"
                            >
                                <!--
                                    The icon carries the state change; keying the
                                    span on the label makes Vue replace it, so the
                                    new one animates in rather than the text
                                    swapping under a static icon.
                                -->
                                <span
                                    v-if="adding"
                                    class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-ink/25 border-t-ink"
                                    aria-hidden="true"
                                />
                                <AppIcon
                                    v-else
                                    :name="added === null ? 'cart' : 'check'"
                                />

                                <span
                                    :key="shoppingLabel"
                                    class="animate-rise-in"
                                >
                                    {{ shoppingLabel }}
                                </span>
                            </button>

                            <!--
                                Which list. Only ever shown when there is more
                                than one — with a single list the question has
                                one answer and asking it is a tap for nothing.
                            -->
                            <div
                                v-if="choosingList"
                                class="w-64 rounded-2xl border border-rule bg-paper-raised p-2 text-left shadow-sm"
                            >
                                <p
                                    class="px-2 pt-1 pb-2 label-caps text-ink-faint"
                                >
                                    Do której listy?
                                </p>

                                <button
                                    v-for="option in shoppingLists"
                                    :key="option.id"
                                    type="button"
                                    class="flex h-11 w-full items-center rounded-xl px-3 text-left text-sm text-ink hover:bg-paper-sunk"
                                    @click="
                                        addToShoppingList({ id: option.id })
                                    "
                                >
                                    {{ option.name }}
                                </button>

                                <form
                                    class="mt-1 flex items-center gap-1 border-t border-rule pt-2"
                                    @submit.prevent="
                                        newListName.trim() !== '' &&
                                        addToShoppingList({
                                            name: newListName.trim(),
                                        })
                                    "
                                >
                                    <label class="sr-only" for="modal-new-list">
                                        Nazwa nowej listy
                                    </label>
                                    <input
                                        id="modal-new-list"
                                        v-model="newListName"
                                        type="text"
                                        maxlength="60"
                                        placeholder="Nowa lista…"
                                        class="h-11 min-w-0 flex-1 rounded-xl border border-rule-strong px-3 text-sm text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="newListName.trim() === ''"
                                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink disabled:opacity-40"
                                        aria-label="Dodaj do nowej listy"
                                    >
                                        <AppIcon name="plus" />
                                    </button>
                                </form>
                            </div>

                            <!-- Never invented, so it has to be said out loud. -->
                            <p
                                v-if="shoppingNote"
                                class="animate-rise-in text-xs text-flag"
                            >
                                {{ shoppingNote }}
                            </p>

                            <!--
                                What the kitchen covered, and the way to overrule
                                it. Secondary on purpose — the shortfall is the
                                right answer nearly every time — and it outlives
                                the confirmation above, because this one is a
                                decision rather than a receipt.
                            -->
                            <template v-if="skipped > 0 || restAdded !== null">
                                <p
                                    v-if="skipped > 0"
                                    class="animate-rise-in text-xs text-ink-muted"
                                >
                                    Pominięto {{ skipped }} — masz to w kuchni
                                </p>
                                <button
                                    type="button"
                                    :disabled="addingRest || restAdded !== null"
                                    class="flex h-11 items-center gap-2 rounded-full border border-rule-strong px-4 text-sm text-ink-muted transition-transform duration-150 active:scale-95 disabled:opacity-70"
                                    @click="addRestToList"
                                >
                                    <span
                                        v-if="addingRest"
                                        class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-ink/25 border-t-ink"
                                        aria-hidden="true"
                                    />
                                    <AppIcon
                                        v-else
                                        :name="
                                            restAdded === null
                                                ? 'plus'
                                                : 'check'
                                        "
                                    />

                                    <span
                                        :key="restLabel"
                                        class="animate-rise-in"
                                    >
                                        {{ restLabel }}
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!--
                        The button's own label is the visual confirmation, but a
                        screen reader needs it announced without moving focus.
                    -->
                    <p class="sr-only" role="status" aria-live="polite">
                        {{ adding ? '' : shoppingLabel }}
                        <template v-if="!adding && skipped > 0">
                            · pominięto {{ skipped }}, bo masz to w kuchni
                        </template>
                    </p>

                    <div
                        v-for="group in ingredientGroups"
                        :key="group.section ?? 'default'"
                        class="mt-6"
                    >
                        <h4
                            v-if="group.section"
                            class="mb-1 text-base font-semibold text-ink"
                        >
                            {{ group.section }}
                        </h4>

                        <ul class="divide-y divide-rule">
                            <li
                                v-for="{ line, marker } in group.items"
                                :key="line.id"
                                class="flex items-baseline gap-4 py-2"
                            >
                                <span
                                    class="w-24 shrink-0 text-right text-sm text-ink tabular-nums"
                                >
                                    {{ formatAmount(line) || '—' }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <IngredientLabel
                                        class="text-ink"
                                        :emoji="line.emoji"
                                        :name="line.ingredient ?? line.rawText"
                                    />
                                    <span
                                        v-if="line.note"
                                        class="text-ink-faint"
                                    >
                                        , {{ line.note }}
                                    </span>
                                    <!--
                                        Both answers now, not only the good one.
                                        An unresolved line still gets none: there
                                        is no product to check against a shelf.
                                    -->
                                    <span
                                        v-if="marker"
                                        class="ml-2 inline-flex items-center gap-1 label-caps"
                                        :class="marker.tone"
                                    >
                                        <!-- Standing alone, the tick has to carry
                                             the name a sighted reader gets from
                                             its colour and shape. -->
                                        <AppIcon
                                            :name="marker.icon"
                                            :label="
                                                marker.showLabel
                                                    ? undefined
                                                    : marker.label
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                        <template v-if="marker.showLabel">
                                            {{ marker.label }}
                                        </template>
                                    </span>
                                    <span
                                        v-if="line.isOptional"
                                        class="ml-2 label-caps text-ink-faint"
                                    >
                                        opcjonalnie
                                    </span>
                                    <span
                                        v-if="line.needsReview"
                                        class="ml-2 label-caps text-flag"
                                    >
                                        sprawdź
                                    </span>
                                    <span
                                        v-if="showRawText"
                                        class="mt-0.5 block text-sm text-ink-faint italic"
                                    >
                                        {{ line.rawText }}
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <section class="mt-12">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-ink pb-2"
                    >
                        <h3 class="label-caps text-ink">Przygotowanie</h3>

                        <!--
                            The same steps, one at a time, on a screen that keeps
                            itself awake and runs the timers. A page of its own
                            rather than a mode of this modal: it has to survive a
                            reload and be openable on the other phone.
                        -->
                        <Link
                            v-if="props.recipe.steps.length > 0"
                            :href="cookHref"
                            class="flex h-11 items-center gap-2 rounded-full bg-accent px-4 text-sm font-medium text-ink"
                        >
                            <AppIcon name="play" />
                            Gotujmy
                        </Link>
                    </div>

                    <ol class="mt-6 ml-3 border-l border-rule">
                        <li
                            v-for="step in props.recipe.steps"
                            :key="step.position"
                            class="relative py-5 pl-8"
                        >
                            <span
                                class="absolute top-5 -left-3 grid h-6 w-6 place-items-center rounded-full bg-paper-raised text-sm text-ink-faint tabular-nums ring-1 ring-rule"
                            >
                                {{ step.position + 1 }}
                            </span>

                            <p
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 label-caps text-accent-strong"
                            >
                                <span>{{ actionLabel(step.action) }}</span>
                                <span
                                    v-if="step.appliance"
                                    class="flex items-center gap-1.5 text-ink-muted"
                                >
                                    <ApplianceIcon
                                        :appliance="step.appliance"
                                    />
                                    {{ applianceLabel(step.appliance) }}
                                </span>
                                <span
                                    v-if="step.temperatureCelsius"
                                    class="text-ink-muted tabular-nums"
                                >
                                    {{ step.temperatureCelsius }} °C
                                </span>
                                <span
                                    v-if="step.durationSeconds"
                                    class="text-ink-muted tabular-nums"
                                >
                                    {{ formatDuration(step.durationSeconds) }}
                                </span>
                            </p>

                            <ul
                                v-if="step.uses.length > 0"
                                class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-ink"
                            >
                                <li
                                    v-for="line in step.uses"
                                    :key="line.id"
                                    class="border-b-2 border-accent-soft"
                                >
                                    <span class="tabular-nums">
                                        {{ formatAmount(line) }}
                                    </span>
                                    <IngredientLabel
                                        inline
                                        :emoji="line.emoji"
                                        :name="line.ingredient ?? ''"
                                    />
                                </li>
                            </ul>

                            <p class="mt-2 leading-relaxed text-ink-muted">
                                {{ step.instruction }}
                            </p>
                        </li>
                    </ol>
                </section>

                <footer class="mt-12 border-t border-rule pt-4">
                    <!-- The whole row is the target: a bare checkbox is 13px,
                         which is not something a thumb can hit. -->
                    <label
                        class="-mx-2 flex min-h-11 cursor-pointer items-center gap-3 px-2 text-sm text-ink-faint"
                    >
                        <input
                            v-model="showRawText"
                            type="checkbox"
                            class="h-5 w-5 shrink-0 [accent-color:var(--color-accent)]"
                        />
                        Pokaż oryginalny tekst ze strony
                    </label>
                </footer>
            </div>
        </div>
    </div>
</template>
