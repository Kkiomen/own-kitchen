<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import FridgeScan from '@/components/FridgeScan.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { formatQuantity } from '@/lib/quantity';
import { destroy, store, update } from '@/routes/pantry';

interface PantryProduct {
    id: number;
    name: string;
    emoji: string;
    defaultUnitId: number | null;
    /** Where this kind of thing usually goes — a suggestion, not a rule. */
    location: string;
}

interface PantryEntry {
    id: number;
    ingredientId: number;
    name: string;
    emoji: string;
    quantity: number | null;
    unitId: number | null;
    unit: string | null;
    expiresAt: string | null;
    expired: boolean;
    note: string | null;
}

interface PantrySection {
    value: string;
    label: string;
    tracksExpiry: boolean;
    items: PantryEntry[];
}

const props = defineProps<{
    sections: PantrySection[];
    units: { id: number; symbol: string; name: string }[];
    ingredients: PantryProduct[];
    expiringSoon: number;
    /** False when no OpenAI key is configured — then there is no camera at all. */
    photoEnabled: boolean;
}>();

const sheetOpen = ref(false);
const search = ref('');
const chosen = ref<PantryProduct | null>(null);
/** The row being corrected, or null while adding. The sheet is the same either way. */
const editing = ref<PantryEntry | null>(null);
/** Deleting is two taps, because the whole row now opens the sheet. */
const confirmingRemoval = ref(false);
/** What went in during this trip — so putting a bagful away feels like progress. */
const justAdded = ref<{ name: string; emoji: string }[]>([]);

const searchField = ref<HTMLInputElement | null>(null);
const addButton = ref<HTMLButtonElement | null>(null);

const form = useForm({
    ingredient_id: null as number | null,
    location: 'fridge',
    quantity: null as number | null,
    unit_id: null as number | null,
    expires_at: null as string | null,
    note: null as string | null,
});

/**
 * Filtered in the browser over the whole product list. Capped at twelve: a longer
 * list on a phone is a wall, not a choice.
 */
const matches = computed<PantryProduct[]>(() => {
    const needle = search.value.trim().toLowerCase();

    if (needle.length < 2) {
        return [];
    }

    const hits = props.ingredients.filter((product) =>
        product.name.toLowerCase().includes(needle),
    );

    // Something that starts with what you typed is what you meant.
    hits.sort((a, b) => {
        const aStarts = a.name.toLowerCase().startsWith(needle) ? 0 : 1;
        const bStarts = b.name.toLowerCase().startsWith(needle) ? 0 : 1;

        return aStarts - bStarts || a.name.localeCompare(b.name, 'pl');
    });

    return hits.slice(0, 12);
});

const total = computed<number>(() =>
    props.sections.reduce((sum, section) => sum + section.items.length, 0),
);

const currentSection = computed<PantrySection | undefined>(() =>
    props.sections.find((section) => section.value === form.location),
);

const unitSymbol = computed<string>(
    () => props.units.find((unit) => unit.id === form.unit_id)?.symbol ?? '',
);

async function openSheet(): Promise<void> {
    sheetOpen.value = true;
    editing.value = null;
    justAdded.value = [];
    resetEntry();
    await nextTick();
    searchField.value?.focus();
}

/**
 * Correcting what is already on a shelf uses the same sheet as putting it there,
 * prefilled with what we hold — the fields and the rules are identical.
 */
function openEdit(item: PantryEntry, location: string): void {
    resetEntry();
    justAdded.value = [];
    editing.value = item;
    chosen.value = {
        id: item.ingredientId,
        name: item.name,
        emoji: item.emoji,
        defaultUnitId: item.unitId,
        location,
    };
    form.ingredient_id = item.ingredientId;
    form.location = location;
    form.quantity = item.quantity;
    form.unit_id = item.unitId;
    form.expires_at = item.expiresAt;
    form.note = item.note;
    sheetOpen.value = true;
}

function closeSheet(): void {
    sheetOpen.value = false;
    editing.value = null;
    addButton.value?.focus();
}

function resetEntry(): void {
    form.reset();
    form.clearErrors();
    chosen.value = null;
    search.value = '';
    confirmingRemoval.value = false;
}

function choose(product: PantryProduct): void {
    chosen.value = product;
    form.ingredient_id = product.id;
    // The unit it is normally bought in and the shelf it normally lives on:
    // both usually right, both one tap from being changed.
    form.unit_id = product.defaultUnitId;
    form.location = product.location;
    /*
     * Putting a bagful away is the real task, and most of it is single items —
     * so the commonest answer is already typed. Only when a unit is known: an
     * amount without one is refused, which would turn a shortcut into an error.
     */
    form.quantity = product.defaultUnitId === null ? null : 1;
}

