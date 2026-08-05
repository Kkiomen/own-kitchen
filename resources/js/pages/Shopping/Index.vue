<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { formatMoney } from '@/lib/money';
import { formatQuantity, stepFor, stepped, steppedDown } from '@/lib/quantity';
import { index as prices } from '@/routes/prices';
import {
    destroy,
    listPlan,
    listStockUp,
    show,
    store,
    update,
} from '@/routes/shopping';
import {
    destroy as destroyList,
    store as storeList,
    update as renameList,
} from '@/routes/shopping/lists';

interface ShoppingProduct {
    id: number;
    name: string;
    emoji: string;
    defaultUnitId: number | null;
}

interface ShoppingEntry {
    id: number;
    name: string;
    emoji: string;
    quantity: number | null;
    unit: string | null;
    /**
     * What an amount typed on this row is counted in — the line's own unit, or
     * the one the product is normally bought in when it has none yet. Null when
     * nothing knows, and then the amount cannot be edited at all.
     */
    measureUnit: string | null;
    measureCode: string | null;
    bought: boolean;
    note: string | null;
    /** On offer in at least one shop's current leaflet. */
    promoted: boolean;
    /** Grosze. Null on a ticked line, and on one nothing could price. */
    cost: number | null;
    costBasis: CostBasis | null;
}

/**
 * How much to lean on a line's figure. A promotion is a price this week's
 * leaflet is charging; the other two are typical prices, and `pack` does not
 * even know what size a pack is.
 */
type CostBasis = 'promotion' | 'unit' | 'pack' | 'unknown';

interface Estimate {
    /** Grosze, both. */
    total: number;
    promoted: number;
    priced: number;
    unpriced: number;
    hasAnyPrice: boolean;
}

interface Aisle {
    value: string;
    label: string;
    items: ShoppingEntry[];
}

interface ShoppingListSummary {
    id: number;
    name: string;
    isDefault: boolean;
    /** The main list is never deletable — something has to stay to write to. */
    deletable: boolean;
    toBuyCount: number | null;
}

const props = defineProps<{
    list: ShoppingListSummary;
    lists: ShoppingListSummary[];
    aisles: Aisle[];
    boughtCount: number;
    promotedCount: number;
    estimate: Estimate;
    units: { id: number; symbol: string; name: string }[];
    ingredients: ShoppingProduct[];
}>();

const sheetOpen = ref(false);
const search = ref('');
const chosen = ref<ShoppingProduct | null>(null);
const searchField = ref<HTMLInputElement | null>(null);
const addButton = ref<HTMLButtonElement | null>(null);

const form = useForm({
    ingredient_id: null as number | null,
    quantity: null as number | null,
    unit_id: null as number | null,
    note: null as string | null,
    /** Whichever list is open — never the main one by accident. */
    shopping_list_id: null as number | null,
});

const total = computed<number>(() =>
    props.aisles.reduce((sum, aisle) => sum + aisle.items.length, 0),
);

const matches = computed<ShoppingProduct[]>(() => {
    const needle = search.value.trim().toLowerCase();

    if (needle.length < 2) {
        return [];
    }

    const hits = props.ingredients.filter((product) =>
        product.name.toLowerCase().includes(needle),
    );

    hits.sort((a, b) => {
        const aStarts = a.name.toLowerCase().startsWith(needle) ? 0 : 1;
        const bStarts = b.name.toLowerCase().startsWith(needle) ? 0 : 1;

        return aStarts - bStarts || a.name.localeCompare(b.name, 'pl');
    });

    return hits.slice(0, 12);
});

const unitSymbol = computed<string>(
    () => props.units.find((unit) => unit.id === form.unit_id)?.symbol ?? '',
);

async function openSheet(): Promise<void> {
    sheetOpen.value = true;
    resetEntry();
    await nextTick();
    searchField.value?.focus();
}

function closeSheet(): void {
    sheetOpen.value = false;
    addButton.value?.focus();
}

function resetEntry(): void {
    form.reset();
    form.clearErrors();
    chosen.value = null;
    search.value = '';
}

