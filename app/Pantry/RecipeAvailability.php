<?php

declare(strict_types=1);

namespace App\Pantry;

use App\Enums\IngredientCategory;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Support\Measurement\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what can I cook from what I have?".
 *
 * Two passes, because the two questions have different costs. Counting how many
 * products a recipe is short of is a single aggregate over ~30 000 ingredient
 * lines, so it happens in the database for the whole catalogue at once. Checking
 * whether the *amount* is enough needs the unit conversions in `Quantity` and the
 * per-product weights in `IngredientMeasures`, so it happens in PHP and only for
 * the recipe actually being looked at.
 */
final class RecipeAvailability
{
    /**
     * How many products each recipe is short of, keyed by recipe id.
     *
     * A line is *not* counted as missing when it is optional, when it is
     * equipment rather than food, or when it is a seasoning — spices and fats,
     * which are assumed to be in the cupboard whether or not anybody wrote them
     * down. Seasoning is judged by **category**, not only by the `is_staple`
     * column: that column is written by the dictionary seeder and is therefore
     * false for every product the importer invented, so "przyprawa gyros" used
     * to hide an otherwise cookable recipe. A line *is* counted when the
     * importer never recognised the product at all: we cannot claim to have
     * something we cannot name.
     *
     * @param  list<int>|null  $recipeIds  narrowed to these recipes, or the whole
     *                                     catalogue when null. The browsing
     *                                     filters need every recipe; a week of
     *                                     meals needs a dozen, and scanning
     *                                     ~98 000 lines to answer for a dozen is
     *                                     a hundredfold too much work.
     * @return array<int, int>
     */
    public function missingCounts(User $user, ?array $recipeIds = null): array
    {
        if ($recipeIds === []) {
            return [];
        }

        /*
         * Distinct because one product may sit on two shelves — butter in the
         * fridge and in the freezer are two rows, and without this the left join
         * fans the recipe's line out into two. The sum survives that (both
         * copies score zero), but any later aggregate over the same join would
         * not, and a query that is only accidentally right is a trap.
         */
        $held = DB::table('pantry_items')
            ->select('ingredient_id')
            ->distinct()
            ->where('user_id', $user->id);

        $rows = DB::table('recipe_ingredients as line')
            ->leftJoin('ingredients as product', 'product.id', '=', 'line.ingredient_id')
            ->leftJoinSub($held, 'held', 'held.ingredient_id', '=', 'line.ingredient_id')
            ->when($recipeIds !== null, fn ($query) => $query->whereIn('line.recipe_id', $recipeIds))
            ->where('line.is_optional', false)
            ->where(function ($query): void {
                $query->whereNull('product.category')
                    ->orWhere('product.category', '!=', IngredientCategory::Equipment->value);
            })
            ->groupBy('line.recipe_id')
            /*
             * The three placeholders are spelled out rather than generated: the
             * expression has to stay a literal string, and a concatenated one
             * would also be a place to accidentally interpolate a value. The
             * coupling to the enum is pinned by
             * `RecipeAvailabilityTest::test_the_seasoning_rule_matches_the_query`,
             * which fails loudly if a fourth seasoning category is ever added.
             */
            ->selectRaw(
                'line.recipe_id as recipe_id, sum(case
                when line.ingredient_id is null then 1
                when product.is_staple = 1 then 0
                when product.category in (?, ?, ?) then 0
                when held.ingredient_id is not null then 0
                else 1 end) as missing',
                IngredientCategory::assumedAtHand(),
            )
            ->pluck('missing', 'recipe_id');

        $counts = [];

        foreach ($rows as $recipeId => $missing) {
            $counts[(int) $recipeId] = (int) $missing;
        }