/** Replacing the suggested amount must be one gesture, not select-then-delete. */
function selectAll(event: FocusEvent): void {
    (event.target as HTMLInputElement).select();
}

function submit(): void {
    if (editing.value !== null) {
        form.patch(update.url(editing.value.id), {
            preserveScroll: true,
            onSuccess: closeSheet,
        });

        return;
    }

    form.post(store.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            justAdded.value.unshift({
                name: chosen.value?.name ?? '',
                emoji: chosen.value?.emoji ?? '',
            });
            resetEntry();
            searchField.value?.focus();
        },
    });
}

function remove(): void {
    if (editing.value === null) {
        return;
    }

    router.delete(destroy.url(editing.value.id), {
        preserveScroll: true,
        onSuccess: closeSheet,
    });
}

watch(sheetOpen, (open) => {
    // The list behind the sheet must not scroll under a thumb aiming at a result.
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<template>
    <Head title="Lodówka" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader title="Moja kuchnia" current="pantry" :count="total">
            <template #subtitle>
                <p v-if="expiringSoon > 0" class="text-xs text-flag">
                    {{ expiringSoon }} rzeczy traci ważność w ciągu 3 dni
                </p>
            </template>
        </AppHeader>

        <main class="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!-- One way in, whatever shelf the thing belongs on. The camera is
                 a second way to say the same thing, and deliberately the quieter
                 of the two: typing it in always works, a photo sometimes does. -->
            <div class="mb-8 space-y-2">
                <button
                    ref="addButton"
                    type="button"
                    class="flex h-13 w-full items-center justify-center gap-2 rounded-full bg-accent text-base font-semibold text-ink transition-colors hover:bg-accent-strong hover:text-paper focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-strong"
                    @click="openSheet"
                >
                    <AppIcon name="plus" />
                    Dodaj zakupy
                </button>

                <FridgeScan
                    v-if="photoEnabled"
                    :sections="sections"
                    :units="units"
                    :ingredients="ingredients"
                />
            </div>

            <section
                v-for="section in sections"
                :key="section.value"
                class="mb-10"
            >
                <h2 class="mb-3 font-semibold text-ink">
                    {{ section.label }}
                    <span
                        class="ml-1 text-sm font-normal text-ink-muted tabular-nums"
                    >
                        {{ section.items.length }}
                    </span>
                </h2>

                <ul
                    v-if="section.items.length > 0"
                    class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                >
                    <li v-for="item in section.items" :key="item.id">
                        <!-- The whole row edits: what you have changes far more
                             often than what you own. -->
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-paper-sunk"
                            :aria-label="`Zmień ${item.name}`"
                            @click="openEdit(item, section.value)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-ink">
                                    <IngredientLabel
                                        :emoji="item.emoji"
                                        :name="item.name"
                                    />
                                </p>
                                <p
                                    class="mt-0.5 flex flex-wrap gap-x-3 pl-7 text-xs text-ink-muted"
                                >
                                    <span
                                        v-if="item.quantity !== null"
                                        class="tabular-nums"
                                    >
                                        {{
                                            formatQuantity(
                                                item.quantity,
                                                item.unit,
                                            )
                                        }}
                                    </span>
                                    <span v-else>ilość nieokreślona</span>
                                    <span
                                        v-if="item.expiresAt"
                                        :class="
                                            item.expired
                                                ? 'font-medium text-flag'
                                                : ''
                                        "
                                    >
                                        {{
                                            item.expired
                                                ? 'przeterminowane'
                                                : 'do'
                                        }}
                                        {{ item.expiresAt }}
                                    </span>
                                </p>
                            </div>

                            <AppIcon
                                name="pencil"
                                class="shrink-0 text-ink-faint"
                            />
                        </button>
                    </li>
                </ul>

                <p
                    v-else
                    class="rounded-2xl border border-dashed border-rule px-4 py-8 text-center text-sm text-ink-muted"
                >
                    Pusto.
                </p>
            </section>
        </main>

        <AppNav current="pantry" />

        <!-- The add sheet: search, pick, amount, done — then straight back to search. -->
        <div
            v-if="sheetOpen"
            class="fixed inset-0 z-40 flex flex-col bg-paper"
            role="dialog"
            aria-modal="true"
            :aria-label="editing ? 'Zmień ilość' : 'Dodaj zakupy'"
            @keydown.esc="closeSheet"
        >
            <div class="border-b border-rule px-4 pt-safe sm:px-8">
                <div
                    class="mx-auto flex max-w-3xl items-center justify-between gap-3 py-3"
                >
                    <h2 class="text-lg font-semibold text-ink">
                        {{ editing ? 'Ile tego masz?' : 'Co kupiłeś?' }}
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
                    <!-- Editing already knows the product; only what we hold of
                         it is in question. -->
                    <template v-if="!editing">
                        <label for="pantry-search" class="sr-only">
                            Szukaj produktu
                        </label>
                        <input
                            id="pantry-search"
                            ref="searchField"
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            placeholder="Wpisz produkt, np. mąka…"
                            class="h-13 w-full rounded-xl border border-rule-strong px-4 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                            @input="chosen = null"
                        />
                    </template>

                    <!-- Pick a product -->
                    <template v-if="!chosen">
                        <ul v-if="matches.length > 0" class="mt-3 space-y-1">
                            <li v-for="product in matches" :key="product.id">
                                <button
                                    type="button"
                                    class="flex h-13 w-full items-center justify-between gap-3 rounded-xl px-4 text-left text-ink hover:bg-paper-sunk"
                                    @click="choose(product)"
                                >
                                    <IngredientLabel
                                        :emoji="product.emoji"
                                        :name="product.name"
                                    />
                                    <span class="text-xs text-ink-muted">
                                        {{
                                            sections.find(
                                                (s) =>
                                                    s.value ===
                                                    product.location,
                                            )?.label
                                        }}
                                    </span>
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

                    <!-- Amount, shelf, date -->
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
                                    for="pantry-qty"
                                    class="mb-1.5 block text-sm text-ink-muted"
                                >
                                    Ile
                                </label>
                                <input
                                    id="pantry-qty"
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
                                    for="pantry-unit"
                                    class="mb-1.5 block text-sm text-ink-muted"
                                >
                                    Jednostka
                                </label>
                                <select
                                    id="pantry-unit"
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
                            Ilość możesz pominąć — wtedy zapiszę po prostu, że
                            to masz.
                        </p>

                        <p v-if="form.errors.unit_id" class="text-sm text-flag">
                            {{ form.errors.unit_id }}
                        </p>

                        <fieldset>
                            <legend class="mb-1.5 text-sm text-ink-muted">
                                Gdzie to kładziesz?
                            </legend>
                            <div class="flex gap-2">
                                <button
                                    v-for="section in sections"
                                    :key="section.value"
                                    type="button"
                                    class="h-11 flex-1 rounded-full border text-sm transition-colors"
                                    :class="
                                        form.location === section.value
                                            ? 'border-accent-strong bg-accent-soft font-medium text-accent-strong'
                                            : 'border-rule-strong text-ink-muted'
                                    "
                                    :aria-pressed="
                                        form.location === section.value
                                    "
                                    @click="form.location = section.value"
                                >
                                    {{ section.label }}
                                </button>
                            </div>
                        </fieldset>

                        <div v-if="currentSection?.tracksExpiry">
                            <label
                                for="pantry-expiry"
                                class="mb-1.5 block text-sm text-ink-muted"
                            >
                                Ważne do (opcjonalnie)
                            </label>
                            <input
                                id="pantry-expiry"
                                v-model="form.expires_at"
                                type="date"
                                class="h-13 w-full rounded-xl border border-rule-strong px-3 text-base text-ink focus:border-accent focus:outline-none"
                            />
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="h-13 flex-1 rounded-full bg-accent font-semibold text-ink disabled:opacity-60"
                            >
                                {{
                                    form.processing
                                        ? 'Zapisuję…'
                                        : editing
                                          ? 'Zapisz'
                                          : 'Dodaj'
                                }}
                            </button>
                            <button
                                type="button"
                                class="h-13 rounded-full border border-rule-strong px-5 text-ink-muted"
                                @click="editing ? closeSheet() : resetEntry()"
                            >
                                {{ editing ? 'Anuluj' : 'Wróć' }}
                            </button>
                        </div>

                        <!-- Two taps, because the whole row opens this sheet and
                             a mis-tap must not empty a shelf. -->
                        <div v-if="editing" class="pt-2 text-center">
                            <button
                                v-if="!confirmingRemoval"
                                type="button"
                                class="h-11 px-4 text-sm text-ink-muted underline underline-offset-4"
                                @click="confirmingRemoval = true"
                            >
                                Usuń z kuchni
                            </button>
                            <button
                                v-else
                                type="button"
                                class="h-11 rounded-full border border-flag px-5 text-sm font-medium text-flag"
                                @click="remove"
                            >
                                Na pewno usunąć {{ editing.name }}?
                            </button>
                        </div>
                    </form>

                    <!-- This trip's haul, newest first. -->
                    <div v-if="justAdded.length > 0" class="mt-8">
                        <p class="mb-2 label-caps text-ink-faint">
                            Dodane ({{ justAdded.length }})
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="(added, index) in justAdded"
                                :key="`${added.name}-${index}`"
                                class="flex items-center gap-2 text-sm text-ink-muted"
                            >
                                <AppIcon
                                    name="check"
                                    class="text-accent-strong"
                                />
                                <IngredientLabel
                                    :emoji="added.emoji"
                                    :name="added.name"
                                />
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
