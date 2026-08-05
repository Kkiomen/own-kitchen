<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import {
    index as mealPlan,
    destroy,
    generate as generateRoute,
    shop,
    store,
    swap,
    update,
} from '@/routes/meal-plan';
import { show } from '@/routes/recipes';
import { show as shoppingList } from '@/routes/shopping';

/** One dish or note planned for one meal. */
interface PlanEntry {
    id: number;
    slot: string;
    date: string;
    title: string;
    note: string | null;
    slug: string | null;
    imageUrl: string | null;
    servings: number | null;
    /** What the recipe itself makes, which is what the portions scale against. */
    recipeServings: number | null;
    totalTimeMinutes: number | null;
    isMealPrep: boolean;
    /** How many products this dish is short of, or null for a note. */
    missing: number | null;
}

interface PlanSlot {
    value: string;
    label: string;
    entries: PlanEntry[];
}

interface PlanDay {
    date: string;
    weekday: string;
    dayOfMonth: number;
    isToday: boolean;
    slots: PlanSlot[];
}

interface RecipeMatch {
    id: number;
    slug: string;
    title: string;
    imageUrl: string | null;
    servings: number | null;
    servingsLabel: string | null;
    totalTimeMinutes: number | null;
    isMealPrep: boolean;
}

const props = defineProps<{
    weekStart: string;
    previousWeek: string;
    nextWeek: string;
    thisWeek: string;
    days: PlanDay[];
    slots: { value: string; label: string }[];
    defaultServings: number;
    matches: RecipeMatch[];
}>();

const page = usePage();

const plannedCount = computed<number>(() =>
    props.days.reduce(
        (total, day) =>
            total +
            day.slots.reduce((count, slot) => count + slot.entries.length, 0),
        0,
    ),
);

const weekLabel = computed<string>(() => {
    const start = new Date(props.weekStart);
    const end = new Date(props.weekStart);
    end.setDate(end.getDate() + 6);

    const day = (date: Date): string =>
        date.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long' });

    return `${day(start)} – ${day(end)}`;
});

/*
 * Which days go shopping. Kept in the browser rather than the URL: it is a
 * gesture towards one button, not a view worth sharing or coming back to.
 */
const chosenDays = ref<string[]>([]);

/** Changing week must not carry a tick from days no longer on screen. */
watch(
    () => props.weekStart,
    () => {
        chosenDays.value = [];
        confirmation.value = null;
    },
);

function toggleDay(date: string): void {
    chosenDays.value = chosenDays.value.includes(date)
        ? chosenDays.value.filter((chosen) => chosen !== date)
        : [...chosenDays.value, date];
}

/** Days holding nothing have nothing to buy, so "all" means all planned. */
const plannedDays = computed<string[]>(() =>
    props.days
        .filter((day) => day.slots.some((slot) => slot.entries.length > 0))
        .map((day) => day.date),
);

const allChosen = computed<boolean>(
    () =>
        plannedDays.value.length > 0 &&
        plannedDays.value.every((date) => chosenDays.value.includes(date)),
);

function toggleWholeWeek(): void {
    chosenDays.value = allChosen.value ? [] : [...plannedDays.value];
}

/* ---------- planning a meal ---------- */

const sheet = ref<{ date: string; slot: string } | null>(null);
const search = ref('');
const chosen = ref<RecipeMatch | null>(null);
const writingNote = ref(false);
const searchField = ref<HTMLInputElement | null>(null);

/**
 * The picker searches within the meal it was opened from, so "jajka" under
 * Obiad does not offer scrambled eggs. Roughly a quarter of the catalogue
 * matched no meal-time rule at all, though, and refusing to show those would
 * make a dish you can see in the list unplannable — hence the way out.
 */
const wholeCatalogue = ref(false);

const form = useForm({
    date: '',
    slot: '',
    recipe_id: null as number | null,
    note: null as string | null,
    servings: props.defaultServings,
});

async function openSheet(date: string, slot: string): Promise<void> {
    sheet.value = { date, slot };
    chosen.value = null;
    writingNote.value = false;
    wholeCatalogue.value = false;
    search.value = '';
    form.reset();
    form.clearErrors();
    form.date = date;
    form.slot = slot;

    await nextTick();
    searchField.value?.focus();
}

function closeSheet(): void {
    sheet.value = null;
}

const slotLabel = computed<string>(
    () =>
        props.slots.find((slot) => slot.value === sheet.value?.slot)?.label ??
        '',
);

/**
 * The catalogue holds ten thousand recipes, so the search runs on the server —
 * a partial visit that replaces only `matches`. `preserveUrl` keeps the week in
 * the address bar: what is being typed into a sheet is not a page worth
 * returning to.
 */
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (term) => {
    clearTimeout(searchTimer);
    chosen.value = null;

    searchTimer = setTimeout(() => {
        router.reload({
            data: {
                week: props.weekStart,
                search: term,
                slot: wholeCatalogue.value ? '' : (sheet.value?.slot ?? ''),
            },
            only: ['matches'],
            preserveUrl: true,
        });
    }, 250);
});

