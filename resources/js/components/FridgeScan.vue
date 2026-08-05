<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { confirm, read } from '@/routes/pantry/photo';

interface ScanProduct {
    id: number;
    name: string;
    emoji: string;
    defaultUnitId: number | null;
    location: string;
}

interface ScanSection {
    value: string;
    label: string;
}

/** One recognised thing, as it sits on the review screen before being saved. */
interface ScanRow {
    /** Ticked rows are the ones that get written down. */
    include: boolean;
    /** What the model wrote — kept as the evidence, exactly like a line's raw_text. */
    spotted: string;
    ingredientId: number | null;
    name: string | null;
    emoji: string | null;
    quantity: number | null;
    unitId: number | null;
    location: string;
}

const props = defineProps<{
    sections: ScanSection[];
    units: { id: number; symbol: string; name: string }[];
    ingredients: ScanProduct[];
}>();

const page = usePage();

const fileField = ref<HTMLInputElement | null>(null);
const reading = ref(false);
const rows = ref<ScanRow[]>([]);
const reviewing = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
/** Which row is being pointed at a product, if any. */
const picking = ref<number | null>(null);
const search = ref('');
/** How many went in, so the trip reports back rather than looking like nothing. */
const saved = ref<number | null>(null);

/**
 * The longest edge a photo is sent at. A shelf is legible well below a phone
 * camera's twelve megapixels, and the model is billed by the pixel — so this is
 * the difference between a two-second upload on a phone in a kitchen and a
 * thirty-second one.
 */
const MAX_EDGE = 1280;

const chosen = computed<ScanRow[]>(() =>
    rows.value.filter((row) => row.include && row.ingredientId !== null),
);

const unmatched = computed<number>(
    () => rows.value.filter((row) => row.ingredientId === null).length,
);

/** The same twelve-result, starts-with-first search as the manual add sheet. */
const matches = computed<ScanProduct[]>(() => {
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

function unitSymbol(unitId: number | null): string {
    return props.units.find((unit) => unit.id === unitId)?.symbol ?? '';
}

function take(): void {
    error.value = null;
    saved.value = null;
    fileField.value?.click();
}

async function onPicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // Clearing it now, so photographing the same shelf twice still fires a
    // change event the second time.
    input.value = '';

    if (!file) {
        return;
    }

    reading.value = true;
    error.value = null;

    try {
        const shrunk = await shrink(file);

        upload(shrunk);
    } catch {
        reading.value = false;
        error.value = 'Nie udało się przygotować zdjęcia.';
    }
}

/**
 * Down to `MAX_EDGE` and re-encoded as JPEG in the browser. Deliberately before
 * the upload rather than on the server: the slow part of this feature is a phone
 * pushing a twelve-megabyte photo over mobile data, and no amount of server-side
 * resizing makes that upload shorter.
 */
async function shrink(file: File): Promise<Blob> {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');

    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);

    const context = canvas.getContext('2d');

    if (context === null) {
        throw new Error('no 2d context');
    }

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    return await new Promise<Blob>((resolve, reject) => {
        canvas.toBlob(
            (blob) =>
                blob === null ? reject(new Error('no blob')) : resolve(blob),
            'image/jpeg',
            0.82,
        );
    });
}

function upload(photo: Blob): void {
    router.post(
        read.url(),
        { photo: new File([photo], 'polka.jpg', { type: 'image/jpeg' }) },
        {
            forceFormData: true,
            // The shelves underneath must not be re-queried for an answer that
            // arrives in the flash, and the page must stay where it was.
            only: ['flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const spotted = page.props.flash?.pantry?.spotted ?? [];

                rows.value = spotted.map((item) => ({
                    ...item,
                    include: true,
                }));
                reviewing.value = true;
            },
            onError: (errors) => {
                error.value = errors.photo ?? 'Nie udało się odczytać zdjęcia.';
            },
            onFinish: () => {
                reading.value = false;
            },
        },
    );
}

function attach(index: number, product: ScanProduct): void {
    const row = rows.value[index];

    row.ingredientId = product.id;
    row.name = product.name;
    row.emoji = product.emoji;
    row.location = product.location;
    row.include = true;
    // Same rule as the manual sheet: an amount is only prefilled when there is
    // a unit to express it in, because one without the other is refused.
    row.unitId ??= product.defaultUnitId;

    picking.value = null;
    search.value = '';
}

