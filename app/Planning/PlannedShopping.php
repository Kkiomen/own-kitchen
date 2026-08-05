<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Pantry\Pantry;
use App\Shopping\Trolley;
use App\Support\Measurement\MeasureBook;

/**
 * The point of planning a week: the days you ticked become the shopping.
 *
 * Deliberately not a loop over `Trolley::addMissingFor()`. That method reads the
 * kitchen afresh for each recipe, so two dishes each wanting 300 g of courgette
 * against 500 g held would both find themselves covered and the week would shop
 * 100 g short. Here the week is added up first and the kitchen subtracted once,
 * from the total.
 */
final class PlannedShopping
{
    public function __construct(
        private readonly MealPlan $plan,
        private readonly PlannedIngredients $needs,
        private readonly PlanShoppingList $lists,
        private readonly Trolley $trolley,
        private readonly MeasureBook $measures,
    ) {}

    /**
     * Write down what the chosen days need and the kitchen cannot supply.
     *
     * It goes on **its own list, named after the days** — "3–9 sierpnia" — and
     * not on the standing one. A week's shopping is one trip with a beginning
     * and an end; folded into the main list, neither can be read.
     *
     * Re-runnable: the same days always mean the same list, and its untouched
     * lines are rebuilt from what the plan says *now*. Swap a dish, press the
     * button again, and the list follows.
     *
     * @param  list<string>  $dates
     * @param  ShoppingList|null  $list  write to this one instead of making a new
     *                                   one; the screens that offer that pass it
     * @return array{added: int, kept: int, unknown: int, notes: int, unscaled: list<string>, wholeBatches: list<string>, meals: int, list: string, listId: int}
     */
    public function addMissing(User $user, array $dates, ?ShoppingList $list = null): array
    {
        $needs = $this->needs->of($this->plan->entriesOn($user, $dates));
        $toBuy = $this->needs->toBuy($needs, Pantry::of($user, $this->measures));

        $list ??= $this->lists->for($user, $dates);
        $alreadyBought = $this->clearUnbought($list);

        $added = 0;

        foreach ($toBuy as $ingredientId => $amount) {
            /*
             * Something ticked off in the shop stays ticked off, and is not
             * asked for a second time: you already have it, even though the
             * kitchen will not know that until the trolley is unpacked. Adding
             * to it would also un-tick it, which is the opposite of helpful
             * halfway down an aisle.
             */
            if (isset($alreadyBought[$ingredientId])) {
                continue;
            }

            $this->trolley->add($list, $ingredientId, $amount);
            $added++;
        }

        return [
            'added' => $added,
            'kept' => count($alreadyBought),
            'unknown' => $needs->unresolvedLines,
            'notes' => $needs->notes,
            'unscaled' => $needs->unscaledRecipes,
            'wholeBatches' => $needs->wholeBatches,
            'meals' => $needs->meals,
            // The list it went on: "dopisano 12" is only half the answer when a
            // household keeps several, and this one was just invented.
            'list' => $list->name,
            'listId' => $list->id,
        ];
    }

    /**
     * Empty the list of everything not yet in the trolley, and report what was.
     *
     * This is what makes the button re-runnable, which is how it is really used:
     * a dish gets swapped, the button gets pressed again, and the list has to
     * become what the plan now says — not the old plan plus the new one. Lines
     * for a dish that is no longer planned would otherwise sit there for ever,
     * and nobody standing in a shop can tell which of them still belongs.
     *
     * **Ticked-off lines survive**, and so does anything hand-written that has
     * been ticked. Only the untouched part is rebuilt.
     *
     * @return array<int, true> ingredient ids already in the trolley
     */
    private function clearUnbought(ShoppingList $list): array
    {
        $bought = [];

        $items = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            ->get(['id', 'ingredient_id', 'bought_at']);

        foreach ($items as $item) {
            if ($item->isBought()) {
                $bought[$item->ingredient_id] = true;

                continue;
            }

            $item->delete();
        }

        return $bought;
    }
}
