import type { IconName } from '@/lib/icons';
import { formatNumber } from '@/lib/quantity';
import type { IngredientLine, IngredientStatus } from '@/types/recipe';

export interface StockMarker {
    icon: IconName;
    /** Always set: it is the icon's accessible name even when nothing is drawn. */
    label: string;
    /**
     * Whether the label is drawn beside the icon. The good news does not need
     * words — a green tick is read at a glance, and on a list of forty lines the
     * repeated "masz" is what makes the ones you lack hard to find.
     */
    showLabel: boolean;
    /** A theme token class — the palette has no red, so a shortage is `flag`. */
    tone: string;
}

/**
 * What each line says about the kitchen. Four of the five speak up:
 *
 * - `held` is a bare tick in the accent, no word beside it.
 * - `absent` and `not_enough` are what you have to fetch, in the muted amber the
 *   rest of the app already uses for "look at this". These are spelled out: the
 *   whole point of the pass is that the gaps are the findable thing.
 * - `assumed` says the same thing faintly: the salt is not written down anywhere
 *   and the shopping list will not ask for it either, so it must not look like a
 *   shortage — but staying silent would leave a hole in a column of answers.
 * - `unknown` says nothing at all. The importer never resolved the line to a
 *   product, so there is no shelf to check it against, and the line already
 *   carries its own "sprawdź".
 */
const STOCK: Record<IngredientStatus, StockMarker | null> = {
    held: {
        icon: 'check',
        label: 'masz',
        showLabel: false,
        tone: 'text-accent-strong',
    },
    not_enough: {
        icon: 'partial',
        label: 'za mało',
        showLabel: true,
        tone: 'text-flag',
    },
    absent: {
        icon: 'cross',
        label: 'nie masz',
        showLabel: true,
        tone: 'text-flag',
    },
    assumed: {
        icon: 'cross',
        label: 'nie masz',
        showLabel: true,
        tone: 'text-ink-faint',
    },
    unknown: null,
};

export function stockMarker(status: IngredientStatus): StockMarker | null {
    return STOCK[status];
}

/**
 * Names for the tool a step happens in; the drawings live in ApplianceIcon.vue.
 * Keys mirror the Appliance enum, so an unmapped value shows its raw key rather
 * than silently vanishing.
 */
const APPLIANCES: Record<string, string> = {
    pan: 'patelnia',
    pot: 'garnek',
    oven: 'piekarnik',
    air_fryer: 'airfryer',
    mixer: 'mikser',
    blender: 'blender',
    bowl: 'miska',
    baking_tin: 'tortownica',
    baking_tray: 'blacha',
    fridge: 'lodówka',
    freezer: 'zamrażarka',
    microwave: 'mikrofalówka',
    grater: 'tarka',
    knife: 'nóż',
};

const ACTIONS: Record<string, string> = {
    prepare: 'PRZYGOTUJ',
    add: 'DODAJ',
    mix: 'WYMIESZAJ',
    blend: 'ZMIKSUJ',
    chop: 'POKRÓJ',
    heat: 'ROZGRZEJ',
    boil: 'GOTUJ',
    fry: 'SMAŻ',
    bake: 'PIECZ',
    chill: 'SCHŁÓDŹ',
    rest: 'ODSTAW',
    serve: 'PODAWAJ',
};

export function applianceLabel(appliance: string | null): string {
    if (appliance === null) {
        return '';
    }

    return APPLIANCES[appliance] ?? appliance;
}

export function actionLabel(action: string | null): string {
    if (action === null) {
        return 'KROK';
    }

    return ACTIONS[action] ?? action.toUpperCase();
}

export function formatDuration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/**
 * The measured part of a line, e.g. "150 g" or "1 - 2 łyżka". Empty when the
 * recipe gave no amount at all, which is legitimate for things like pepper.
 *
 * Takes the three fields it reads rather than a whole `IngredientLine`, so the
 * cooking screen — whose payload carries no pantry answers — formats amounts
 * through this one function too, instead of growing a second copy of it.
 */
export function formatAmount(
    line: Pick<IngredientLine, 'quantity' | 'quantityMax' | 'unit'>,
): string {
    if (line.quantity === null) {
        return '';
    }

    const amount =
        line.quantityMax === null
            ? formatNumber(line.quantity)
            : `${formatNumber(line.quantity)}–${formatNumber(line.quantityMax)}`;

    return line.unit === null ? amount : `${amount} ${line.unit}`;
}

/**
 * Groups lines under their section heading, preserving order. Recipes without
 * sections end up in a single unnamed group.
 */
export function groupBySection<T extends { section: string | null }>(
    items: T[],
): { section: string | null; items: T[] }[] {
    const groups: { section: string | null; items: T[] }[] = [];

    for (const item of items) {
        const last = groups[groups.length - 1];

        if (last !== undefined && last.section === item.section) {
            last.items.push(item);

            continue;
        }

        groups.push({ section: item.section, items: [item] });
    }

    return groups;
}