function choose(product: ShoppingProduct): void {
    chosen.value = product;
    form.ingredient_id = product.id;
    // The unit it is normally bought in, with the commonest amount already
    // typed — both one tap from being changed. Never an amount without a unit:
    // that combination is refused.
    form.unit_id = product.defaultUnitId;
    form.quantity = product.defaultUnitId === null ? null : 1;
}

/** Replacing the suggested amount must be one gesture, not select-then-delete. */
function selectAll(event: FocusEvent): void {
    (event.target as HTMLInputElement).select();
}

function submit(): void {
    form.shopping_list_id = props.list.id;

    form.post(store.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            resetEntry();
            searchField.value?.focus();
        },
    });
}

/** Ticking in the shop must be instant, so this posts without a page change. */
function toggle(item: ShoppingEntry): void {
    router.patch(
        update.url(item.id),
        { bought: !item.bought },
        { preserveScroll: true, preserveState: true },
    );
}

/*
 * The amount shown while a change is in flight. Tapping "+" four times must
 * count four times without waiting for four round trips, so the row reads from
 * here until the server confirms — and each request carries the absolute
 * amount, so the last tap wins whatever order the responses come back in.
 */
const drafts = ref<Record<number, number | null>>({});

function shownQuantity(item: ShoppingEntry): number | null {
    return item.id in drafts.value ? drafts.value[item.id] : item.quantity;
}

/**
 * A recipe's "1/3 szklanki" is stored as 0.3333333333, and a box four
 * characters wide would show "0,33…" — an amount that looks like a fault. The
 * box therefore reads two decimals. The stored number is left alone until
 * somebody actually changes it, and one tap of the stepper is measured from
 * what is on screen.
 */
function boxed(item: ShoppingEntry): string {
    const quantity = shownQuantity(item);

    return quantity === null ? '' : String(Number(quantity.toFixed(2)));
}

/** An amount with nothing to measure it in cannot be written down honestly. */
function canAdjust(item: ShoppingEntry): boolean {
    return !item.bought && item.measureCode !== null;
}

function setQuantity(item: ShoppingEntry, quantity: number | null): void {
    drafts.value[item.id] = quantity;

    router.patch(
        update.url(item.id),
        { quantity },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                delete drafts.value[item.id];
            },
        },
    );
}

function bump(item: ShoppingEntry, direction: 1 | -1): void {
    const step = stepFor(item.measureCode);
    const from = shownQuantity(item);

    setQuantity(
        item,
        direction === 1 ? stepped(from, step) : steppedDown(from, step),
    );
}

/** Typing wins over the buttons: whatever is in the box is the amount. */
function typeQuantity(item: ShoppingEntry, event: Event): void {
    const value = (event.target as HTMLInputElement).value.trim();

    if (value === '') {
        setQuantity(item, null);

        return;
    }

    const amount = Number(value.replace(',', '.'));

    if (!Number.isFinite(amount) || amount < 0) {
        return;
    }

    setQuantity(item, amount === 0 ? null : amount);
}

