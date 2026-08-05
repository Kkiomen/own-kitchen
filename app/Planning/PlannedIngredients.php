<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Pantry\Pantry;
use App\Pantry\RecipeAvailability;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;
use Illuminate\Support\Collection;

/**
 * What a set of planned days actually needs, added up product by product.
 *
 * Two rules do the work here, and both come from how the cooking really happens
 * in this household — one big session, eaten over several days.
 *
 * **A recipe planned three times is cooked once, for the portions of all three.**
 * So entries are grouped by recipe and their portions summed *before* anything is
 * multiplied. Scaling each meal on its own and adding the results would buy the
 * same pot of stew three times over.
 *
 * **The scale is portions, not meals.** A recipe written for four, planned as two
 * portions on Monday and two on Wednesday, is one batch as written — factor 1.
 * The same recipe planned for eight portions is factor 2, and its shopping is
 * doubled.
 */
final class PlannedIngredients
{
    public function __construct(
        private readonly RecipeAvailability $availability,
        private readonly MeasureBook $measures,
    ) {}

    /**
     * @param  Collection<int, MealPlanEntry>  $entries
     */
    public function of(Collection $entries): PlannedNeeds
    {
        $amounts = [];
        $unresolved = 0;
        $unscaled = [];
        $wholeBatches = [];
        $meals = 0;
        $notes = 0;

        foreach ($this->batches($entries) as $batch) {
            /** @var Recipe $recipe */
            $recipe = $batch['recipe'];
            $meals += $batch['entries'];

            $factor = $this->factorFor($recipe, $batch['servings']);

            if ($factor === null) {
                $unscaled[] = $recipe->title;
            }

            // Fewer portions planned than one cooking makes: the pot is bought
            // whole, and saying so is the difference between leftovers you
            // expected and shopping that quietly did not add up.
            if ($factor !== null && $recipe->servings > $batch['servings']) {
                $wholeBatches[] = $recipe->title.' (przepis na '.$recipe->servings.')';
            }

            foreach ($recipe->ingredients as $line) {
                if ($this->availability->isAssumedAtHand($line)) {
                    continue;
                }

                if ($line->ingredient === null) {
                    // Nothing to write down: a line of raw text on a shopping
                    // list is exactly the duplication the catalogue prevents.
                    $unresolved++;

                    continue;
                }

                $wanted = $line->toQuantity()?->multipliedBy($factor ?? 1.0);
                $id = $line->ingredient->id;

                /*
                 * `null` stays infectious: one line asking for an unstated amount
                 * of chicken makes the week's chicken unknown, and the list then
                 * says "kurczak" with no number rather than a wrong one.
                 */
                $amounts[$id] = array_key_exists($id, $amounts)
                    ? $this->measures->for($id)->sum($amounts[$id], $wanted)
                    : $wanted;
            }
        }

        foreach ($entries as $entry) {
            if ($entry->isNote()) {
                $notes++;
            }
        }

        return new PlannedNeeds($amounts, $unresolved, $unscaled, $wholeBatches, $meals, $notes);
    }

    /**
     * One cooking session per recipe, carrying the portions every entry asked for.
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return list<array{recipe: Recipe, servings: int, entries: int}>
     */
    private function batches(Collection $entries): array
    {
        $batches = [];

        foreach ($entries as $entry) {
            $recipe = $entry->recipe;

            if ($recipe === null) {
                continue;
            }

            $batches[$recipe->id] ??= ['recipe' => $recipe, 'servings' => 0, 'entries' => 0];
            $batches[$recipe->id]['servings'] += $entry->servings ?? MealPlan::DEFAULT_SERVINGS;
            $batches[$recipe->id]['entries']++;
        }

        return array_values($batches);
    }

    /**
     * How many times over this recipe has to be cooked.
     *
     * Null when the recipe never said how many portions it makes — 78 of ~10 200
     * do not. There is no honest factor then, so the amounts are used as written
     * and the caller says so on screen; inventing a portion size would silently
     * halve or double a week's shopping.
     *
     * **Never below one whole cooking.** Two portions planned from a recipe for
     * four is not half the shopping: it is half an egg, two-thirds of a tin and
     * a quarter of a jar of passata, none of which is a thing you can buy or a
     * thing anybody cooks. So the pot is made whole and the extra portions are
     * simply left over — the caller says which recipes that happened to, because
     * buying more than was asked for is exactly the kind of thing this app is
     * not allowed to do quietly. Above one batch it scales as usual: six
     * portions from a recipe for four is 1.5.
     */
    private function factorFor(Recipe $recipe, int $plannedServings): ?float
    {
        $yields = $recipe->servings;

        if ($yields === null || $yields <= 0) {
            return null;
        }

        return max(1.0, $plannedServings / $yields);
    }

    /**
     * What the week still has to buy, after the kitchen is counted **once**.
     *
     * Once, and that is the whole reason this class exists. Asking
     * `ShoppingList::addMissingFor()` for each planned recipe in turn reads the
     * pantry afresh every time, so two dishes both wanting 300 g of courgette
     * against 500 g held would each find themselves covered and the week would go
     * shopping 100 g short.
     *
     * Products the kitchen covers are absent from the result rather than present
     * with a null: a null here means what it means everywhere else in this app —
     * "some, amount unknown" — and it belongs on the list, not off it.
     *
     * @return array<int, Quantity|null> ingredient id => amount to buy
     */
    public function toBuy(PlannedNeeds $needs, Pantry $pantry): array
    {
        $buy = [];

        foreach ($needs->amounts as $ingredientId => $wanted) {
            if (! $pantry->has($ingredientId)) {
                $buy[$ingredientId] = $wanted;

                continue;
            }

            $measures = $pantry->measuresFor($ingredientId);
            $held = $pantry->amountOf($ingredientId);

            // The same three-answer rule one recipe is checked with: only a
            // definite "no" is a shortage. "Cannot say" leaves the shelf
            // trusted, rather than buying a second one of everything nobody
            // has weighed.
            if ($measures->covers($held, $wanted) !== false) {
                continue;
            }

            $shortfall = $measures->shortfall($held, $wanted);

            // Subtracting two measures of the same thing can land on a hair
            // above nothing. Nobody buys that.
            if ($shortfall !== null && $shortfall->amount <= self::NOTHING) {
                continue;
            }

            $buy[$ingredientId] = $shortfall;
        }

        return $buy;
    }

    private const float NOTHING = 0.0001;
}
