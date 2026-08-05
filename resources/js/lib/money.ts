/**
 * The single place a price becomes text, for the same reason `quantity.ts` is
 * the single place an amount does: the plan, a shop's subtotal and the running
 * saving are the same numbers seen three times, and they have to be spelled
 * identically.
 *
 * Everything crossing from PHP is an integer count of grosze. That is deliberate
 * — a złoty amount as a float is exactly how a total of twelve prices renders as
 * "11,999999999 zł" on the one screen whose whole purpose is a number you trust.
 * Divide here, at the last possible moment, and only to print.
 */

/** "54,99 zł". Always two decimal places: prices are not rounded to "55 zł". */
export function formatMoney(grosze: number): string {
    return `${(grosze / 100).toFixed(2).replace('.', ',')} zł`;
}

/**
 * "12,49 zł/kg" — the only comparison between two offers that is actually true.
 * Empty when the pack size was never printed, because there is no honest figure
 * to show and a guessed one would be driving a real decision.
 */
export function formatUnitPrice(
    grosze: number | null,
    label: string | null,
): string {
    if (grosze === null || label === null) {
        return '';
    }

    return `${formatMoney(grosze)}/${label}`;
}

/**
 * "do 8 sierpnia". Null dates say nothing rather than "bezterminowo": the source
 * states a duration for most entries and stays quiet on the rest, and silence is
 * not a promise that the offer runs for ever.
 */
export function formatValidTo(date: string | null): string {
    if (date === null) {
        return '';
    }

    return `do ${new Date(date).toLocaleDateString('pl-PL', {
        day: 'numeric',
        month: 'long',
    })}`;
}