function openPicker(index: number): void {
    picking.value = picking.value === index ? null : index;
    search.value = rows.value[index].spotted;
}

function save(): void {
    if (chosen.value.length === 0) {
        return;
    }

    saving.value = true;

    router.post(
        confirm.url(),
        {
            items: chosen.value.map((row) => ({
                ingredient_id: row.ingredientId,
                location: row.location,
                quantity: row.unitId === null ? null : row.quantity,
                unit_id: row.unitId,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = page.props.flash?.pantry?.added ?? 0;
                close();
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function close(): void {
    reviewing.value = false;
    rows.value = [];
    picking.value = null;
    search.value = '';
}
</script>

<template>
    <div>
        <button
            type="button"
            class="flex h-13 w-full items-center justify-center gap-2 rounded-full border border-accent-strong text-base font-semibold text-accent-strong transition-colors hover:bg-accent-soft disabled:opacity-60"
            :disabled="reading"
            @click="take"
        >
            <AppIcon name="camera" />
            {{ reading ? 'Czytam zdjęcie…' : 'Zrób zdjęcie półki' }}
        </button>

        <!-- `capture` asks the phone for the camera rather than the gallery;
             on a desktop it is ignored and you get a file picker, which is the
             right fallback rather than a broken button. -->
        <input
            ref="fileField"
            type="file"
            accept="image/*"
            capture="environment"
            class="sr-only"
            @change="onPicked"
        />

        <p v-if="error" class="mt-2 text-center text-sm text-flag">
            {{ error }}
        </p>

        <p
            v-else-if="saved !== null"
            class="mt-2 text-center text-sm text-accent-strong"
        >
            Dodano {{ saved }} do kuchni.
        </p>
    </div>

    <!-- Nothing is written until this screen is confirmed: a model reading
         "śmietana" off a tub of yoghurt is a normal Tuesday. -->
    <div
        v-if="reviewing"
        class="fixed inset-0 z-40 flex flex-col bg-paper"
        role="dialog"
        aria-modal="true"
        aria-label="Sprawdź, co widać na zdjęciu"
        @keydown.esc="close"
    >
        <div class="border-b border-rule px-4 pt-safe sm:px-8">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between gap-3 py-3"
            >
                <div>
                    <h2 class="text-lg font-semibold text-ink">
                        Co widać na zdjęciu
                    </h2>
                    <p class="text-xs text-ink-muted">
                        Sprawdź i popraw — zapiszę tylko zaznaczone.
                    </p>
                </div>
                <button
                    type="button"
                    class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk"
                    aria-label="Zamknij"
                    @click="close"
                >
                    <AppIcon name="clear" />
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-4 pb-safe sm:px-8">
            <div class="mx-auto max-w-3xl py-4">
                <p
                    v-if="rows.length === 0"
                    class="rounded-2xl border border-dashed border-rule px-4 py-10 text-center text-sm text-ink-muted"
                >
                    Nic nie rozpoznałem na tym zdjęciu. Spróbuj z bliższej
                    odległości albo przy lepszym świetle.
                </p>

                <p
                    v-else-if="unmatched > 0"
                    class="mb-4 rounded-xl bg-paper-sunk px-4 py-3 text-sm text-ink-muted"
                >
                    {{ unmatched }} rzeczy nie znam z nazwy — wskaż produkt albo
                    zostaw odznaczone.
                </p>

                <ul v-if="rows.length > 0" class="space-y-2">
                    <li
                        v-for="(row, index) in rows"
                        :key="`${row.spotted}-${index}`"
                        class="rounded-2xl border border-rule bg-paper-raised p-3"
                    >
                        <div class="flex items-start gap-3">
                            <!-- 44px of tappable area around a small box: the
                                 whole label is the target, not the tick. -->
                            <label
                                class="flex min-h-11 flex-1 cursor-pointer items-center gap-3"
                            >
                                <input
                                    v-model="row.include"
                                    type="checkbox"
                                    :disabled="row.ingredientId === null"
                                    class="h-5 w-5 shrink-0 accent-accent disabled:opacity-40"
                                />
                                <span class="min-w-0">
                                    <span
                                        v-if="row.ingredientId !== null"
                                        class="block text-ink"
                                    >
                                        <IngredientLabel
                                            :emoji="row.emoji ?? ''"
                                            :name="row.name ?? ''"
                                        />
                                    </span>
                                    <span v-else class="block text-ink-muted">
                                        {{ row.spotted }}
                                    </span>
                                    <!-- What the model wrote, when it is not
                                         what we ended up calling it. -->
                                    <span
                                        v-if="
                                            row.name !== null &&
                                            row.name.toLowerCase() !==
                                                row.spotted.toLowerCase()
                                        "
                                        class="mt-0.5 block pl-7 text-xs text-ink-faint"
                                    >
                                        na zdjęciu: {{ row.spotted }}
                                    </span>
                                </span>
                            </label>

                            <button
                                type="button"
                                class="h-11 shrink-0 rounded-full border border-rule-strong px-3 text-xs text-ink-muted"
                                @click="openPicker(index)"
                            >
                                {{
                                    row.ingredientId === null
                                        ? 'Wskaż produkt'
                                        : 'Zmień'
                                }}
                            </button>
                        </div>

                        <!-- Pointing a line at a product: the same search as the
                             manual sheet, opened where the line is. -->
                        <div v-if="picking === index" class="mt-3">
                            <input
                                v-model="search"
                                type="search"
                                autocomplete="off"
                                placeholder="Szukaj produktu…"
                                class="h-11 w-full rounded-xl border border-rule-strong px-3 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                            />
                            <ul
                                v-if="matches.length > 0"
                                class="mt-1 space-y-1"
                            >
                                <li
                                    v-for="product in matches"
                                    :key="product.id"
                                >
                                    <button
                                        type="button"
                                        class="flex h-11 w-full items-center rounded-xl px-3 text-left text-ink hover:bg-paper-sunk"
                                        @click="attach(index, product)"
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
                                class="mt-2 text-sm text-ink-muted"
                            >
                                Nie znam takiego produktu.
                            </p>
                        </div>

                        <!-- Amount and shelf, on the rows that are going in. -->
                        <div
                            v-if="row.ingredientId !== null && row.include"
                            class="mt-3 flex flex-wrap items-center gap-2 pl-8"
                        >
                            <input
                                v-model.number="row.quantity"
                                type="number"
                                inputmode="decimal"
                                step="any"
                                min="0"
                                :placeholder="unitSymbol(row.unitId)"
                                :aria-label="`Ile: ${row.name}`"
                                class="h-11 w-20 rounded-xl border border-rule-strong px-2 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                            />
                            <select
                                v-model="row.unitId"
                                :aria-label="`Jednostka: ${row.name}`"
                                class="h-11 rounded-xl border border-rule-strong bg-paper-raised px-2 text-sm text-ink focus:border-accent focus:outline-none"
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
                            <select
                                v-model="row.location"
                                :aria-label="`Gdzie: ${row.name}`"
                                class="h-11 rounded-xl border border-rule-strong bg-paper-raised px-2 text-sm text-ink focus:border-accent focus:outline-none"
                            >
                                <option
                                    v-for="section in sections"
                                    :key="section.value"
                                    :value="section.value"
                                >
                                    {{ section.label }}
                                </option>
                            </select>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div class="border-t border-rule px-4 pb-safe sm:px-8">
            <div class="mx-auto flex max-w-3xl gap-2 py-3">
                <button
                    type="button"
                    class="h-13 flex-1 rounded-full bg-accent font-semibold text-ink disabled:opacity-60"
                    :disabled="chosen.length === 0 || saving"
                    @click="save"
                >
                    {{
                        saving
                            ? 'Zapisuję…'
                            : `Dodaj do kuchni (${chosen.length})`
                    }}
                </button>
                <button
                    type="button"
                    class="h-13 rounded-full border border-rule-strong px-5 text-ink-muted"
                    @click="close"
                >
                    Anuluj
                </button>
            </div>
        </div>
    </div>
</template>
