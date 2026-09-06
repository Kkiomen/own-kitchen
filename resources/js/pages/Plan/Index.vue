<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import { formatMoney } from '@/lib/money';
import {
    index as mealPlan,
    alternatives as alternativesRoute,
    destroy,
    generate as generateRoute,
    replace,
    shop,
    store,
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
    /**
     * What one person gets from this meal — the household is two, the same
     * assumption the week panel makes. Null when the dish cannot be counted,
     * because a zero would read as a light meal.
     */
    nutrition: {
        kcal: number;
        protein?: number;
        fat?: number;
        carbs?: number;
    } | null;
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
    /** The day added up for one person, and the dishes it could not read. */
    nutrition: {
        kcal: number;
        protein: number;
        fat: number;
        carbs: number;
        uncounted: number;
    } | null;
}

/**
 * A dish offered in place of a planned meal.
 *
 * `servings` is part of the offer rather than a detail: every candidate is a
 * different size, so the portions that reach the same meal differ from dish to
 * dish. Showing the dishes without it would leave the person picking to guess,
 * and guessing wrong is how a day quietly gains a thousand calories.
 */
interface MealAlternative {
    recipeId: number;
    slug: string;
    title: string;
    imageUrl: string | null;
    servings: number;
    /** Per portion, the unit every other screen speaks in. */
    kcalPerPortion: number | null;
    proteinPerPortion: number | null;
    /** The whole meal, shown beside the portions so the two cannot be confused. */
    kcal: number | null;
    cost: number | null;
    missing: number | null;
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
    /**
     * The week as it stands, recomputed server-side on every visit.
     *
     * Null when nothing is planned. It carries no target comparison — the
     * screen cannot know what was asked for once the generator's message is
     * gone, so `calorieGap` and `fitsBudget` are null here and only the
     * generate response fills them in.
     */
    week: WeekReport | null;
    /** Only present while the alternatives sheet is open. */
    alternatives?: MealAlternative[];
    /** The meal being replaced, in the same units as the offers. */
    replacing?: { servings: number; kcal: number | null };
    /** What the targets form opens with; the shares come from `PlanTargets`. */
    defaultTargets: {
        people: number;
        kcal: number;
        shares: Record<string, number>;
    };
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
    /** Meals nothing cheap enough was left for, judged on the whole cost. */
    overspent: number;
    /** Only when targets were set: what the week that was written adds up to. */
    week?: WeekReport;
}

/**
 * The week measured after it was written, not what the generator aimed at.
 *
 * Both money figures are here on purpose. `eats` is everything the week calls
 * for and is the number to compare against another week; `buys` is what is left
 * after the kitchen, and is the one a budget is set against. A full cupboard
 * makes them very different and neither of them wrong.
 */
interface WeekReport {
    kcalPerPerson: number | null;
    proteinPerPerson: number | null;
    kcalByDate: Record<string, number>;
    calorieGap: number | null;
    eats: number;
    buys: number;
    unpricedProducts: number;
    products: number;
    confidence: number;
    fitsBudget: boolean | null;
    uncounted: number;
}

const generating = ref(false);
const generated = ref<GenerateResult | null>(null);

/**
 * The measured week, kept after the confirmation message has expired.
 *
 * The message says what the button did and stops being interesting; this says
 * what the week is, and somebody reading it is doing so on purpose.
 */
/*
 * Swapping by hand. The shuffle button used to hand back one dish at random,
 * which is fine when anything will do and useless when somebody has an opinion
 * about Tuesday — so it opens this instead, and the roll of the dice is one of
 * the rows in it.
 */
const swapSheet = ref<PlanEntry | null>(null);
const loadingAlternatives = ref(false);
const choosing = ref<number | null>(null);

const alternatives = computed<MealAlternative[]>(
    () => props.alternatives ?? [],
);

function openSwap(entry: PlanEntry): void {
    swapSheet.value = entry;
    loadingAlternatives.value = true;

    /*
     * A partial visit to the alternatives route, not a reload of this one: the
     * plan page has no `alternatives` prop to hand back, so reloading it left
     * the sheet permanently empty. `preserveUrl` keeps the week in the address
     * bar, because what is being chosen inside a sheet is not a page worth
     * coming back to.
     */
    router.get(
        alternativesRoute.url(entry.id),
        { week: props.weekStart },
        {
            // Both, or the sheet has offers with nothing to compare them to.
            only: ['alternatives', 'replacing'],
            preserveUrl: true,
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                loadingAlternatives.value = false;
            },
        },
    );
}

