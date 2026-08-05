<?php

declare(strict_types=1);

namespace App\Pantry;

use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is about to go off, and what could be cooked with it.
 *
 * The kitchen has recorded `expires_at` since it was built and has done nothing
 * with it beyond counting: "3 rzeczy tracą ważność w ciągu 3 dni" is a fact with
 * no action attached, which is a nag rather than a feature. The catalogue can
 * already answer "co ugotuję" — this narrows that question to the products with
 * a deadline on them.
 *
 * **Three days, the same three the kitchen already warns about.** A different
 * window here would put a number on the chip that contradicts the sentence on
 * the other screen, and the household would have to work out which one to
 * believe. `DAYS` is the single place it is stated.
 *
 * **An entry with no date is not expiring.** Most of the kitchen has no date on
 * it — the starter command deliberately writes none — and treating "unknown" as
 * "urgent" would make this the loudest thing in the app and the first thing
 * anyone turned off. Silence about what we do not know, exactly as everywhere
 * else.
 */
final class ExpiringSoon
{
    /**
     * How far ahead counts as "about to go off".
     *
     * Long enough to plan a dinner around, short enough that the answer is
     * about food and not about the whole fridge.
     */
    public const int DAYS = 3;

    /**
     * The products with a deadline on them, soonest first.
     *
     * @return array<int, string> ingredient id => product name
     */
    public function products(User $user): array
    {
        $rows = DB::table('pantry_items')
            ->join('ingredients', 'ingredients.id', '=', 'pantry_items.ingredient_id')
            ->where('pantry_items.user_id', $user->id)
            ->whereNotNull('pantry_items.expires_at')
            ->whereDate('pantry_items.expires_at', '<=', now()->addDays(self::DAYS)->toDateString())
            ->orderBy('pantry_items.expires_at')
            ->select(['ingredients.id', 'ingredients.name'])
            ->get();

        $products = [];

        foreach ($rows as $row) {
            // The same product on two shelves is two rows and one answer; the
            // first wins, which is the one going off soonest.
            $products[(int) $row->id] ??= (string) $row->name;
        }

        return $products;
    }

    /**
     * Which recipes use those products, and which ones each recipe uses.
     *
     * The names travel with the ids because the card has to say *what* is
     * running out. "Brakuje 0" plus a badge reading "Ser żółty" is a reason to
     * cook something; a badge reading "coś ci się psuje" is a puzzle.
     *
     * @param  array<int, string>  $products  as returned by `products()`
     * @param  list<int>|null  $recipeIds  narrowed to these, or the whole
     *                                     catalogue when null — the chip count
     *                                     needs every recipe, a page needs 36
     * @return array<int, list<string>> recipe id => the expiring products it uses
     */
    public function usedBy(array $products, ?array $recipeIds = null): array
    {
        if ($products === [] || $recipeIds === []) {
            return [];
        }

        $rows = DB::table('recipe_ingredients')
            ->whereIn('ingredient_id', array_keys($products))
            // An optional line is not a reason to cook a dish, and the shortfall
            // aggregate does not count one either.
            ->where('is_optional', false)
            ->when($recipeIds !== null, fn (Builder $query): Builder => $query->whereIn('recipe_id', $recipeIds))
            ->distinct()
            ->select(['recipe_id', 'ingredient_id'])
            ->get();

        $uses = [];

        foreach ($rows as $row) {
            $name = $products[(int) $row->ingredient_id] ?? null;

            if ($name !== null) {
                $uses[(int) $row->recipe_id][] = $name;
            }
        }

        return $uses;
    }

    /** Whether this kitchen has anything with a deadline on it at all. */
    public function exists(User $user): bool
    {
        return PantryItem::query()
            ->where('user_id', $user->id)
            ->expiringWithin(self::DAYS)
            ->exists();
    }
}