function remove(item: ShoppingEntry): void {
    router.delete(destroy.url(item.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

/** The point of the whole screen: shopping becomes a stocked kitchen. */
function putAway(): void {
    router.post(listStockUp.url(props.list.id), {}, { preserveScroll: true });
}

/*
 * Naming a list and renaming one are the same form in two moods, so they share
 * it. Only one can be open at a time, which is also why closing either is one
 * function.
 */
const naming = ref<'new' | 'rename' | null>(null);
const confirmingDelete = ref(false);
const nameField = ref<HTMLInputElement | null>(null);

const listForm = useForm({ name: '' });

async function openNaming(mood: 'new' | 'rename'): Promise<void> {
    naming.value = mood;
    confirmingDelete.value = false;
    listForm.clearErrors();
    listForm.name = mood === 'rename' ? props.list.name : '';
    await nextTick();
    nameField.value?.focus();
}

function closeNaming(): void {
    naming.value = null;
    listForm.reset();
    listForm.clearErrors();
}

function submitName(): void {
    if (naming.value === 'rename') {
        listForm.patch(renameList.url(props.list.id), {
            preserveScroll: true,
            onSuccess: closeNaming,
        });

        return;
    }

    // The server sends us to the new list: somebody who just named one is about
    // to put something on it.
    listForm.post(storeList.url(), { onSuccess: closeNaming });
}

/**
 * Deleting takes the lines with it, so it asks first — in the page rather than
 * in a browser dialog, which on a phone is a modal you cannot style and cannot
 * dismiss with a thumb.
 */
function removeList(): void {
    router.delete(destroyList.url(props.list.id));
}

watch(sheetOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<template>
    <!--
        Which list, once there is more than one — two tabs both reading
        "Co kupić" are the same problem the switcher exists to solve.
    -->
    <Head :title="list.isDefault ? 'Co kupić' : `Co kupić — ${list.name}`" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader title="Co kupić" current="shopping" :count="total">
            <template #actions>
                <!--
                    What things normally cost, as opposed to what they cost this
                    week. It belongs beside the trolley because that is where the
                    question "czy to teraz drogie" gets asked.
                -->
                <Link
                    :href="prices.url()"
                    class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:text-ink"
                    title="Ceny"
                    aria-label="Ceny"
                >
                    <AppIcon name="tag" class="h-5 w-5" />
                </Link>
            </template>
        </AppHeader>

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!--
                Which list is open. Chips rather than a select: on a phone the
                lists are two or three and a tap beats a picker, and the counts
                have to be visible to be worth switching between.
            -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <Link
                    v-for="option in lists"
                    :key="option.id"
                    :href="show.url(option.id)"
                    class="flex h-11 items-center gap-2 rounded-full border px-4 text-sm transition-colors"
                    :class="
                        option.id === list.id
                            ? 'border-accent bg-accent font-medium text-ink'
                            : 'border-rule-strong text-ink-muted hover:text-ink'
                    "
                    :aria-current="option.id === list.id ? 'page' : undefined"
                >
                    {{ option.name }}
                    <span
                        v-if="option.toBuyCount"
                        class="tabular-nums"
                        :class="
                            option.id === list.id
                                ? 'text-ink'
                                : 'text-ink-faint'
                        "
                    >
                        {{ option.toBuyCount }}
                    </span>
                </Link>

                <button
                    v-if="naming === null"
                    type="button"
                    class="flex h-11 items-center gap-1 rounded-full border border-dashed border-rule-strong px-4 text-sm text-ink-muted hover:text-ink"
                    @click="openNaming('new')"
                >
                    <AppIcon name="plus" />
                    Nowa lista
                </button>
            </div>

            <!-- Naming a new list, or renaming this one: one form, two moods. -->
            <form
                v-if="naming !== null"
                class="mb-4 flex flex-wrap items-center gap-2"
                @submit.prevent="submitName"
            >
                <label for="list-name" class="sr-only">Nazwa listy</label>
                <input
                    id="list-name"
                    ref="nameField"
                    v-model="listForm.name"
                    type="text"
                    maxlength="60"
                    placeholder="np. Grill w sobotę"
                    class="h-11 min-w-0 flex-1 rounded-xl border border-rule-strong px-3 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                    @keydown.esc="closeNaming"
                />
                <button
                    type="submit"
                    :disabled="
                        listForm.processing || listForm.name.trim() === ''
                    "
                    class="h-11 rounded-full bg-accent px-5 text-sm font-semibold text-ink disabled:opacity-60"
                >
                    {{ naming === 'rename' ? 'Zmień nazwę' : 'Utwórz' }}
                </button>
                <button
                    type="button"
                    class="h-11 rounded-full border border-rule-strong px-4 text-sm text-ink-muted"
                    @click="closeNaming"
                >
                    Anuluj
                </button>
                <p v-if="listForm.errors.name" class="w-full text-sm text-flag">
                    {{ listForm.errors.name }}
                </p>
            </form>

            <!--
                Housekeeping for the open list. Quiet by design: these are rare
                next to ticking things off, and the main list cannot be deleted
                at all — something has to stay to write to.
            -->
            <div
                v-if="naming === null"
                class="mb-6 flex flex-wrap items-center gap-3 text-sm"
            >
                <button
                    type="button"
                    class="flex h-11 items-center gap-1.5 text-ink-muted hover:text-ink"
                    @click="openNaming('rename')"
                >
                    <AppIcon name="pencil" />
                    Zmień nazwę
                </button>

                <template v-if="list.deletable">
                    <button
                        v-if="!confirmingDelete"
                        type="button"
                        class="flex h-11 items-center gap-1.5 text-ink-muted hover:text-ink"
                        @click="confirmingDelete = true"
                    >
                        <AppIcon name="clear" />
                        Usuń listę
                    </button>

                    <span
                        v-else
                        class="flex flex-wrap items-center gap-2 text-ink-muted"
                    >
                        Usunąć „{{ list.name }}” razem z tym, co na niej jest?
                        <button
                            type="button"
                            class="h-11 rounded-full border border-flag px-4 text-flag"
                            @click="removeList"
                        >
                            Usuń
                        </button>
                        <button
                            type="button"
                            class="h-11 rounded-full border border-rule-strong px-4"
                            @click="confirmingDelete = false"
                        >
                            Zostaw
                        </button>
                    </span>
                </template>
            </div>

            <!--
                What the trolley is likely to come to. Above the buttons because
                it is the answer to the question somebody opens this screen with,
                and hidden entirely until something can be priced — a total of
                "0 zł" over a full list is not a smaller number, it is a wrong one.
            -->
            <section
                v-if="props.estimate.hasAnyPrice"
                class="mb-6 rounded-2xl border border-rule bg-paper-raised px-4 py-3"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <span class="label-caps text-ink-faint">Mniej więcej</span>
                    <span class="text-2xl font-semibold text-ink tabular-nums">
                        {{ formatMoney(props.estimate.total) }}
                    </span>
                </div>

                <p
                    v-if="props.estimate.promoted > 0"
                    class="mt-1 text-right text-sm text-accent-strong tabular-nums"
                >
                    w tym {{ formatMoney(props.estimate.promoted) }} z gazetek
                </p>

                <!--
                    The count of unpriced lines is not a footnote — without it the
                    total silently understates the bill, and understating is the
                    direction that costs money.
                -->
                <p class="mt-2 text-xs text-ink-faint">
                    <template v-if="props.estimate.unpriced > 0">
                        Nie znam ceny
                        {{ props.estimate.unpriced }}
                        {{
                            props.estimate.unpriced === 1
                                ? 'produktu'
                                : 'produktów'
                        }}
                        — rachunek będzie wyższy.
                    </template>
                    <template v-else>
                        Szacunek na podstawie gazetek i średnich cen GUS.
                    </template>
                </p>
            </section>

            <button
                ref="addButton"
                type="button"
                class="mb-6 flex h-13 w-full items-center justify-center gap-2 rounded-full bg-accent text-base font-semibold text-ink transition-colors hover:bg-accent-strong hover:text-paper focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-strong"
                @click="openSheet"
            >
                <AppIcon name="plus" />
                Dopisz produkt
            </button>

            <!--
                Hidden until something on the list is actually on offer: a button
                promising cheaper shopping that opens an empty screen is worse
                than no button.
            -->
            <Link
                v-if="promotedCount > 0"
                :href="listPlan.url(list.id)"
                class="mb-6 flex h-13 w-full items-center justify-center gap-2 rounded-full border border-accent-strong text-base font-semibold text-accent-strong"
            >
                <AppIcon name="tag" />
                Gdzie kupić taniej ({{ promotedCount }})
            </Link>

            <!-- Unpacking: what was ticked off moves onto the shelves. -->
            <button
                v-if="boughtCount > 0"
                type="button"
                class="mb-8 flex h-12 w-full items-center justify-center gap-2 rounded-full border border-accent-strong px-4 text-sm font-medium text-accent-strong"
                @click="putAway"
            >
                <AppIcon name="ingredients" />
                Przenieś kupione ({{ boughtCount }}) do kuchni
            </button>

            <p
                v-if="total === 0"
                class="rounded-2xl border border-dashed border-rule px-4 py-12 text-center text-sm text-ink-muted"
            >
                Lista jest pusta. Dopisz produkt albo otwórz przepis i kliknij
                „Dodaj do listy zakupów”.
            </p>

            <section v-for="aisle in aisles" :key="aisle.value" class="mb-8">
                <h2 class="mb-2 label-caps text-ink-faint">
                    {{ aisle.label }}
                </h2>

                <ul
                    class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                >
                    <li
                        v-for="item in aisle.items"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-1 pr-2"
                    >
                        <!-- The whole row is the checkbox: it is tapped in a shop, one-handed. -->
                        <button
                            type="button"
                            class="order-1 flex min-h-13 flex-1 items-center gap-3 py-3 pl-4 text-left"
                            :aria-pressed="item.bought"
                            @click="toggle(item)"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border"
                                :class="
                                    item.bought
                                        ? 'border-accent-strong bg-accent-strong text-paper'
                                        : 'border-rule-strong'
                                "
                            >
                                <AppIcon v-if="item.bought" name="check" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block"
                                    :class="
                                        item.bought
                                            ? 'text-ink-faint line-through'
                                            : 'text-ink'
                                    "
                                >
                                    <IngredientLabel
                                        :emoji="item.emoji"
                                        :name="item.name"
                                    />
                                    <span
                                        v-if="item.promoted && !item.bought"
                                        class="ml-2 inline-flex items-center rounded-full bg-accent-soft px-2 py-0.5 align-middle text-xs font-medium text-accent-strong"
                                    >
                                        promocja
                                    </span>
                                </span>

                                <!--
                                    The tilde is doing real work: it separates a
                                    price this week's leaflet is charging from a
                                    typical price for a pack of unknown size, and
                                    those deserve different amounts of trust.
                                -->
                                <span
                                    v-if="item.cost !== null"
                                    class="block text-xs tabular-nums"
                                    :class="
                                        item.costBasis === 'promotion'
                                            ? 'text-accent-strong'
                                            : 'text-ink-faint'
                                    "
                                >
                                    {{
                                        item.costBasis === 'promotion'
                                            ? ''
                                            : '~'
                                    }}{{ formatMoney(item.cost) }}
                                </span>
                                <!--
                                    Ticked off, or nothing knows what to count
                                    it in: the amount is read, not edited.
                                -->
                                <span
                                    v-if="
                                        !canAdjust(item) &&
                                        item.quantity !== null
                                    "
                                    class="block pl-7 text-xs text-ink-muted tabular-nums"
                                >
                                    {{
                                        formatQuantity(item.quantity, item.unit)
                                    }}
                                </span>
                            </span>
                        </button>

                        <!--
                            Adjusting at the shelf: two taps or one typed
                            number. Outside the row's own button, or every
                            change would tick the item off as well.

                            On a phone it takes a line of its own, ordered below
                            the name: four controls at the 44px a thumb needs
                            leave a product called "Mięso mielone wieprzowe"
                            nowhere to go on the same line.
                        -->
                        <div
                            v-if="canAdjust(item)"
                            class="order-3 flex w-full shrink-0 items-center justify-end gap-0.5 pb-2 sm:order-2 sm:w-auto sm:pb-0"
                        >
                            <button
                                type="button"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink disabled:opacity-40"
                                :disabled="shownQuantity(item) === null"
                                :aria-label="`Mniej ${item.name}`"
                                @click="bump(item, -1)"
                            >
                                <AppIcon name="minus" />
                            </button>

                            <label class="sr-only" :for="`qty-${item.id}`">
                                Ile {{ item.name }}
                            </label>
                            <input
                                :id="`qty-${item.id}`"
                                :value="boxed(item)"
                                type="number"
                                inputmode="decimal"
                                step="any"
                                min="0"
                                :placeholder="item.measureUnit ?? ''"
                                class="h-11 w-14 rounded-lg border border-rule-strong px-1 text-center text-sm text-ink tabular-nums placeholder:text-ink-faint focus:border-accent focus:outline-none"
                                @focus="selectAll"
                                @change="typeQuantity(item, $event)"
                            />

                            <!--
                                Wide enough for the longest symbol in the
                                vocabulary ("gałązka"), so a column of rows
                                lines up and nothing is cut in half.
                            -->
                            <span
                                class="w-14 shrink-0 truncate pl-1.5 text-xs text-ink-muted"
                            >
                                <!-- Empty amount says it in the box's own
                                placeholder; repeating it here would shift the
                                buttons under the thumb. -->
                                {{
                                    shownQuantity(item) === null
                                        ? ''
                                        : item.measureUnit
                                }}
                            </span>

                            <button
                                type="button"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk hover:text-ink"
                                :aria-label="`Więcej ${item.name}`"
                                @click="bump(item, 1)"
                            >
                                <AppIcon name="plus" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="order-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-paper-sunk hover:text-ink sm:order-3"
                            :aria-label="`Usuń ${item.name} z listy`"
                            @click="remove(item)"
                        >
                            <AppIcon name="clear" />
                        </button>
                    </li>
                </ul>
            </section>
        </main>

        <AppNav current="shopping" />

        <div
            v-if="sheetOpen"
            class="fixed inset-0 z-40 flex flex-col bg-paper"
            role="dialog"
            aria-modal="true"
            aria-label="Dopisz produkt"
            @keydown.esc="closeSheet"
        >
            <div class="border-b border-rule px-4 pt-safe sm:px-8">
                <div
                    class="mx-auto flex max-w-3xl items-center justify-between gap-3 py-3"
                >
                    <h2 class="text-lg font-semibold text-ink">Co dopisać?</h2>
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
                    <label for="shopping-search" class="sr-only">
                        Szukaj produktu
                    </label>
                    <input
                        id="shopping-search"
                        ref="searchField"
                        v-model="search"
                        type="search"
                        autocomplete="off"
                        placeholder="Wpisz produkt, np. masło…"
                        class="h-13 w-full rounded-xl border border-rule-strong px-4 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                        @input="chosen = null"
                    />

                    <template v-if="!chosen">
                        <ul v-if="matches.length > 0" class="mt-3 space-y-1">
                            <li v-for="product in matches" :key="product.id">
                                <button
                                    type="button"
                                    class="flex h-13 w-full items-center rounded-xl px-4 text-left text-ink hover:bg-paper-sunk"
                                    @click="choose(product)"
                                >
                                    <IngredientLabel
                                        :emoji="product.emoji"
                                        :name="product.name"
                                    />
                                </button>
                            </li>
                        </ul>

                        <p
                            v-else-if="search.trim().length >= 2"
                            class="mt-4 text-sm text-ink-muted"
                        >
                            Nie znam takiego produktu. Wybierz coś z listy —
                            dzięki temu przepisy się z nim dopasują.
                        </p>

                        <p v-else class="mt-4 text-sm text-ink-muted">
                            Wpisz dwie litery, a podpowiem resztę.
                        </p>
                    </template>

                    <form
                        v-else
                        class="mt-4 space-y-5"
                        @submit.prevent="submit"
                    >
                        <p class="text-lg font-semibold text-ink">
                            <IngredientLabel
                                :emoji="chosen.emoji"
                                :name="chosen.name"
                            />
                        </p>

                        <div class="flex gap-3">
                            <div class="w-28">
                                <label
                                    for="shopping-qty"
                                    class="mb-1.5 block text-sm text-ink-muted"
                                >
                                    Ile
                                </label>
                                <input
                                    id="shopping-qty"
                                    v-model.number="form.quantity"
                                    type="number"
                                    inputmode="decimal"
                                    step="any"
                                    min="0"
                                    :placeholder="unitSymbol"
                                    class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                                    @focus="selectAll"
                                />
                            </div>
                            <div class="flex-1">
                                <label
                                    for="shopping-unit"
                                    class="mb-1.5 block text-sm text-ink-muted"
                                >
                                    Jednostka
                                </label>
                                <select
                                    id="shopping-unit"
                                    v-model="form.unit_id"
                                    class="h-13 w-full rounded-xl border border-rule-strong bg-paper-raised px-3 text-base text-ink focus:border-accent focus:outline-none"
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="unit in units"
                                        :key="unit.id"
                                        :value="unit.id"
                                    >
                                        {{ unit.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <p class="text-xs text-ink-muted">
                            Ilość możesz pominąć — „kup chleb” to też pełna
                            informacja.
                        </p>

                        <p v-if="form.errors.unit_id" class="text-sm text-flag">
                            {{ form.errors.unit_id }}
                        </p>

                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="h-13 flex-1 rounded-full bg-accent font-semibold text-ink disabled:opacity-60"
                            >
                                {{ form.processing ? 'Dopisuję…' : 'Dopisz' }}
                            </button>
                            <button
                                type="button"
                                class="h-13 rounded-full border border-rule-strong px-5 text-ink-muted"
                                @click="resetEntry"
                            >
                                Wróć
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
