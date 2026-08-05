<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Enums\Appliance;
use App\Enums\MealSlot;
use App\Models\User;
use App\Pantry\ExpiringSoon;
use App\Pantry\RecipeAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One page of the catalogue, narrowed and counted in the database.
 *
 * This used to be done in the browser over every recipe at once. At ~4 800
 * recipes that was merely wasteful; at 10 000 it stopped working altogether —
 * hydrating them cost more than PHP's memory limit and the page answered 500 on
 * every visit. Searching and filtering therefore happen in SQL, and the browser
 * receives one screenful at a time.
 *
 * Rows are read with the query builder rather than Eloquent on purpose: models
 * for a page of 36 are affordable, but the counts below run over the whole
 * table and nothing here needs behaviour, only values.
 */
final class RecipeListing
{
    public const int PAGE_SIZE = 36;

    /**
     * How many missing products still counts as "could cook this tonight".
     *
     * Shared by the "brakuje 1–2" chip and by the use-it-up one, so the two
     * cannot drift into meaning different things on the same screen.
     */
    private const int WITHIN_REACH = 2;

    /**
     * How many products each recipe is short of, for the account being served.
     *
     * @var array<int, int>|null
     */
    private ?array $missing = null;

    /**
     * The products with a use-by date on them, memoised for the same reason.
     *
     * @var array<int, string>|null
     */
    private ?array $expiring = null;

    public function __construct(
        private readonly RecipeAvailability $availability,
        private readonly ExpiringSoon $expiringSoon,
    ) {}

    /**
     * @return array{recipes: list<array<string, mixed>>, total: int, hasMore: bool}
     */
    public function page(User $user, RecipeFilter $filter): array
    {
        $missing = $this->missingCounts($user);

        $total = (int) $this->query($user, $filter, $missing)->count();

        $rows = $this->query($user, $filter, $missing)
            ->select([
                'recipes.id', 'recipes.slug', 'recipes.title', 'recipes.image_url',
                'recipes.servings_label', 'recipes.total_time_minutes',
                'recipes.appliance', 'recipes.is_meal_prep', 'recipes.needs_review',
            ])
            ->orderBy('recipes.title')
            ->forPage($filter->page, self::PAGE_SIZE)
            ->get();

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        $counts = $this->lineCounts($ids);
        $categories = $this->categorySlugs($ids);
        // Only for the recipes actually on this page: the card names what is
        // running out, and asking that of the whole catalogue would be 10 000
        // answers to show 36.
        $expiring = $this->expiringSoon->usedBy($this->expiringProducts($user), $ids);

        $recipes = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            $recipes[] = [
                'slug' => $row->slug,
                'title' => $row->title,
                'imageUrl' => $row->image_url,
                'servingsLabel' => $row->servings_label,
                'totalTimeMinutes' => $row->total_time_minutes,
                'appliance' => $row->appliance === null
                    ? null
                    : Appliance::from($row->appliance)->iconKey(),
                'isMealPrep' => (bool) $row->is_meal_prep,
                'ingredientCount' => $counts['ingredients'][$id] ?? 0,
                'stepCount' => $counts['steps'][$id] ?? 0,
                'needsReview' => (bool) $row->needs_review,
                'categories' => $categories[$id] ?? [],
                'missing' => $missing[$id] ?? 0,
                'expiring' => $expiring[$id] ?? [],
            ];
        }