/** Widening the search has to fetch again; the narrow results are the wrong set. */
function searchEverywhere(): void {
    wholeCatalogue.value = true;

    router.reload({
        data: { week: props.weekStart, search: search.value, slot: '' },
        only: ['matches'],
        preserveUrl: true,
    });
}

function chooseRecipe(recipe: RecipeMatch): void {
    chosen.value = recipe;
    form.recipe_id = recipe.id;
    form.note = null;
    /*
     * Two people eat here, and that is the amount nearly every time. It is one
     * tap from being changed and, unlike a pack size, it is not a guess about
     * the world — it is a household fact.
     */
    form.servings = props.defaultServings;
}

function startNote(): void {
    writingNote.value = true;
    chosen.value = null;
    form.recipe_id = null;
    form.note = search.value.trim() === '' ? '' : search.value.trim();
}

function submit(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: closeSheet,
    });
}

/* ---------- changing what is already planned ---------- */

/**
 * The portions shown while a change is in flight, so tapping "+" three times
 * counts three times without waiting for three round trips. Each request
 * carries the absolute number, so the last tap wins whatever order the
 * responses come back in.
 */
const drafts = ref<Record<number, number>>({});

function shownServings(entry: PlanEntry): number {
    return drafts.value[entry.id] ?? entry.servings ?? props.defaultServings;
}

function setServings(entry: PlanEntry, servings: number): void {
    if (servings < 1 || servings > 99) {
        return;
    }

    drafts.value[entry.id] = servings;

    router.patch(
        update.url(entry.id),
        { servings },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                delete drafts.value[entry.id];
            },
        },
    );
}

/**
 * Another dish for this meal, under the same rules the week was generated by:
 * nothing eaten within the month, the kitchen first. The portions stay — the
 * dish changed, not how many people are eating.
 */
const swapping = ref<number | null>(null);

function swapDish(entry: PlanEntry): void {
    swapping.value = entry.id;

    router.post(
        swap.url(entry.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                swapping.value = null;
            },
        },
    );
}

