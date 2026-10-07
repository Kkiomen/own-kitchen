<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\Appliance;
use App\Enums\IngredientCategory;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Nutrition\RecipeNutrition;
use App\Pantry\RecipeAvailability;
use App\Pricing\IngredientCost;
use App\Support\Measurement\MeasureBook;
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
        private Season $season,
        private DishFamily $families,
        private MeasureBook $measures,
    ) {}

    /**
     * @param  list<int>  $recipeIds
     * @param  ShopOffers|null  $offers  the shop the week is being bought in, when
     *                                   somebody said which — its offers then price
     *                                   the lines they cover
     * @return array<int, RecipeFact>
     */
    public function forRecipes(array $recipeIds, ?ShopOffers $offers = null): array
    {
        if ($recipeIds === []) {
            return [];
        }

        $recipes = Recipe::query()
            ->whereIn('id', $recipeIds)
            ->with(['ingredients.ingredient', 'ingredients.unit', 'categories:id,slug'])
            ->get(['id', 'servings', 'title', 'total_time_minutes', 'appliance']);

        $seasonal = array_flip($this->season->seasonalProducts());
        $facts = [];

        foreach ($recipes as $recipe) {
            $energy = $this->nutrition->for($recipe);

            $categories = array_values($recipe->categories->pluck('slug')->all());

            $facts[$recipe->id] = new RecipeFact(
                kcalPerPortion: $energy->reliableKcalPerPortion(),
                costPerPortion: $this->costPerPortion($recipe, $offers),
                proteinPerPortion: $energy->isReliable() ? $energy->perPortion?->protein : null,
                realIngredients: $this->realIngredients($recipe),
                dominantIngredientId: $energy->dominantIngredientId,
                minutes: $recipe->total_time_minutes,
                categories: $categories,
                occasion: $this->season->occasionOf($recipe->title),
                seasonalProduce: $this->seasonalProduceOf($recipe, $seasonal),
                isHeadline: self::isHeadline($recipe->title),
                family: $this->families->of($recipe->title),
                servings: $recipe->servings,
                isAirFryer: $recipe->appliance === Appliance::AirFryer,
                isHomeClassic: $this->families->isHomeClassic($recipe->title),
                isPlantProtein: $this->families->isPlantProtein($recipe->title),
                isOffal: $this->families->isOffal($recipe->title),
                cuisine: $this->families->cuisineOf($recipe->title),
                signature: $this->families->signatureOf($recipe->title),
                starchShare: $energy->isReliable() ? $this->starchShare($recipe, $energy->kcalByIngredient) : null,
                namesStarch: $this->families->namesStarch($recipe->title),
                inSauce: $this->families->isInSauce($recipe->title),
                hasMeatInTitle: $this->families->namesMeat($recipe->title),
                vegetables: self::vegetablesIn($recipe),
                isRich: self::isRich($recipe, $energy->kcalByIngredient),
                soupKind: $this->families->of($recipe->title) === 'zupa' || in_array('zupy', $categories, true)
                    ? $this->families->soupKindOf($recipe->title)
                    : null,
                promoted: $offers === null ? 0 : $this->promotedIn($recipe, $offers),
            );
        }

        return $facts;
    }

    /**
     * A second sentence is what gives a headline away. Half of beszamel.se.pl's
     * 7 400 titles have one and no other source writes them; a dish is named
     * in a phrase, a news item is written in sentences.
     */
    private static function isHeadline(string $title): bool
    {
        return preg_match('/[.!?]\s+\p{Lu}/u', $title) === 1;
    }

    /**
     * @param  array<string, int>  $seasonal
     * @return list<string>
     */
    private function seasonalProduceOf(Recipe $recipe, array $seasonal): array
    {
        $found = array_fill_keys($this->season->produceNamedIn($recipe->title), true);

        foreach ($recipe->ingredients as $line) {
            $name = $line->ingredient?->name;

            if ($name !== null && isset($seasonal[$name]) && ! $line->is_optional) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
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
     * The vegetables and fruit a dish is built from.
     *
     * Onion, garlic and potatoes are left out: they are in nearly every pot, and
     * "Kiełbasa z cebulką" was a supper the dietitian counted as having no
     * vegetable at all. A number rather than a flag, so a supper of bread and
     * cheese can be told from one with a tomato on it and from a salad.
     */
    private static function vegetablesIn(Recipe $recipe): int
    {
        $found = [];

        foreach ($recipe->ingredients as $line) {
            $product = $line->ingredient;

            if ($product === null || $line->is_optional) {
                continue;
            }

            if (in_array($product->category, [IngredientCategory::Vegetable, IngredientCategory::Fruit], true)
                && preg_match('/^(cebul|czosn|ziemn|szalot)/', $product->slug) !== 1) {
                $found[$product->id] = true;
            }
        }

        return count($found);
    }

    /**
     * Processed meat and the rich cheeses, when they carry a real share of the
     * dish — a tenth of its calories. Halloumi three times in three weeks,
     * kiełbasa, chorizo and szynka beside it, was the dietitian's third
     * complaint in every round; a rasher of boczek for flavour is not that.
     *
     * @param  array<int, float>  $kcalByIngredient
     */
    private static function isRich(Recipe $recipe, array $kcalByIngredient): bool
    {
        $total = array_sum($kcalByIngredient);

        foreach ($recipe->ingredients as $line) {
            $product = $line->ingredient;

            if ($product === null || $line->is_optional || ! in_array($product->slug, self::RICH, true)) {
                continue;
            }

            // Unknown calories: being there at all is the answer.
            if ($total <= 0 || ($kcalByIngredient[$product->id] ?? 0.0) / $total >= 0.1) {
                return true;
            }
        }

        return false;
    }

    private const array RICH = [
        'boczek', 'szynka', 'kielbasa', 'guanciale', 'wedlina', 'salami', 'chorizo', 'parowki',
        'kaszanka', 'slonina', 'salceson', 'halloumi', 'camembert', 'gorgonzola', 'burrata',
        'mascarpone', 'serek-topiony',
    ];

    /**
     * How much of a dish's energy comes from what fills a plate — potatoes,
     * groats, rice, pasta, bread, dumplings.
     *
     * By calories rather than by presence, because flour, breadcrumbs and the
     * soaked roll in kotlety mielone are all grain and none of them is the
     * potatoes the cutlet still needs. Null when nothing could be counted.
     *
     * @param  array<int, float>  $kcalByIngredient
     */
    private function starchShare(Recipe $recipe, array $kcalByIngredient): ?float
    {
        $total = array_sum($kcalByIngredient);

        if ($total <= 0) {
            return null;
        }

        $starch = [];

        foreach ($recipe->ingredients as $line) {
            $product = $line->ingredient;

            if ($product !== null && ! $line->is_optional && self::isStarch($product->slug, $product->category)) {
                $starch[$product->id] = $kcalByIngredient[$product->id] ?? 0.0;
            }
        }

        return array_sum($starch) / $total;
    }

    /**
     * Grain that is eaten as itself, plus the two vegetables a plate is built
     * on. Flour, crumbs and flakes bind or coat; they never stand on a plate.
     */
    private static function isStarch(string $slug, IngredientCategory $category): bool
    {
        if (in_array($slug, ['ziemniak', 'bataty'], true)) {
            return true;
        }

        return $category === IngredientCategory::Grain
            && preg_match('/^(maka|platki|otreby|bulka-tarta|panko|herbatniki|biszkopty|chipsy)/', $slug) !== 1;
    }

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
    private function costPerPortion(Recipe $recipe, ?ShopOffers $offers): ?Money
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

            $cost = $offers === null
                ? $this->costs->of($line->ingredient_id, $line->toQuantity())
                : $this->quote($offers, $line)['cost'] ?? null;

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

    /**
     * How many of the products a dish is built from are on offer in this shop.
     *
     * Counted over the same lines the price is — not the salt, not the oil, not
     * an optional garnish. A dish does not become a promotion dish because the
     * parsley on top was cheap this week. And only where the offer actually
     * beats the usual price: a discounted premium salmon is not a reason to
     * plan salmon.
     */
    private function promotedIn(Recipe $recipe, ShopOffers $offers): int
    {
        $found = [];

        foreach ($recipe->ingredients as $line) {
            if ($line->ingredient_id === null || $this->availability->isAssumedAtHand($line)) {
                continue;
            }

            if ($offers->has($line->ingredient_id) && ($this->quote($offers, $line)['onOffer'] ?? false)) {
                $found[$line->ingredient_id] = true;
            }
        }

        return count($found);
    }

    /**
     * @return array{cost: Money, onOffer: bool, saves: ?Money}|null
     */
    private function quote(ShopOffers $offers, RecipeIngredient $line): ?array
    {
        $id = (int) $line->ingredient_id;

        return $offers->quote($id, $line->toQuantity(), $this->costs, $this->measures->for($id));
    }
}