function closeSwap(): void {
    swapSheet.value = null;
}

function choose(alternative: MealAlternative): void {
    const entry = swapSheet.value;

    if (entry === null || choosing.value !== null) {
        return;
    }

    choosing.value = alternative.recipeId;

    router.post(
        replace.url(entry.id),
        { recipe_id: alternative.recipeId },
        {
            preserveScroll: true,
            onSuccess: closeSwap,
            onFinish: () => {
                choosing.value = null;
            },
        },
    );
}

const generatedReport = ref<WeekReport | null>(null);

/**
 * The generated week's report while it is fresh, otherwise whatever the server
 * says the week is now.
 *
 * The generated one is preferred only because it carries the comparison against
 * the target that was asked for. The moment anything on the plan changes, the
 * server's version is the true one — which is why swapping a dish or changing
 * portions clears the generated copy rather than leaving it to go stale.
 */
const report = computed<WeekReport | null>(
    () => generatedReport.value ?? props.week,
);

/*
 * Anything that changes the plan overtakes the generated report: swapping a
 * dish, changing portions, removing a meal. Rather than remembering to clear it
 * in four handlers — and forgetting in the fifth somebody adds later — it is
 * dropped whenever the server sends a different set of days. `props.week` is
 * recomputed on the same response, so the panel keeps a figure throughout; it
 * just stops being the one that knew about the target.
 */
watch(
    () => props.days,
    () => {
        if (!generating.value) {
            generatedReport.value = null;
        }
    },
);
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

/** "+3%" / "-4%", so the number is read as near or far rather than just read. */
const gapLabel = computed<string>(() => {
    const gap = report.value?.calorieGap;

    if (gap === null || gap === undefined) {
        return '';
    }

    const percent = Math.round(gap * 100);

    return `${percent > 0 ? '+' : ''}${percent}%`;
});

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

/*
 * Targets are opt-in. Off, this is the "fill my week" button the screen has
 * always had; on, the generator picks dishes and helpings to reach a number of
 * calories and stays inside a budget. Defaulted from the server so the shares
 * cannot drift from the class that validates them.
 */
const useTargets = ref(false);
const people = ref(props.defaultTargets.people);
const kcal = ref(props.defaultTargets.kcal);
const budget = ref<number | null>(null);
const shares = ref<Record<string, number>>({ ...props.defaultTargets.shares });

/** The meals the targets cover: whatever the grid above is asking for. */
const targetedSlots = computed<string[]>(() => {
    const asked = new Set<string>();

    Object.values(wanted.value).forEach((slots) =>
        slots.forEach((slot) => asked.add(slot)),
    );

    return props.slots
        .map((slot) => slot.value)
        .filter((slot) => asked.has(slot));
});

const shareTotal = computed<number>(() =>
    targetedSlots.value.reduce(
        (sum, slot) => sum + (shares.value[slot] ?? 0),
        0,
    ),
);

/**
 * A day has to add up to a day. Said here as well as on the server because the
 * server refuses rather than normalising — silently scaling 30/45/20 to a whole
 * would hand back a week hitting a target nobody set.
 */
const sharesAddUp = computed<boolean>(() => shareTotal.value === 100);

/** One person's share of one meal, so the numbers mean something as you type. */
function kcalForSlot(slot: string): number {
    return Math.round((kcal.value * (shares.value[slot] ?? 0)) / 100);
}

/**
 * A meal the grid asks for but the shares have no figure for yet — the two
 * meals that are not everyday, added after the form opened.
 */
