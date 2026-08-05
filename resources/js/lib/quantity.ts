/**
 * The single place an amount becomes text. The kitchen, the shopping list and a
 * recipe's ingredient lines all read the same numbers, so they must all round
 * and spell them the same way.
 *
 * Fractions are the point. A recipe saying "1/3 szklanki" is stored as a float,
 * and SQLite does not honour the `decimal(10,3)` the migration declares — it
 * keeps whatever the sum produced. So the shopping list was reading
 * "0.3333333333 szkl. pomarańcza", which is not an amount anybody can shop for.
 * Rendering it as "⅓ szkl." says the same thing in the language a kitchen uses.
 */

const FRACTIONS: { value: number; glyph: string }[] = [
    { value: 1 / 4, glyph: '¼' },
    { value: 1 / 3, glyph: '⅓' },
    { value: 1 / 2, glyph: '½' },
    { value: 2 / 3, glyph: '⅔' },
    { value: 3 / 4, glyph: '¾' },
];

/**
 * How far off a number may be and still be called that fraction. Wide enough for
 * 0.333 and 0.33 alike, far narrower than the gap between any two of them.
 */
const TOLERANCE = 0.02;

/**
 * A number as a cook would write it: "2", "1 ½", "⅓", and only then "0,4".
 */
export function formatNumber(value: number): string {
    const rounded = Number(value.toFixed(2));
    const whole = Math.floor(rounded);
    const rest = rounded - whole;

    if (rest < TOLERANCE) {
        return String(whole);
    }

    if (rest > 1 - TOLERANCE) {
        return String(whole + 1);
    }

    const fraction = FRACTIONS.find(
        (candidate) => Math.abs(rest - candidate.value) < TOLERANCE,
    );

    if (fraction !== undefined) {
        return whole === 0 ? fraction.glyph : `${whole} ${fraction.glyph}`;
    }

    // Nothing familiar to round to, so say the number — with a Polish comma.
    return String(rounded).replace('.', ',');
}

/**
 * An amount with its unit, e.g. "½ szkl." or "150 g". Empty when there is no
 * amount, which means "some, quantity unknown" and must not be read as none.
 */
export function formatQuantity(
    quantity: number | null,
    unit: string | null,
): string {
    if (quantity === null) {
        return '';
    }

    const amount = formatNumber(quantity);

    return unit === null ? amount : `${amount} ${unit}`;
}

/**
 * How much one tap of a stepper moves an amount, by unit code.
 *
 * A step is not a claim about the product — unlike a prefilled amount, nothing
 * is written down until the number on screen says so. It only has to be the
 * jump a cook would make anyway: nobody adjusts flour by one gram, and nobody
 * buys half an onion. So the base units of mass and volume move in the amounts
 * shopping is actually done in, and everything countable moves by one.
 */
const STEPS: Record<string, number> = {
    g: 50,
    dag: 5,
    kg: 0.5,
    ml: 50,
    l: 0.5,
};

export function stepFor(unitCode: string | null): number {
    return unitCode === null ? 1 : (STEPS[unitCode] ?? 1);
}

/**
 * A step away from where we are, never below one step. Kept off the raw float
 * SQLite hands back, so ⅓ + one step is a number and not 1.3333333333.
 */
export function stepped(quantity: number | null, step: number): number {
    const from = quantity ?? 0;

    return Math.max(step, Number((from + step).toFixed(3)));
}

export function steppedDown(quantity: number | null, step: number): number {
    const from = quantity ?? 0;

    return Math.max(step, Number((from - step).toFixed(3)));
}
