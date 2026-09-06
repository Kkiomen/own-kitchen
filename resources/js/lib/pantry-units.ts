/**
 * Which measures a unit picker offers first.
 *
 * The vocabulary is closed — twenty-one measures, and none of them free text —
 * but a closed list is still the whole list on screen, which is how a tub of
 * yoghurt ends up recorded in ząbki. `App\Catalogue\IngredientUnits` works out,
 * per product, the measures the catalogue actually uses for it; this is the
 * browser half of that, and the only place the two groups are named.
 *
 * Nothing is hidden. The shortlist is the first group and the rest of the
 * vocabulary is the second, because a shelf occasionally holds something no
 * recipe ever measured that way. A product the catalogue has never measured
 * sends an empty shortlist and gets one ungrouped list, exactly as before.
 */

export interface PantryUnit {
    id: number;
    code: string;
    symbol: string;
    name: string;
}

/** As much of a product as a unit picker needs to know about it. */
export interface UnitsOfProduct {
    unitIds: number[];
    unconvertibleUnitIds: number[];
}

export interface UnitGroup {
    label: string;
    units: PantryUnit[];
}

export const SUGGESTED = 'Zwykle w przepisach';
export const OTHER = 'Pozostałe';

/**
 * The picker's contents for one product, or one plain group when the product
 * has no opinion.
 */
export function unitGroupsFor(
    units: PantryUnit[],
    product: UnitsOfProduct | null,
): UnitGroup[] {
    const shortlist = product?.unitIds ?? [];

    if (shortlist.length === 0) {
        return [{ label: '', units }];
    }

    // The server's order is the useful one — commonest measure first — so the
    // shortlist is walked rather than filtered out of the vocabulary.
    const suggested = shortlist
        .map((id) => units.find((unit) => unit.id === id))
        .filter((unit): unit is PantryUnit => unit !== undefined);

    const rest = units.filter((unit) => !shortlist.includes(unit.id));

    return [
        { label: SUGGESTED, units: suggested },
        { label: OTHER, units: rest },
    ];
}

/**
 * Whether an amount in this measure can be lined up against a recipe's.
 *
 * False is not an error and must not read as one: holding two słoiki of
 * something is a fact worth writing down. It is just a fact nothing can compare
 * with "200 g", because nobody has recorded what one of them weighs — and
 * saying so is the same "cannot tell" rule `IngredientMeasures::covers()` keeps
 * on the server, moved to where somebody is choosing.
 */
export function converts(
    product: UnitsOfProduct | null,
    unitId: number | null,
): boolean {
    if (product === null || unitId === null) {
        return true;
    }

    // Only the offered measures were checked; anything from the second group is
    // unknown territory rather than known-bad, and nagging about it would be
    // guessing.
    if (!product.unitIds.includes(unitId)) {
        return true;
    }

    return !product.unconvertibleUnitIds.includes(unitId);
}

/** The measure a photograph counts in. */
export function pieceUnit(units: PantryUnit[]): PantryUnit | undefined {
    return units.find((unit) => unit.code === 'piece');
}