watch(targetedSlots, (slots) => {
    slots.forEach((slot) => {
        if (shares.value[slot] === undefined) {
            shares.value[slot] = 0;
        }
    });
});

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
    shares.value = { ...props.defaultTargets.shares };
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
    if (
        generating.value ||
        wantedCount.value === 0 ||
        (useTargets.value && !sharesAddUp.value)
    ) {
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
            targets: useTargets.value
                ? {
                      people: people.value,
                      kcal: kcal.value,
                      budget: budget.value,
                      shares: Object.fromEntries(
                          targetedSlots.value.map((slot) => [
                              slot,
                              shares.value[slot] ?? 0,
                          ]),
                      ),
                  }
                : null,
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
                generatedReport.value = result.week ?? null;
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
            What the week actually came to. Kept out of the message above and
            given room of its own, because it is the answer to the question the
            targets asked and it does not expire with the confirmation.
        -->
        <section
            v-if="report !== null"
            class="mx-auto mt-3 max-w-6xl px-4 sm:px-8"
        >
            <div class="rounded-2xl border border-rule bg-paper-raised p-4">
                <p class="mb-3 label-caps text-ink-muted">Ten tydzień</p>

                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-sm text-ink-muted">
                            kcal / osobę / dzień
                        </dt>
                        <dd
                            class="mt-0.5 text-xl font-semibold text-ink tabular-nums"
                        >
                            {{
                                report.kcalPerPerson === null
                                    ? '—'
                                    : Math.round(report.kcalPerPerson)
                            }}
                            <span
                                v-if="report.calorieGap !== null"
                                class="text-sm font-normal text-ink-muted"
                            >
                                ({{ gapLabel }})
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">białka / osobę</dt>
                        <dd
                            class="mt-0.5 text-xl font-semibold text-ink tabular-nums"
                        >
                            {{
                                report.proteinPerPerson === null
                                    ? '—'
                                    : `${Math.round(report.proteinPerPerson)} g`
                            }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">zjada za</dt>
                        <dd
                            class="mt-0.5 text-xl font-semibold text-ink tabular-nums"
                        >
                            {{ formatMoney(report.eats) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">
                            kupujesz za
                            <span class="text-ink-faint"
                                >(bez tego, co masz)</span
                            >
                        </dt>
                        <dd
                            class="mt-0.5 text-xl font-semibold tabular-nums"
                            :class="
                                report.fitsBudget === false
                                    ? 'text-flag'
                                    : 'text-ink'
                            "
                        >
                            {{ formatMoney(report.buys) }}
                        </dd>
                    </div>
                </dl>

                <!--
                    The honest small print. A third of what a week calls for has
                    no price behind it today, and a total that did not say so
                    would simply be too small — which is the direction that
                    costs money and the one nobody notices.
                -->
                <p
                    v-if="report.unpricedProducts > 0"
                    class="mt-3 text-sm text-ink-muted"
                >
                    Nie znam ceny {{ report.unpricedProducts }} z
                    {{ report.products }} produktów — rachunek będzie wyższy.
                </p>

                <p
                    v-if="report.uncounted > 0"
                    class="mt-1 text-sm text-ink-muted"
                >
                    {{ report.uncounted }}
                    {{ report.uncounted === 1 ? 'dania' : 'dań' }} nie umiem
                    policzyć kalorycznie — te kcal to dolna granica.
                </p>

                <p
                    v-if="generated !== null && generated.overspent > 0"
                    class="mt-1 text-sm text-flag"
                >
                    Przy {{ generated.overspent }}
                    {{ generated.overspent === 1 ? 'posiłku' : 'posiłkach' }}
                    zabrakło już tańszych propozycji — budżet był ciasny.
                </p>
            </div>
        </section>

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

                                            <!--
                                              One person's share of this meal.
                                              Per person because that is the
                                              unit the target and the week panel
                                              speak in; a meal figure under a
                                              2 500 daily goal reads as a fault.
                                            -->
                                            <span
                                                v-if="entry.nutrition"
                                                class="tabular-nums"
                                            >
                                                {{ entry.nutrition.kcal }} kcal
                                                <template
                                                    v-if="
                                                        entry.nutrition
                                                            .protein !==
                                                        undefined
                                                    "
                                                >
                                                    · B
                                                    {{
                                                        entry.nutrition.protein
                                                    }}
                                                </template>
                                                <template
                                                    v-if="
                                                        entry.nutrition.fat !==
                                                        undefined
                                                    "
                                                >
                                                    · T
                                                    {{ entry.nutrition.fat }}
                                                </template>
                                                <template
                                                    v-if="
                                                        entry.nutrition
                                                            .carbs !== undefined
                                                    "
                                                >
                                                    · W
                                                    {{ entry.nutrition.carbs }}
                                                </template>
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
                                        :disabled="
                                            loadingAlternatives &&
                                            swapSheet?.id === entry.id
                                        "
                                        class="flex h-11 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:text-accent-strong disabled:opacity-40"
                                        :aria-label="`Inne danie zamiast: ${entry.title}`"
                                        title="Inne danie"
                                        @click="openSwap(entry)"
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

                        <!--
                          The day added up, for one person. It sits at the foot
                          of the card rather than the head because it is the
                          answer to the meals above it, and it is per person for
                          the same reason every other figure in this feature is:
                          the target somebody set was "2 500 na osobę".
                        -->
                        <div
                            v-if="day.nutrition"
                            class="mt-1 flex flex-wrap items-baseline gap-x-3 border-t border-rule pt-3 text-sm tabular-nums"
                        >
                            <span class="label-caps text-ink-muted">
                                Razem / osobę
                            </span>
                            <span class="font-semibold text-ink">
                                {{ day.nutrition.kcal }} kcal
                            </span>
                            <span class="text-ink-muted">
                                B {{ day.nutrition.protein }} g · T
                                {{ day.nutrition.fat }} g · W
                                {{ day.nutrition.carbs }} g
                            </span>
                            <!--
                              A day missing an unreadable dinner is not a light
                              day, and a bare total cannot tell the difference.
                            -->
                            <span
                                v-if="day.nutrition.uncounted > 0"
                                class="text-flag"
                            >
                                bez
                                {{ day.nutrition.uncounted }}
                                {{
                                    day.nutrition.uncounted === 1
                                        ? 'dania'
                                        : 'dań'
                                }}
                            </span>
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

                    <div v-if="!useTargets" class="mt-6 w-40">
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

                    <!--
                        Calories and money. Off by default: most weeks are
                        planned by eye, and a form that always asked for a
                        calorie target would make the plain button worse to
                        reach the one people press less often.
                    -->
                    <div class="mt-6 border-t border-rule pt-6">
                        <button
                            type="button"
                            class="flex h-13 w-full items-center justify-between text-left"
                            @click="useTargets = !useTargets"
                        >
                            <span>
                                <span class="font-semibold text-ink">
                                    Kalorie i budżet
                                </span>
                                <span
                                    class="mt-0.5 block text-sm text-ink-muted"
                                >
                                    Dobierz dania i porcje pod cel
                                </span>
                            </span>
                            <span
                                class="flex h-7 w-12 shrink-0 items-center rounded-full px-0.5 transition-colors"
                                :class="
                                    useTargets ? 'bg-accent' : 'bg-paper-sunk'
                                "
                            >
                                <span
                                    class="h-6 w-6 rounded-full bg-paper shadow-sm transition-transform"
                                    :class="useTargets ? 'translate-x-5' : ''"
                                />
                            </span>
                        </button>

                        <div v-if="useTargets" class="mt-4 space-y-5">
                            <div class="flex flex-wrap gap-3">
                                <div class="w-28">
                                    <label
                                        for="target-people"
                                        class="mb-1.5 block text-sm text-ink-muted"
                                    >
                                        Osób
                                    </label>
                                    <input
                                        id="target-people"
                                        v-model.number="people"
                                        type="number"
                                        inputmode="numeric"
                                        min="1"
                                        max="12"
                                        class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums focus:border-accent focus:outline-none"
                                    />
                                </div>

                                <div class="w-36">
                                    <label
                                        for="target-kcal"
                                        class="mb-1.5 block text-sm text-ink-muted"
                                    >
                                        kcal / osobę
                                    </label>
                                    <input
                                        id="target-kcal"
                                        v-model.number="kcal"
                                        type="number"
                                        inputmode="numeric"
                                        min="800"
                                        max="6000"
                                        step="50"
                                        class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums focus:border-accent focus:outline-none"
                                    />
                                </div>

                                <div class="w-36">
                                    <label
                                        for="target-budget"
                                        class="mb-1.5 block text-sm text-ink-muted"
                                    >
                                        Budżet (zł)
                                    </label>
                                    <input
                                        id="target-budget"
                                        v-model.number="budget"
                                        type="number"
                                        inputmode="decimal"
                                        min="1"
                                        step="10"
                                        placeholder="bez limitu"
                                        class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums placeholder:text-ink-faint focus:border-accent focus:outline-none"
                                    />
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 label-caps text-ink-muted">
                                    Podział dnia
                                </p>

                                <div class="space-y-2">
                                    <div
                                        v-for="slot in targetedSlots"
                                        :key="slot"
                                        class="flex items-center gap-3"
                                    >
                                        <span class="w-36 shrink-0 text-ink">
                                            {{
                                                props.slots.find(
                                                    (one) => one.value === slot,
                                                )?.label ?? slot
                                            }}
                                        </span>
                                        <input
                                            v-model.number="shares[slot]"
                                            type="number"
                                            inputmode="numeric"
                                            min="0"
                                            max="100"
                                            step="5"
                                            :aria-label="`Udział posiłku ${slot} w kaloriach dnia, w procentach`"
                                            class="h-11 w-20 rounded-xl border border-rule-strong px-3 text-base text-ink tabular-nums focus:border-accent focus:outline-none"
                                        />
                                        <span class="text-sm text-ink-muted">
                                            % — {{ kcalForSlot(slot) }} kcal
                                        </span>
                                    </div>
                                </div>

                                <p
                                    class="mt-2 text-sm"
                                    :class="
                                        sharesAddUp
                                            ? 'text-ink-faint'
                                            : 'text-flag'
                                    "
                                >
                                    {{
                                        sharesAddUp
                                            ? 'Razem 100% dnia.'
                                            : `Razem ${shareTotal}% — dzień musi się zsumować do 100%.`
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-t border-rule px-4 py-3 pb-safe sm:px-8">
                <div class="mx-auto flex max-w-3xl gap-2">
                    <button
                        type="button"
                        :disabled="
                            generating ||
                            wantedCount === 0 ||
                            (useTargets && !sharesAddUp)
                        "
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

        <!--
            Choosing a replacement by hand. Every row says what the meal would
            come to — the calories, the protein and the price — because that is
            the whole reason for choosing rather than shuffling.
        -->
        <div
            v-if="swapSheet !== null"
            class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-label="Wybierz inne danie"
            @click.self="closeSwap"
        >
            <div
                class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-t-3xl bg-paper sm:rounded-3xl"
            >
                <div
                    class="flex items-start justify-between border-b border-rule px-4 py-4 sm:px-8"
                >
                    <div class="min-w-0">
                        <p class="font-semibold text-ink">Zamiast tego dania</p>
                        <p class="mt-0.5 truncate text-sm text-ink-muted">
                            {{ swapSheet.title }}
                        </p>
                        <!--
                            The figure the offers are matched against. Without it
                            a lunch reading "2 223 kcal razem" looks broken beside
                            a 2 500 daily target — it is four portions for two
                            people, and this is the line that says so.
                        -->
                        <p
                            v-if="props.replacing?.kcal"
                            class="mt-1 text-sm text-ink-faint tabular-nums"
                        >
                            {{ props.replacing.servings }} porcje ·
                            {{ props.replacing.kcal }} kcal razem — tyle samo
                            dostaniesz z każdego poniżej
                        </p>
                    </div>

                    <button
                        type="button"
                        class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink"
                        aria-label="Zamknij"
                        @click="closeSwap"
                    >
                        <AppIcon name="cross" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 sm:px-8">
                    <p
                        v-if="loadingAlternatives"
                        class="py-6 text-sm text-ink-muted"
                    >
                        Szukam…
                    </p>

                    <!--
                        Nothing to offer is a real answer: this meal has been
                        eaten recently, or the catalogue has nothing else of the
                        right size. Saying so beats an empty list.
                    -->
                    <p
                        v-else-if="alternatives.length === 0"
                        class="py-6 text-sm text-ink-muted"
                    >
                        Nie mam czym tego zastąpić — nic innego o tej porze nie
                        pasuje kalorycznie albo już to jadłeś w tym miesiącu.
                    </p>

                    <ul v-else class="divide-y divide-rule">
                        <li v-for="one in alternatives" :key="one.recipeId">
                            <button
                                type="button"
                                :disabled="choosing !== null"
                                class="flex w-full items-center gap-3 py-3 text-left disabled:opacity-60"
                                @click="choose(one)"
                            >
                                <img
                                    v-if="one.imageUrl"
                                    :src="one.imageUrl"
                                    alt=""
                                    class="h-12 w-12 shrink-0 rounded-xl object-cover"
                                    loading="lazy"
                                />
                                <span
                                    v-else
                                    class="h-12 w-12 shrink-0 rounded-xl bg-paper-sunk"
                                />

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-ink">
                                        {{ one.title }}
                                    </span>

                                    <span
                                        class="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-ink-muted tabular-nums"
                                    >
                                        <span
                                            v-if="one.kcalPerPortion !== null"
                                        >
                                            {{ one.kcalPerPortion }} kcal/porcja
                                        </span>
                                        <span
                                            v-if="
                                                one.proteinPerPortion !== null
                                            "
                                        >
                                            B {{ one.proteinPerPortion }} g
                                        </span>
                                        <span v-if="one.cost !== null">
                                            {{ formatMoney(one.cost) }}
                                        </span>
                                        <span class="text-ink-faint">
                                            {{ one.servings }} porcje ·
                                            {{ one.kcal }} kcal razem
                                        </span>
                                        <span
                                            v-if="one.missing === 0"
                                            class="text-accent-strong"
                                        >
                                            masz wszystko
                                        </span>
                                        <span
                                            v-else-if="
                                                one.missing !== null &&
                                                one.missing <= 2
                                            "
                                        >
                                            brakuje {{ one.missing }}
                                        </span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
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