function remove(entry: PlanEntry): void {
    router.delete(destroy.url(entry.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

/** Slots this day has no row for yet — the two meals that are not everyday. */
function spareSlots(day: PlanDay): { value: string; label: string }[] {
    return props.slots.filter(
        (slot) => !day.slots.some((shown) => shown.value === slot.value),
    );
}

/* ---------- turning the week into shopping ---------- */

interface PlanResult {
    added: number;
    unknown: number;
    notes: number;
    unscaled: string[];
    /** Recipes bought as one whole cooking, because fewer portions were planned. */
    wholeBatches: string[];
    /** Lines already ticked off, left exactly as they were. */
    kept: number;
    meals: number;
    /** The list it went on: a new one, named after the days. */
    list: string;
    listId: number;
}

const shopping = ref(false);
const confirmation = ref<PlanResult | null>(null);
let confirmationTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * The result of this button is invisible — the list it feeds is another screen —
 * so it reports back in place. The message expires, because a permanent
 * "dopisano 12" makes a second week's shopping look like it failed.
 */
const CONFIRMATION_MS = 12_000;

function addToShoppingList(): void {
    if (chosenDays.value.length === 0 || shopping.value) {
        return;
    }

    shopping.value = true;

    router.post(
        shop.url(),
        { dates: chosenDays.value },
        {
            only: ['flash'],
            preserveUrl: true,
            preserveScroll: true,
            onSuccess: () => {
                const result = page.props.flash?.plan as PlanResult | undefined;

                if (result === undefined) {
                    return;
                }

                confirmation.value = result;
                clearTimeout(confirmationTimer);
                confirmationTimer = setTimeout(() => {
                    confirmation.value = null;
                }, CONFIRMATION_MS);
            },
            onFinish: () => {
                shopping.value = false;
            },
        },
    );
}

/* ---------- filling the week in ---------- */

interface GenerateResult {
    added: number;
    skipped: number;
    /** Meals no recipe is tagged for — a real answer, not an error. */
    empty: string[];
}

const generating = ref(false);
const generated = ref<GenerateResult | null>(null);
let generatedTimer: ReturnType<typeof setTimeout> | undefined;

/** Ticked days if there are any, otherwise the whole week on screen. */
const daysToFill = computed<string[]>(() =>
    chosenDays.value.length > 0
        ? chosenDays.value
        : props.days.map((day) => day.date),
);

const generateLabel = computed<string>(() =>
    generating.value ? 'Układam…' : 'Wygeneruj',
);

const generatedMessage = computed<string>(() => {
    const result = generated.value;

    if (result === null) {
        return '';
    }

    const parts =
        result.added === 0
            ? ['Nie było czego uzupełnić — te dni są już zaplanowane.']
            : [`Wpisałem ${result.added} posiłków.`];

    if (result.added > 0 && result.skipped > 0) {
        parts.push(`${result.skipped} zostawiłem bez zmian.`);
    }

    if (result.empty.length > 0) {
        parts.push(
            `Bez propozycji: ${result.empty.join(', ')} — żaden przepis nie ma tej pory.`,
        );
    }

    return parts.join(' ');
});

/**
 * What to fill in, one row of ticks per day.
 *
 * A week is not uniform — a podwieczorek at the weekend, a drugie śniadanie on
 * working days — so this is a grid rather than two lists. It opens on the
 * everyday three, which is the answer most days want, and every cell is one tap
 * from being changed.
 */
const wanted = ref<Record<string, string[]>>({});
const generatorOpen = ref(false);
const generatorServings = ref(props.defaultServings);

const EVERYDAY = ['breakfast', 'lunch', 'dinner'];

function openGenerator(): void {
    const days = daysToFill.value;

    wanted.value = Object.fromEntries(
        props.days.map((day) => [
            day.date,
            days.includes(day.date) ? [...EVERYDAY] : [],
        ]),
    );
    generatorServings.value = props.defaultServings;
    generatorOpen.value = true;
}

function wants(date: string, slot: string): boolean {
    return wanted.value[date]?.includes(slot) ?? false;
}

function toggleWanted(date: string, slot: string): void {
    const slots = wanted.value[date] ?? [];

    wanted.value = {
        ...wanted.value,
        [date]: slots.includes(slot)
            ? slots.filter((chosen) => chosen !== slot)
            : [...slots, slot],
    };
}

/** The header of a column ticks that meal on every day, and unticks it again. */
function toggleWantedColumn(slot: string): void {
    const everywhere = props.days.every((day) => wants(day.date, slot));

    wanted.value = Object.fromEntries(
        props.days.map((day) => {
            const slots = (wanted.value[day.date] ?? []).filter(
                (chosen) => chosen !== slot,
            );

            return [day.date, everywhere ? slots : [...slots, slot]];
        }),
    );
}

/** The label of a row does the same for one day: all five, or none. */
function toggleWantedRow(date: string): void {
    const all = props.slots.length === (wanted.value[date]?.length ?? 0);

    wanted.value = {
        ...wanted.value,
        [date]: all ? [] : props.slots.map((slot) => slot.value),
    };
}

const wantedCount = computed<number>(() =>
    Object.values(wanted.value).reduce(
        (total, slots) => total + slots.length,
        0,
    ),
);

/** Which cells are already spoken for, so the grid can grey them out. */
function isPlanned(date: string, slot: string): boolean {
    const day = props.days.find((candidate) => candidate.date === date);

    return (
        day?.slots.some(
            (shown) => shown.value === slot && shown.entries.length > 0,
        ) ?? false
    );
}

function generate(): void {
    if (generating.value || wantedCount.value === 0) {
        return;
    }

    generating.value = true;

    router.post(
        generateRoute.url(),
        {
            days: Object.entries(wanted.value)
                .filter(([, slots]) => slots.length > 0)
                .map(([date, slots]) => ({ date, slots })),
            servings: generatorServings.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                const result = page.props.flash?.generated as
                    GenerateResult | undefined;

                if (result === undefined) {
                    return;
                }

                generatorOpen.value = false;
                generated.value = result;
                clearTimeout(generatedTimer);
                generatedTimer = setTimeout(() => {
                    generated.value = null;
                }, CONFIRMATION_MS);
            },
            onFinish: () => {
                generating.value = false;
            },
        },
    );
}

/* ---------- the calendar strip ---------- */

function mealCount(day: PlanDay): number {
    return day.slots.reduce((count, slot) => count + slot.entries.length, 0);
}

/** Tapping a date in the strip brings its card into view, wherever it sits. */
function jumpTo(date: string): void {
    document
        .getElementById(`day-${date}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearTimeout(confirmationTimer);
    clearTimeout(generatedTimer);
});

watch([sheet, generatorOpen], ([picker, generator]) => {
    document.body.style.overflow =
        picker === null && !generator ? '' : 'hidden';
});
</script>

<template>
    <Head title="Plan na tydzień" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader
            title="Plan na tydzień"
            current="plan"
            :count="plannedCount"
            wide
        >
            <template #subtitle>
                <p class="text-sm text-ink-muted">{{ weekLabel }}</p>
            </template>

            <!--
              The calendar strip: one week at a time, paged with the arrows, with
              a dot under the days that hold something. Tapping a day jumps to it
              rather than ticking it — ticking is what the cards below are for,
              and one control doing two things is how the wrong one gets pressed.
            -->
            <div class="pb-3">
                <div class="flex items-center gap-1">
                    <Link
                        :href="
                            mealPlan.url({
                                query: { week: props.previousWeek },
                            })
                        "
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink"
                        aria-label="Poprzedni tydzień"
                        preserve-scroll
                    >
                        <AppIcon name="chevronLeft" />
                    </Link>

                    <p
                        class="flex-1 text-center text-sm font-medium text-ink tabular-nums"
                    >
                        {{ weekLabel }}
                    </p>

                    <Link
                        :href="
                            mealPlan.url({ query: { week: props.nextWeek } })
                        "
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink"
                        aria-label="Następny tydzień"
                        preserve-scroll
                    >
                        <AppIcon name="chevronLeft" class="rotate-180" />
                    </Link>
                </div>

                <ul class="mt-1 flex">
                    <li
                        v-for="day in props.days"
                        :key="day.date"
                        class="min-w-0 flex-1"
                    >
                        <button
                            type="button"
                            class="flex h-14 w-full flex-col items-center justify-center gap-0.5 rounded-xl text-xs transition-colors"
                            :class="[
                                day.isToday
                                    ? 'font-semibold text-accent-strong'
                                    : 'text-ink-muted',
                                chosenDays.includes(day.date)
                                    ? 'bg-accent-soft'
                                    : 'hover:bg-paper-sunk',
                            ]"
                            :aria-label="`Przejdź do: ${day.weekday} ${day.dayOfMonth}`"
                            @click="jumpTo(day.date)"
                        >
                            <span>{{ day.weekday }}</span>
                            <span
                                class="text-base tabular-nums"
                                :class="day.isToday ? '' : 'text-ink'"
                            >
                                {{ day.dayOfMonth }}
                            </span>
                            <!-- A dot is enough: a title never fits in a seventh. -->
                            <span
                                class="h-1 w-1 rounded-full"
                                :class="
                                    mealCount(day) > 0
                                        ? 'bg-accent-strong'
                                        : 'bg-transparent'
                                "
                            ></span>
                        </button>
                    </li>
                </ul>

                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <!-- Only worth a button once you have wandered off it. -->
                    <Link
                        v-if="props.weekStart !== props.thisWeek"
                        :href="mealPlan.url()"
                        class="flex h-11 items-center rounded-full border border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                        preserve-scroll
                    >
                        Ten tydzień
                    </Link>

                    <button
                        type="button"
                        :disabled="generating"
                        class="flex h-11 items-center gap-1.5 rounded-full border border-accent-strong px-4 text-sm font-medium text-accent-strong disabled:opacity-60"
                        @click="openGenerator"
                    >
                        <AppIcon name="calendar" />
                        {{ generateLabel }}
                    </button>

                    <button
                        v-if="plannedDays.length > 0"
                        type="button"
                        class="ml-auto flex h-11 items-center rounded-full border border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                        @click="toggleWholeWeek"
                    >
                        {{ allChosen ? 'Odznacz dni' : 'Zaznacz cały tydzień' }}
                    </button>
                </div>
            </div>
        </AppHeader>

        <!--
          What the generator did. It says the skipped slots out loud, or filling
          an already-planned week reads as a button that did nothing.
        -->
        <p
            v-if="generated !== null"
            class="mx-auto max-w-6xl px-4 pt-4 text-sm text-accent-strong sm:px-8"
        >
            {{ generatedMessage }}
        </p>

        <!--
          The bottom padding clears the phone's nav row, plus the action bar
          once it is there: without it the last day's controls sit under them.
        -->
        <main
            class="mx-auto max-w-6xl px-4 py-6 sm:px-8"
            :class="chosenDays.length > 0 ? 'pb-44 sm:pb-28' : 'pb-24 sm:pb-8'"
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <section
                    v-for="day in props.days"
                    :id="`day-${day.date}`"
                    :key="day.date"
                    class="scroll-mt-56 rounded-2xl border bg-paper-raised"
                    :class="
                        chosenDays.includes(day.date)
                            ? 'border-accent'
                            : 'border-rule'
                    "
                >
                    <!--
                      The whole day header ticks the day, because the tick is the
                      gesture the shopping button is built on and a 20px box is
                      not what a thumb aims at.
                    -->
                    <button
                        type="button"
                        class="flex min-h-13 w-full items-center gap-3 px-4 py-3 text-left"
                        :aria-pressed="chosenDays.includes(day.date)"
                        @click="toggleDay(day.date)"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border"
                            :class="
                                chosenDays.includes(day.date)
                                    ? 'border-accent-strong bg-accent-strong text-paper'
                                    : 'border-rule-strong'
                            "
                        >
                            <AppIcon
                                v-if="chosenDays.includes(day.date)"
                                name="check"
                            />
                        </span>

                        <span class="flex-1">
                            <span
                                class="font-semibold"
                                :class="
                                    day.isToday
                                        ? 'text-accent-strong'
                                        : 'text-ink'
                                "
                            >
                                {{ day.weekday }} {{ day.dayOfMonth }}
                            </span>
                            <span
                                v-if="day.isToday"
                                class="ml-2 text-xs text-accent-strong"
                            >
                                dziś
                            </span>
                        </span>
                    </button>

                    <div class="border-t border-rule px-4 py-2">
                        <div
                            v-for="slot in day.slots"
                            :key="slot.value"
                            class="border-b border-rule py-2 last:border-b-0"
                        >
                            <p class="label-caps text-ink-faint">
                                {{ slot.label }}
                            </p>

                            <ul v-if="slot.entries.length > 0" class="mt-1">
                                <li
                                    v-for="entry in slot.entries"
                                    :key="entry.id"
                                    class="flex items-center gap-2 py-1"
                                >
                                    <img
                                        v-if="entry.imageUrl"
                                        :src="entry.imageUrl"
                                        alt=""
                                        loading="lazy"
                                        class="h-10 w-10 shrink-0 rounded-lg object-cover"
                                    />

                                    <span class="min-w-0 flex-1">
                                        <Link
                                            v-if="entry.slug"
                                            :href="show.url(entry.slug)"
                                            class="block truncate text-sm text-ink hover:text-accent-strong"
                                        >
                                            {{ entry.title }}
                                        </Link>
                                        <span
                                            v-else
                                            class="block truncate text-sm text-ink-muted italic"
                                        >
                                            {{ entry.title }}
                                        </span>

                                        <span
                                            class="flex flex-wrap items-center gap-x-2 text-xs text-ink-muted"
                                        >
                                            <!--
                                              Silent past two, where a number
                                              stops being a nudge and becomes a
                                              shopping list of its own.
                                            -->
                                            <span
                                                v-if="entry.missing === 0"
                                                class="text-accent-strong"
                                            >
                                                masz wszystko
                                            </span>
                                            <span
                                                v-else-if="
                                                    entry.missing !== null &&
                                                    entry.missing <= 2
                                                "
                                            >
                                                brakuje {{ entry.missing }}
                                            </span>

                                            <span
                                                v-if="entry.recipeServings"
                                                class="tabular-nums"
                                            >
                                                przepis na
                                                {{ entry.recipeServings }}
                                            </span>
                                        </span>
                                    </span>

                                    <!-- Portions: what the shopping scales by. -->
                                    <span
                                        v-if="!entry.slug"
                                        class="w-24 shrink-0"
                                    ></span>
                                    <span
                                        v-else
                                        class="flex shrink-0 items-center"
                                    >
                                        <button
                                            type="button"
                                            class="flex h-11 w-8 items-center justify-center rounded-full text-ink-muted hover:text-ink disabled:opacity-40"
                                            :disabled="
                                                shownServings(entry) <= 1
                                            "
                                            :aria-label="`Mniej porcji: ${entry.title}`"
                                            @click="
                                                setServings(
                                                    entry,
                                                    shownServings(entry) - 1,
                                                )
                                            "
                                        >
                                            <AppIcon name="minus" />
                                        </button>

                                        <span
                                            class="w-10 text-center text-sm text-ink tabular-nums"
                                            :title="`${shownServings(entry)} porcji`"
                                        >
                                            {{ shownServings(entry) }}×
                                        </span>

                                        <button
                                            type="button"
                                            class="flex h-11 w-8 items-center justify-center rounded-full text-ink-muted hover:text-ink"
                                            :aria-label="`Więcej porcji: ${entry.title}`"
                                            @click="
                                                setServings(
                                                    entry,
                                                    shownServings(entry) + 1,
                                                )
                                            "
                                        >
                                            <AppIcon name="plus" />
                                        </button>
                                    </span>

                                    <!-- A note has no other dish to become. -->
                                    <button
                                        v-if="entry.slug"
                                        type="button"
                                        :disabled="swapping === entry.id"
                                        class="flex h-11 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:text-accent-strong disabled:opacity-40"
                                        :aria-label="`Inne danie zamiast: ${entry.title}`"
                                        title="Inne danie"
                                        @click="swapDish(entry)"
                                    >
                                        <AppIcon name="shuffle" />
                                    </button>

                                    <button
                                        type="button"
                                        class="flex h-11 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:text-flag"
                                        :aria-label="`Usuń z planu: ${entry.title}`"
                                        @click="remove(entry)"
                                    >
                                        <AppIcon name="cross" />
                                    </button>
                                </li>
                            </ul>

                            <button
                                type="button"
                                class="mt-1 flex h-11 items-center gap-1.5 text-sm text-ink-muted hover:text-accent-strong"
                                @click="openSheet(day.date, slot.value)"
                            >
                                <AppIcon name="plus" />
                                {{
                                    slot.entries.length > 0
                                        ? 'Dodaj jeszcze'
                                        : 'Zaplanuj'
                                }}
                            </button>
                        </div>

                        <!--
                          The two meals that are not everyday earn their row by
                          being asked for, rather than showing seven days of
                          empty ones.
                        -->
                        <div
                            v-if="spareSlots(day).length > 0"
                            class="flex flex-wrap gap-2 pt-2 pb-1"
                        >
                            <button
                                v-for="slot in spareSlots(day)"
                                :key="slot.value"
                                type="button"
                                class="flex h-11 items-center gap-1 rounded-full border border-rule px-3 text-xs text-ink-faint hover:border-rule-strong hover:text-ink"
                                @click="openSheet(day.date, slot.value)"
                            >
                                <AppIcon name="plus" />
                                {{ slot.label }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <p
                v-if="plannedCount === 0"
                class="mt-6 rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
            >
                Pusty tydzień. Zaplanuj posiłek, zaznacz dni i zrób z nich jedną
                listę zakupów.
            </p>
        </main>

        <!--
          The action bar appears with the first tick, because until then there
          is nothing it could do. It sits above the phone's own nav row.
        -->
        <div
            v-if="chosenDays.length > 0"
            class="fixed inset-x-0 bottom-14 z-30 border-t border-rule bg-paper/95 px-4 py-3 pb-safe backdrop-blur sm:bottom-0 sm:px-8"
        >
            <div class="mx-auto flex max-w-6xl items-center gap-3">
                <p class="min-w-0 flex-1 text-sm text-ink-muted">
                    <template v-if="confirmation === null">
                        Zaznaczono
                        <span class="font-medium text-ink tabular-nums">
                            {{ chosenDays.length }}
                        </span>
                        {{ chosenDays.length === 1 ? 'dzień' : 'dni' }}
                    </template>

                    <template v-else>
                        <!--
                          The list is new and lives on another screen, so its
                          name is the useful half of the answer and it is a way
                          through rather than a fact to remember.
                        -->
                        <Link
                            :href="shoppingList.url(confirmation.listId)"
                            class="font-medium text-accent-strong underline decoration-accent-soft underline-offset-2"
                        >
                            {{
                                confirmation.added === 0
                                    ? `Masz już wszystko — lista „${confirmation.list}” jest pusta`
                                    : `Lista „${confirmation.list}”: ${confirmation.added} do kupienia`
                            }}
                        </Link>
                        <span
                            v-if="confirmation.unknown > 0"
                            class="block text-xs"
                        >
                            {{ confirmation.unknown }}
                            {{
                                confirmation.unknown === 1
                                    ? 'składnika nie rozpoznałem'
                                    : 'składników nie rozpoznałem'
                            }}
                            — sprawdź je w przepisie.
                        </span>
                        <!--
                          Says that ticked-off lines were left alone, or
                          re-running the button looks like it lost them.
                        -->
                        <span
                            v-if="confirmation.kept > 0"
                            class="block text-xs"
                        >
                            {{ confirmation.kept }} odhaczonych zostawiłem bez
                            zmian.
                        </span>
                        <span
                            v-if="confirmation.wholeBatches.length > 0"
                            class="block text-xs"
                        >
                            Kupuję na całe gotowanie:
                            {{ confirmation.wholeBatches.join(', ') }} —
                            zaplanowaliście mniej porcji, niż przepis robi, a
                            pół przepisu to pół jajka.
                        </span>
                        <span
                            v-if="confirmation.unscaled.length > 0"
                            class="block text-xs"
                        >
                            Bez przeliczenia porcji:
                            {{ confirmation.unscaled.join(', ') }} — przepis nie
                            podaje, na ile osób jest.
                        </span>
                    </template>
                </p>

                <button
                    type="button"
                    :disabled="shopping"
                    class="flex h-13 shrink-0 items-center gap-2 rounded-full bg-accent px-5 text-sm font-semibold text-ink disabled:opacity-60"
                    @click="addToShoppingList"
                >
                    <AppIcon name="cart" />
                    {{ shopping ? 'Dopisuję…' : 'Dopisz braki do zakupów' }}
                </button>
            </div>
        </div>

        <AppNav current="plan" />

        <!--
          What to fill in: one row per day, one column per meal. A grid rather
          than "these days" plus "these meals", because a week is not uniform —
          a podwieczorek on Saturday and nothing on Tuesday is a normal way to
          eat, and two separate lists cannot say that.
        -->
        <div
            v-if="generatorOpen"
            class="fixed inset-0 z-40 flex flex-col bg-paper"
            role="dialog"
            aria-modal="true"
            aria-label="Wygeneruj plan"
            @keydown.esc="generatorOpen = false"
        >
            <div class="border-b border-rule px-4 pt-safe sm:px-8">
                <div
                    class="mx-auto flex max-w-3xl items-center justify-between gap-3 py-3"
                >
                    <h2 class="text-lg font-semibold text-ink">
                        Co wygenerować?
                    </h2>
                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk"
                        aria-label="Zamknij"
                        @click="generatorOpen = false"
                    >
                        <AppIcon name="clear" />
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 sm:px-8">
                <div class="mx-auto max-w-3xl py-4">
                    <p class="mb-3 text-sm text-ink-muted">
                        Zaznacz posiłki, które mam ułożyć. Zaplanowanych nie
                        ruszam.
                    </p>

                    <table class="w-full table-fixed border-collapse">
                        <thead>
                            <tr>
                                <th class="w-20 sm:w-28"></th>
                                <!-- The heading ticks its whole column. -->
                                <th
                                    v-for="slot in props.slots"
                                    :key="slot.value"
                                    class="p-0 align-bottom"
                                >
                                    <button
                                        type="button"
                                        class="h-14 w-full px-0.5 text-[11px] leading-tight text-ink-muted hover:text-ink"
                                        @click="toggleWantedColumn(slot.value)"
                                    >
                                        {{ slot.label }}
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in props.days"
                                :key="day.date"
                                class="border-t border-rule"
                            >
                                <!-- And the row label ticks the whole day. -->
                                <th class="p-0 text-left">
                                    <button
                                        type="button"
                                        class="flex h-12 w-full items-center gap-1 text-sm"
                                        :class="
                                            day.isToday
                                                ? 'font-semibold text-accent-strong'
                                                : 'text-ink'
                                        "
                                        @click="toggleWantedRow(day.date)"
                                    >
                                        {{ day.weekday }}
                                        <span class="tabular-nums">
                                            {{ day.dayOfMonth }}
                                        </span>
                                    </button>
                                </th>

                                <td
                                    v-for="slot in props.slots"
                                    :key="slot.value"
                                    class="p-0 text-center"
                                >
                                    <!--
                                      A meal already planned cannot be
                                      generated over, so it reads as taken
                                      rather than as an empty box that does
                                      nothing when tapped.
                                    -->
                                    <span
                                        v-if="isPlanned(day.date, slot.value)"
                                        class="flex h-12 items-center justify-center text-ink-faint"
                                        :title="`${slot.label}: już zaplanowane`"
                                    >
                                        <AppIcon name="check" />
                                    </span>

                                    <button
                                        v-else
                                        type="button"
                                        class="flex h-12 w-full items-center justify-center"
                                        :aria-label="`${slot.label}, ${day.weekday} ${day.dayOfMonth}`"
                                        :aria-pressed="
                                            wants(day.date, slot.value)
                                        "
                                        @click="
                                            toggleWanted(day.date, slot.value)
                                        "
                                    >
                                        <span
                                            class="flex h-6 w-6 items-center justify-center rounded-md border"
                                            :class="
                                                wants(day.date, slot.value)
                                                    ? 'border-accent-strong bg-accent-strong text-paper'
                                                    : 'border-rule-strong'
                                            "
                                        >
                                            <AppIcon
                                                v-if="
                                                    wants(day.date, slot.value)
                                                "
                                                name="check"
                                            />
                                        </span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="mt-6 w-40">
                        <label
                            for="generate-servings"
                            class="mb-1.5 block text-sm text-ink-muted"
                        >
                            Ile porcji
                        </label>
                        <input
                            id="generate-servings"
                            v-model.number="generatorServings"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="99"
                            class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums focus:border-accent focus:outline-none"
                        />
                    </div>
                </div>
            </div>

            <div class="border-t border-rule px-4 py-3 pb-safe sm:px-8">
                <div class="mx-auto flex max-w-3xl gap-2">
                    <button
                        type="button"
                        :disabled="generating || wantedCount === 0"
                        class="h-13 flex-1 rounded-full bg-accent font-semibold text-ink disabled:opacity-60"
                        @click="generate"
                    >
                        {{
                            generating
                                ? 'Układam…'
                                : `Wygeneruj ${wantedCount} posiłków`
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Planning one meal: a recipe from the catalogue, or your own words. -->
        <div
            v-if="sheet !== null"
            class="fixed inset-0 z-40 flex flex-col bg-paper"
            role="dialog"
            aria-modal="true"
            :aria-label="`Zaplanuj: ${slotLabel}`"
            @keydown.esc="closeSheet"
        >
            <div class="border-b border-rule px-4 pt-safe sm:px-8">
                <div
                    class="mx-auto flex max-w-3xl items-center justify-between gap-3 py-3"
                >
                    <h2 class="text-lg font-semibold text-ink">
                        {{ slotLabel }}
                    </h2>
                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk"
                        aria-label="Zamknij"
                        @click="closeSheet"
                    >
                        <AppIcon name="clear" />
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 pb-safe sm:px-8">
                <div class="mx-auto max-w-3xl py-4">
                    <label for="plan-search" class="sr-only">
                        Szukaj przepisu
                    </label>
                    <input
                        id="plan-search"
                        ref="searchField"
                        v-model="search"
                        type="search"
                        autocomplete="off"
                        :placeholder="`Szukaj: ${slotLabel.toLowerCase()}…`"
                        class="h-13 w-full rounded-xl border border-rule-strong px-4 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                    />

                    <template v-if="chosen === null && !writingNote">
                        <ul
                            v-if="props.matches.length > 0"
                            class="mt-3 space-y-1"
                        >
                            <li
                                v-for="recipe in props.matches"
                                :key="recipe.id"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl p-2 text-left hover:bg-paper-sunk"
                                    @click="chooseRecipe(recipe)"
                                >
                                    <img
                                        v-if="recipe.imageUrl"
                                        :src="recipe.imageUrl"
                                        alt=""
                                        loading="lazy"
                                        class="h-12 w-12 shrink-0 rounded-lg object-cover"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate text-ink">{{
                                            recipe.title
                                        }}</span>
                                        <span
                                            class="block text-xs text-ink-muted"
                                        >
                                            {{
                                                recipe.servingsLabel ??
                                                'porcje nieznane'
                                            }}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <p
                            v-else-if="search.trim().length >= 2"
                            class="mt-4 text-sm text-ink-muted"
                        >
                            {{
                                wholeCatalogue
                                    ? 'Nic takiego nie mam w katalogu.'
                                    : `Nic takiego wśród przepisów na ${slotLabel.toLowerCase()}.`
                            }}
                        </p>

                        <p v-else class="mt-4 text-sm text-ink-muted">
                            Wpisz dwie litery, a podpowiem resztę.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="flex h-12 items-center gap-2 rounded-full border border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                                @click="startNote"
                            >
                                <AppIcon name="pencil" />
                                Wpisz własnymi słowami
                            </button>

                            <!--
                              Shown only once the narrow search has run: before
                              anything is typed there is nothing to widen.
                            -->
                            <button
                                v-if="
                                    !wholeCatalogue && search.trim().length >= 2
                                "
                                type="button"
                                class="flex h-12 items-center gap-2 rounded-full border border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                                @click="searchEverywhere"
                            >
                                <AppIcon name="search" />
                                Szukaj w całym katalogu
                            </button>
                        </div>
                    </template>

                    <form
                        v-else
                        class="mt-4 space-y-5"
                        @submit.prevent="submit"
                    >
                        <template v-if="chosen !== null">
                            <p class="text-lg font-semibold text-ink">
                                {{ chosen.title }}
                            </p>

                            <div class="w-40">
                                <label
                                    for="plan-servings"
                                    class="mb-1.5 block text-sm text-ink-muted"
                                >
                                    Ile porcji
                                </label>
                                <input
                                    id="plan-servings"
                                    v-model.number="form.servings"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    max="99"
                                    class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums focus:border-accent focus:outline-none"
                                />
                            </div>

                            <p class="text-xs text-ink-muted">
                                <template v-if="chosen.servings">
                                    Przepis jest na
                                    {{ chosen.servings }} porcje. Ten sam
                                    przepis wstawiony w kilka dni kupujemy raz —
                                    porcje się sumują.
                                </template>
                                <template v-else>
                                    Przepis nie podaje, na ile porcji jest, więc
                                    zakupy policzę z ilości tak, jak są
                                    zapisane.
                                </template>
                            </p>
                        </template>

                        <template v-else>
                            <label
                                for="plan-note"
                                class="block text-sm text-ink-muted"
                            >
                                Co jecie?
                            </label>
                            <input
                                id="plan-note"
                                v-model="form.note"
                                type="text"
                                maxlength="255"
                                placeholder="np. kanapki, obiad u rodziców"
                                class="h-13 w-full rounded-xl border border-rule-strong px-4 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                            />
                            <p class="text-xs text-ink-muted">
                                Notatka nie trafia na listę zakupów — nie ma z
                                czego jej policzyć.
                            </p>
                        </template>

                        <p
                            v-if="form.errors.recipe_id"
                            class="text-sm text-flag"
                        >
                            {{ form.errors.recipe_id }}
                        </p>

                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="h-13 flex-1 rounded-full bg-accent font-semibold text-ink disabled:opacity-60"
                            >
                                {{
                                    form.processing
                                        ? 'Zapisuję…'
                                        : 'Dodaj do planu'
                                }}
                            </button>
                            <button
                                type="button"
                                class="h-13 rounded-full border border-rule-strong px-5 text-sm text-ink-muted"
                                @click="
                                    chosen = null;
                                    writingNote = false;
                                "
                            >
                                Wstecz
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
