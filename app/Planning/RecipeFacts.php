<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\Recipe;
use App\Nutrition\RecipeNutrition;
use App\Pantry\RecipeAvailability;
use App\Pricing\IngredientCost;
use App\Support\Money\Money;

/**
 * What one portion of a recipe is worth in calories and in money.
 *
 * The two facts a targeted plan chooses on, answered for a whole pool at once.
 * Asked recipe by recipe this would be a query per dish per slot per day — a few
 * thousand for one week — so the lines come back in one read and both books
 * answer from memory.
 *
 * **Both figures are nullable and mean different things by it.** No calories
 * means the dish cannot be used to hit a calorie target at all. No cost means
 * only that nothing prices it: the dish is still perfectly cookable, and a
 * planner that refused those would quietly narrow a catalogue of ten thousand
 * down to whatever happened to appear in a shop leaflet. So the calorie is a
 * requirement and the price is a preference — which is the honest shape of what
 * we know.
 */
final readonly class RecipeFacts
{
    public function __construct(
        private RecipeNutrition $nutrition,
        private IngredientCost $costs,
        private RecipeAvailability $availability,
    ) {}

    /**
     * @param  list<int>  $recipeIds
     * @return array<int, RecipeFact>
     */
    public function forRecipes(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }

        $recipes = Recipe::query()
            ->whereIn('id', $recipeIds)
            ->with(['ingredients.ingredient', 'ingredients.unit'])
            ->get(['id', 'servings']);

        $facts = [];

        foreach ($recipes as $recipe) {
            $energy = $this->nutrition->for($recipe);

            $facts[$recipe->id] = new RecipeFact(
                kcalPerPortion: $energy->reliableKcalPerPortion(),
                costPerPortion: $this->costPerPortion($recipe),
                proteinPerPortion: $energy->isReliable() ? $energy->perPortion?->protein : null,
                realIngredients: $this->realIngredients($recipe),
                dominantIngredientId: $energy->dominantIngredientId,
            );
        }

        return $facts;
    }

    /**
     * How many things this dish is actually built from.
     *
     * Seasoning and water do not count, on the same rule the shopping uses, and
     * that is what makes the number mean something: "Jak ugotować kaszę bulgur"
     * is groats, water and salt, which is one ingredient and a set of
     * instructions rather than a meal. On the live catalogue **236 recipes
     * tagged as a meal have two or fewer** — chipsy, frytki, grzanki, "Jajko w
     * koszulce" — and 63 of them have exactly one.
     *
     * A structural test rather than a title one, deliberately. "Jak zrobić leczo
     * z cukinii" and "Jak zrobić pierogi z kapustą" open the same way and are
     * dinner; what separates them from the bulgur is what is in the pot.
     */
    private function realIngredients(Recipe $recipe): int
    {
        $products = [];

        foreach ($recipe->ingredients as $line) {
            if ($line->ingredient_id === null || $this->availability->isAssumedAtHand($line)) {
                continue;
            }

            // Water is in nearly every pot and is not something a dish is made of.
            if ($line->ingredient?->slug === self::WATER) {
                continue;
            }

            $products[$line->ingredient_id] = true;
        }

        return count($products);
    }

    private const string WATER = 'woda';

    /**
     * How much of a dish has to have a price before its total is worth quoting.
     *
     * Two thirds. Roughly a third of what a week calls for has no reading behind
     * it, so demanding all of it would silence nearly everything; accepting any
     * of it prices a steak by its garnish.
     */
    private const float PRICED_ENOUGH = 0.66;

    /**
     * Roughly what one portion's ingredients cost.
     *
     * Deliberately rough, and deliberately **not** the number the shopping
     * estimate will produce. This one is asked of hundreds of candidates to rank
     * them against each other, so it prices the recipe as written and divides;
     * the shopping list prices what is actually still to buy, in packs, after
     * the kitchen has been subtracted. Ranking is a comparison and only has to be
     * fair between dishes — which is why the unpriced lines below are skipped
     * rather than made to disqualify a recipe.
     */
    private function costPerPortion(Recipe $recipe): ?Money
    {
        $total = new Money(0);
        $priced = 0;
        $wanted = 0;

        foreach ($recipe->ingredients as $line) {
            /*
             * The same exemptions the week's shopping applies, shared rather
             * than copied — `RecipeAvailability::isAssumedAtHand()` is public
             * for exactly this. Pricing the salt and the oil here made every
             * candidate look dearer than the week it ends up in: a week costed
             * at 341 zł was being planned against an internal estimate that had
             * already spent 350, so meal after meal was reported as unaffordable
             * while the total came in comfortably under.
             */
            if ($line->ingredient_id === null || $this->availability->isAssumedAtHand($line)) {
                continue;
            }

            $wanted++;

            $cost = $this->costs->of($line->ingredient_id, $line->toQuantity());

            if ($cost === null) {
                continue;
            }

            $total = $total->plus($cost);
            $priced++;
        }

        /*
         * A price built from a minority of a dish is not a price. Beef
         * tenderloin came back at 4,89 zł in the alternatives sheet because the
         * beef itself had no reading and the onion did — a number that looks
         * authoritative and is off by a factor of ten. Below the floor the
         * answer is silence, which every caller already handles: the sheet omits
         * the figure and the budget ranking treats the dish as neither cheap nor
         * dear.
         */
        if ($wanted === 0 || $priced / $wanted < self::PRICED_ENOUGH) {
            return null;
        }

        $servings = $recipe->servings === null || $recipe->servings < 1 ? 1 : $recipe->servings;

        return $total->scaledBy(1 / $servings);
    }
}
