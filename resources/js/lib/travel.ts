/**
 * Every word and every number the travel screen prints, in one file.
 *
 * The API deliberately serves codes rather than copy — `round_trip`, `breakfast`
 * — so this is the only place the Polish for them exists, exactly as
 * `recipe-display.ts` is for a recipe and `money.ts` for a price. Change the
 * wording here and it changes on the card, in the details sheet and in the
 * filter bar at once.
 */

import type { Deal, DealDates } from '@/types/travel';

/** A code we have no word for is shown as itself, never as "nieznany". */
function labelled(labels: Record<string, string>, code: string | null): string {
    if (code === null) {
        return '';
    }

    return labels[code] ?? code;
}

const TYPE_LABELS: Record<string, string> = {
    flight: 'W jedną stronę',
    round_trip: 'Tam i z powrotem',
    trip: 'Wyjazd z pakietem',
};

/** Shorter, for a chip that sits beside a price. */
const TYPE_CHIPS: Record<string, string> = {
    flight: 'w jedną stronę',
    round_trip: 'tam i z powrotem',
    trip: 'pakiet',
};

const SORT_LABELS: Record<string, string> = {
    score: 'Najlepsze',
    price: 'Najtańsze',
    newest: 'Najnowsze',
};

const BOARD_LABELS: Record<string, string> = {
    all_inclusive: 'All inclusive',
    full_board: 'Pełne wyżywienie',
    half_board: 'Dwa posiłki',
    breakfast: 'Ze śniadaniem',
    room_only: 'Bez wyżywienia',
};

/**
 * Several of the sources are the same airline asked a different way, so they are
 * collapsed to the name a person would say.
 */
const SOURCE_LABELS: Record<string, string> = {
    ryanair: 'Ryanair',
    'ryanair-return': 'Ryanair',
    'ryanair-pairs': 'Ryanair',
    wizzair: 'Wizz Air',
    fly4free: 'Fly4free',
    'wakacyjni-piraci': 'Wakacyjni Piraci',
};

export function typeLabel(type: string | null): string {
    return labelled(TYPE_LABELS, type);
}

export function typeChip(type: string | null): string {
    return labelled(TYPE_CHIPS, type);
}

export function sortLabel(sort: string | null): string {
    return labelled(SORT_LABELS, sort);
}

export function boardLabel(board: string | null): string {
    return labelled(BOARD_LABELS, board);
}

export function sourceLabel(source: string): string {
    return labelled(SOURCE_LABELS, source);
}

/**
 * A price, in the currency the deal itself states.
 *
 * It arrives as integer grosze for the reason `money.ts` explains, but unlike
 * the shopping list this one carries its own currency code — the deals app says
 * PLN today and says so per offer, so the code is honoured rather than assumed.
 */
export function formatPrice(minorUnits: number, currency: string): string {
    return new Intl.NumberFormat('pl-PL', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(minorUnits / 100);
}

/** "1 noc" / "3 noce" / "8 nocy" — Polish counts three ways, not two. */
export function nightsLabel(nights: number): string {
    if (nights === 1) {
        return '1 noc';
    }

    const tens = nights % 100;
    const units = nights % 10;
    const few = units >= 2 && units <= 4 && (tens < 12 || tens > 14);

    return `${nights} ${few ? 'noce' : 'nocy'}`;
}

/**
 * The day a departure belongs to.
 *
 * **Converted, never sliced.** A flight carries an instant
 * ("2026-10-29T22:30:00+00:00") and cutting the first ten characters out of it
 * puts a 22:30 UTC departure on the wrong day — the one thing a screen about
 * dates may not get wrong.
 */
export function formatDay(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return new Date(iso).toLocaleDateString('pl-PL', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

/** The clock beside the day, for a flight that has one. */
export function formatTime(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return new Date(iso).toLocaleTimeString('pl-PL', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * "pt 12 wrz → nd 14 wrz". The arrow rather than a dash, because the two ends
 * are a journey and not a range of prices.
 */
export function formatJourney(deal: Deal): string {
    const out = formatDay(deal.departsAt);

    if (out === '') {
        return '';
    }

    const back = formatDay(deal.returnsAt);

    return back === '' ? out : `${out} → ${back}`;
}

/**
 * One term an article names. Its own Polish wording is preferred — the article
 * wrote "długi weekend majowy" and no reformatting of two dates says that.
 */
export function formatDates(dates: DealDates): string {
    if (dates.label !== '') {
        return dates.label;
    }

    const from = formatDay(dates.from);
    const to = formatDay(dates.to);

    if (from === '') {
        return '';
    }

    return to === '' || to === from ? from : `${from} – ${to}`;
}

/**
 * Whether an offer is worth the eye, as a word.
 *
 * A verdict never rests on colour alone — that is the rule the deals app follows
 * and this one keeps: the ring is coloured *and* labelled, so it survives being
 * read outdoors, in greyscale, or by somebody who does not see the difference.
 */
export function scoreVerdict(score: number, good: number): string {
    if (score >= good) {
        return 'okazja';
    }

    return score >= good - 20 ? 'nieźle' : 'przeciętnie';
}