        return [
            'recipes' => $recipes,
            'total' => $total,
            'hasMore' => $filter->page * self::PAGE_SIZE < $total,
        ];
    }

    /**
     * A handful of recipes matching a name, for picking one rather than browsing.
     *
     * Short and unpaged on purpose: this answers a search box inside a sheet,
     * where the tenth result is already further than anyone scrolls. The list
     * page is the place to actually look through the catalogue.
     *
     * @param  MealSlot|null  $slot  narrow it to recipes that suit this meal;
     *                               null searches the whole catalogue, which is
     *                               what "pokaż wszystkie" falls back to when the
     *                               dish you want was never tagged
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?MealSlot $slot = null, int $limit = 12): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $rows = DB::table('recipes')
            ->when($slot !== null, fn (Builder $query): Builder => $query->whereExists(
                fn (Builder $suits): Builder => $suits->from('recipe_meal_slots')
                    ->whereColumn('recipe_meal_slots.recipe_id', 'recipes.id')
                    ->where('recipe_meal_slots.slot', $slot->value),
            ))
            ->whereLike('recipes.title', '%'.$term.'%')
            ->select(['id', 'slug', 'title', 'image_url', 'servings', 'servings_label', 'total_time_minutes', 'is_meal_prep'])
            ->orderBy('title')
            ->limit($limit)
            ->get();

        $matches = [];

        foreach ($rows as $row) {
            $matches[] = [
                'id' => (int) $row->id,
                'slug' => $row->slug,
                'title' => $row->title,
                'imageUrl' => $row->image_url,
                'servings' => $row->servings === null ? null : (int) $row->servings,
                'servingsLabel' => $row->servings_label,
                'totalTimeMinutes' => $row->total_time_minutes === null ? null : (int) $row->total_time_minutes,
                'isMealPrep' => (bool) $row->is_meal_prep,
            ];
        }

        return $matches;
    }

    /**
     * The numbers on the chips. Deliberately unaffected by the search box: a
     * count that changed as you typed would make the row jump under your thumb.
     *
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $row = DB::table('recipes')->selectRaw(
            'count(*) as total,
             sum(case when appliance = ? then 1 else 0 end) as air_fryer,
             sum(case when is_meal_prep = 1 then 1 else 0 end) as meal_prep,
             sum(case when needs_review = 1 then 1 else 0 end) as review',
            [Appliance::AirFryer->value],
        )->first();

        $missing = $this->missingCounts($user);

        return [
            'all' => (int) ($row->total ?? 0),
            'air_fryer' => (int) ($row->air_fryer ?? 0),
            'meal_prep' => (int) ($row->meal_prep ?? 0),
            'review' => (int) ($row->review ?? 0),
            'cookable' => count(array_filter($missing, static fn (int $short): bool => $short === 0)),
            'almost' => count(array_filter($missing, static fn (int $short): bool => $short > 0 && $short <= 2)),
            'expiring' => count($this->expiringIds($user, $missing)),
        ];
    }

    /**
     * Recipes that use something about to go off *and* could actually be cooked.
     *
     * The shortfall cap is what makes this a suggestion rather than a search.
     * Without it, a carton of milk going off on Thursday matches two thousand
     * recipes, nearly all of them needing a shop first — which answers "co
     * zawiera mleko", a question nobody asked. Two missing products is the same
     * threshold the "brakuje 1–2" chip already uses, so "almost there" means the
     * same thing in both places.
     *
     * @param  array<int, int>  $missing
     * @return list<int>
     */
    private function expiringIds(User $user, array $missing): array
    {
        $products = $this->expiringProducts($user);

        if ($products === []) {
            return [];
        }

        $ids = [];

        foreach (array_keys($this->expiringSoon->usedBy($products)) as $recipeId) {
            /*
             * A recipe with no row in the aggregate has no ingredient lines to
             * be short of — but it cannot have reached this list either, since
             * it is here precisely because one of its lines names the product.
             */
            if (($missing[$recipeId] ?? PHP_INT_MAX) <= self::WITHIN_REACH) {
                $ids[] = $recipeId;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function expiringProducts(User $user): array
    {
        return $this->expiring ??= $this->expiringSoon->products($user);
    }

    /**
     * The shortfall aggregate, computed at most once per instance.
     *
     * It scans every ingredient line in the catalogue — ~98 000 of them — and
     * both `page()` and `counts()` need it, so running it twice doubled the cost
     * of every visit for an answer that cannot have changed in between. One
     * listing serves one request, which is exactly the life of this cache.
     *
     * @return array<int, int>
     */
    private function missingCounts(User $user): array
    {
        return $this->missing ??= $this->availability->missingCounts($user);
    }

    /**
     * @param  array<int, int>  $missing
     */
    private function query(User $user, RecipeFilter $filter, array $missing): Builder
    {
        $query = DB::table('recipes');

        if ($filter->search !== '') {
            $query->whereLike('recipes.title', '%'.$filter->search.'%');
        }

        $category = $filter->categorySlug();

        if ($category !== null) {
            $query->join('category_recipe as pivot', 'pivot.recipe_id', '=', 'recipes.id')
                ->join('categories as category', 'category.id', '=', 'pivot.category_id')
                ->where('category.slug', $category);
        }

        match ($filter->key) {
            'air_fryer' => $query->where('recipes.appliance', Appliance::AirFryer->value),
            'meal_prep' => $query->where('recipes.is_meal_prep', true),
            'review' => $query->where('recipes.needs_review', true),
            default => null,
        };

        if ($filter->key === 'expiring') {
            $query->whereIn('recipes.id', $this->expiringIds($user, $missing));

            return $query;
        }

        if ($filter->needsPantry()) {
            /*
             * The only filter that is not a column. The aggregate has already
             * answered it for every recipe, so the matching ids go in directly —
             * and they come *only* from that aggregate, so a recipe with no
             * ingredient lines at all cannot pass itself off as cookable.
             */
            $wanted = $filter->key === 'cookable'
                ? static fn (int $short): bool => $short === 0
                : static fn (int $short): bool => $short > 0 && $short <= self::WITHIN_REACH;

            $query->whereIn('recipes.id', array_keys(array_filter($missing, $wanted)));
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids
     * @return array{ingredients: array<int, int>, steps: array<int, int>}
     */
    private function lineCounts(array $ids): array
    {
        $countBy = fn (string $table): array => DB::table($table)
            ->selectRaw('recipe_id, count(*) as total')
            ->whereIn('recipe_id', $ids)
            ->groupBy('recipe_id')
            ->pluck('total', 'recipe_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'ingredients' => $countBy('recipe_ingredients'),
            'steps' => $countBy('recipe_steps'),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function categorySlugs(array $ids): array
    {
        $slugs = [];

        $rows = DB::table('category_recipe as pivot')
            ->join('categories as category', 'category.id', '=', 'pivot.category_id')
            ->whereIn('pivot.recipe_id', $ids)
            ->select(['pivot.recipe_id', 'category.slug'])
            ->get();

        foreach ($rows as $row) {
            $slugs[(int) $row->recipe_id][] = $row->slug;
        }

        return $slugs;
    }
}
