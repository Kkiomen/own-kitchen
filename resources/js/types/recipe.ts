export interface RecipeSummary {
    slug: string;
    title: string;
    imageUrl: string | null;
    servingsLabel: string | null;
    /** Often absent: only some sources publish it. */
    totalTimeMinutes: number | null;
    /** Set when the recipe is written for one device, e.g. an air fryer. */
    appliance: string | null;
    /** Cooked ahead in a batch and carried — a lunchbox dish. */
    isMealPrep: boolean;
    ingredientCount: number;
    stepCount: number;
    needsReview: boolean;
    /** Slugs of the quick-pick categories this recipe fell into. */
    categories: string[];
    /** How many products you would still have to buy. Zero means cook it tonight. */
    missing: number;
    /**
     * Products in the kitchen with a use-by date on them that this recipe uses.
     * Named rather than counted: "Ser żółty" is a reason to cook a dish, "1
     * rzecz" is a puzzle.
     */
    expiring: string[];
}

export interface RecipeCategory {
    slug: string;
    name: string;
    icon: string | null;
    count: number;
}

/**
 * How one line stands against the kitchen. `assumed` is "not on a shelf, but
 * nothing to buy either" — salt, oil, an optional line — and `unknown` is a line
 * the importer never resolved to a product, which cannot be checked at all.
 */
export type IngredientStatus =
    'held' | 'not_enough' | 'absent' | 'assumed' | 'unknown';

export interface IngredientLine {
    id: number;
    quantity: number | null;
    quantityMax: number | null;
    unit: string | null;
    unitCode: string | null;
    ingredient: string | null;
    category: string | null;
    emoji: string;
    status: IngredientStatus;
    note: string | null;
    section: string | null;
    rawText: string;
    isOptional: boolean;
    needsReview: boolean;
}

export interface RecipeStep {
    position: number;
    section: string | null;
    instruction: string;
    action: string | null;
    appliance: string | null;
    temperatureCelsius: number | null;
    durationSeconds: number | null;
    needsReview: boolean;
    uses: IngredientLine[];
}

/**
 * What the cooking screen is given. Deliberately not `RecipeDetail`: standing at
 * the hob, nothing about the source, the tags or the state of the fridge is a
 * question — and asking the pantry about every line would be work done for none
 * of them.
 */
export interface CookIngredient {
    id: number;
    /** Null when the importer never resolved the line; `rawText` carries it. */
    name: string | null;
    emoji: string;
    quantity: number | null;
    quantityMax: number | null;
    unit: string | null;
    note: string | null;
    rawText: string;
}

export interface CookStep {
    position: number;
    section: string | null;
    instruction: string;
    action: string | null;
    appliance: string | null;
    temperatureCelsius: number | null;
    /** The step's own stated time — what the timer is offered for. */
    durationSeconds: number | null;
    uses: CookIngredient[];
}

export interface CookRecipe {
    slug: string;
    title: string;
    servingsLabel: string | null;
    /** How many portions the amounts on screen are for, when the source said. */
    servings: number | null;
    isScaled: boolean;
    appliance: string | null;
    steps: CookStep[];
}

/**
 * How many portions the amounts on screen are for.
 *
 * `base` is what the source wrote the recipe for and is null for the ~78
 * recipes that never said — the control is not offered at all then, because
 * there is nothing to scale from.
 */
export interface RecipeScale {
    base: number | null;
    servings: number | null;
    isScaled: boolean;
}

export interface RecipeDetail {
    slug: string;
    scale: RecipeScale;
    title: string;
    description: string | null;
    imageUrl: string | null;
    servings: number | null;
    servingsLabel: string | null;
    totalTimeMinutes: number | null;
    appliance: string | null;
    isMealPrep: boolean;
    sourceName: string;
    sourceUrl: string | null;
    importedAt: string | null;
    needsReview: boolean;
    /** Whether the kitchen holds anything to check the lines against. */
    hasPantry: boolean;
    tags: string[];
    ingredients: IngredientLine[];
    steps: RecipeStep[];
}