        return $counts;
    }

    /**
     * The lines a recipe is short of, refined with amounts.
     *
     * This is what a shopping list is built from, so it reports *why* — the
     * product is absent, or it is there but there is not enough of it — and
     * *how much*: the whole amount when there is none, only the difference when
     * some is already in the kitchen. `quantity` is null whenever no honest
     * figure exists, which the list shows as a product with no amount.
     *
     * @return list<array{line: RecipeIngredient, reason: string, quantity: Quantity|null}>
     */
    public function missingFor(Recipe $recipe, Pantry $pantry): array
    {
        $missing = [];

        foreach ($recipe->ingredients as $line) {
            $reason = $this->shortfall($line, $pantry);

            if ($reason !== null) {
                $missing[] = [
                    'line' => $line,
                    'reason' => $reason,
                    'quantity' => $this->amountToBuy($line, $pantry, $reason),
                ];
            }
        }

        return $missing;
    }

    /**
     * The lines the kitchen already covers — exactly what `missingFor()` left
     * off the list *because it is on a shelf*.
     *
     * The two together are not the whole recipe, and that is the point: a line
     * exempt by policy (optional, equipment, seasoning) is not here either, so
     * offering these never puts a jar of paprika in the trolley. They carry the
     * full amount the recipe asks for, because someone reaching for this has
     * decided the shelf is not to be trusted — subtracting from an amount that
     * is in doubt would be the same guess in a smaller disguise.
     *
     * @return list<array{line: RecipeIngredient, quantity: Quantity|null}>
     */
    public function heldFor(Recipe $recipe, Pantry $pantry): array
    {
        $held = [];

        foreach ($recipe->ingredients as $line) {
            if ($this->isAssumedAtHand($line) || $line->ingredient === null) {
                continue;
            }

            if ($this->stock($line, $pantry) !== 'held') {
                continue;
            }

            $held[] = ['line' => $line, 'quantity' => $line->toQuantity()];
        }

        return $held;
    }

    /**
     * How much of this line still has to be bought.
     */
    private function amountToBuy(RecipeIngredient $line, Pantry $pantry, string $reason): ?Quantity
    {
        $required = $line->toQuantity();

        if ($required === null || $line->ingredient === null) {
            return null;
        }

        if ($reason !== 'not_enough') {
            return $required;
        }

        // `not_enough` is only ever reported when both sides are comparable, but
        // "comparable" may have taken a conversion to establish — 2 cebule against
        // 300 g. Subtracting takes the same route, and the answer comes back in
        // the unit the recipe asked in, because that is what the list will show.
        // The meal plan subtracts its whole week the same way, so the arithmetic
        // lives on the measures rather than being written out twice.
        return $pantry->measuresFor($line->ingredient->id)->shortfall(
            $pantry->amountOf($line->ingredient->id),
            $required,
        );
    }

    /**
     * How one line stands against the kitchen, for a screen rather than a list.
     *
     * The shopping list asks a narrower question — it only wants what has to be
     * bought — so it never hears about a line that is covered, and it stays
     * silent about seasoning it deliberately never buys. A cook reading a recipe
     * wants the other half of that answer too: whether each product is there.
     * Hence the extra case `shortfall()` has no use for. `assumed` is "not on a
     * shelf, but nothing to buy either" — an optional line, a tool, or the salt
     * and oil the whole app takes for granted — and it is a separate answer from
     * `absent` so the screen can say it quietly instead of raising an alarm.
     *
     * @return 'held'|'not_enough'|'absent'|'assumed'|'unknown'
     */
    public function statusFor(RecipeIngredient $line, Pantry $pantry): string
    {
        $stock = $this->stock($line, $pantry);

        if ($stock === 'absent' && $this->isAssumedAtHand($line)) {
            return 'assumed';
        }

        return $stock;
    }

    /**
     * `null` when the line is covered; otherwise why it is not.
     */
    private function shortfall(RecipeIngredient $line, Pantry $pantry): ?string
    {
        if ($this->isAssumedAtHand($line)) {
            return null;
        }

        $stock = $this->stock($line, $pantry);

        return $stock === 'held' ? null : $stock;
    }

    /**
     * Whether this line is one the shopping list never asks for, however empty
     * the kitchen is.
     *
     * The same three exemptions `missingCounts()` applies, and they have to stay
     * the same three: this is what the shopping list is built from, so a rule
     * that held only in the aggregate would let a recipe read "masz wszystko"
     * and then put a jar of paprika in the trolley.
     *
     * Public because a week of meals has to answer it too, and a second copy of
     * this rule in the planner would drift from this one the first time a fourth
     * exemption is argued about.
     */
    public function isAssumedAtHand(RecipeIngredient $line): bool
    {
        if ($line->is_optional) {
            return true;
        }

        $ingredient = $line->ingredient;

        if ($ingredient === null) {
            return false;
        }

        return $ingredient->category === IngredientCategory::Equipment
            || $ingredient->is_staple
            || $ingredient->category->isSeasoning();
    }

    /**
     * What the shelves say about this line, before any exemption is applied.
     *
     * @return 'held'|'not_enough'|'absent'|'unknown'
     */
    private function stock(RecipeIngredient $line, Pantry $pantry): string
    {
        $ingredient = $line->ingredient;

        if ($ingredient === null) {
            // An unrecognised line cannot be matched against anything held.
            return 'unknown';
        }

        if (! $pantry->has($ingredient->id)) {
            return 'absent';
        }

        /*
         * Three answers, not two. `covers()` says yes, no, or "cannot tell" — and
         * "cannot tell" must stay silent rather than become a shortage. It now
         * says yes or no far more often, because a weight for the product bridges
         * "2 cebule" and "300 g"; before, every such pair was a shrug.
         */
        return $pantry->measuresFor($ingredient->id)->covers(
            $pantry->amountOf($ingredient->id),
            $line->toQuantity(),
        ) === false ? 'not_enough' : 'held';
    }
}
