<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Category;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Works out which quick-pick categories each recipe belongs to.
 *
 * Deliberately derived rather than imported: the sources' own categories were
 * never recorded, and their tags cover barely half the catalogue while mixing
 * cuisine with diet with single ingredients. Canonical ingredients are the one
 * thing we trust everywhere, with titles and tags as corroboration.
 *
 * Re-runnable: it replaces a recipe's categories rather than adding to them, so
 * editing the rules and running it again is the whole workflow.
 */
final class CategoriseRecipes
{
    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    /**
     * @param  callable(int, int): void|null  $onProgress  Receives processed and total counts.
     * @return array<string, int> Recipes matched, keyed by category slug.
     */
    public function run(?callable $onProgress = null): array
    {
        $report = $onProgress ?? static fn (int $done, int $total): null => null;

        /** @var list<array<string, mixed>> $rules */
        $rules = require database_path('data/categories.php');

        $categories = $this->syncCategories($rules);
        $counts = array_fill_keys(array_keys($categories), 0);

        $total = Recipe::query()->count();
        $processed = 0;

        Recipe::query()
            ->with(['tags:id,name', 'ingredients:id,recipe_id,ingredient_id', 'ingredients.ingredient:id,name,category,source'])
            ->chunkById(200, function ($recipes) use ($rules, $categories, &$counts, &$processed, $total, $report): void {
                $links = [];

                foreach ($recipes as $recipe) {
                    $facts = $this->factsOf($recipe);

                    foreach ($rules as $rule) {
                        if (! $this->matches($rule, $facts)) {
                            continue;
                        }

                        $links[] = [
                            'category_id' => $categories[$rule['slug']],
                            'recipe_id' => $recipe->id,
                        ];
                        $counts[$rule['slug']]++;
                    }
                }

                DB::transaction(function () use ($recipes, $links): void {
                    // Replace rather than add, so a rule that no longer matches
                    // actually releases the recipe.
                    DB::table('category_recipe')
                        ->whereIn('recipe_id', $recipes->pluck('id'))
                        ->delete();

                    if ($links !== []) {
                        DB::table('category_recipe')->insert($links);
                    }
                });

                $processed += $recipes->count();
                $report($processed, $total);
            });

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, int> Category id keyed by slug.
     */
    private function syncCategories(array $rules): array
    {
        $ids = [];

        foreach ($rules as $position => $rule) {
            /** @var string $slug */
            $slug = $rule['slug'];

            $ids[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $rule['name'],
                    'icon' => $rule['icon'] ?? null,
                    'position' => $position,
                ],
            )->id;
        }

        // A category dropped from the rules must disappear from the picker too.
        Category::query()->whereNotIn('slug', array_keys($ids))->delete();

        return $ids;
    }

    /**
     * @return array{title: string, minutes: int|null, tags: list<string>, ingredients: list<string>, ingredientCategories: list<string>, allIngredientsTrusted: bool}
     */
    private function factsOf(Recipe $recipe): array
    {
        $tags = [];

        foreach ($recipe->tags as $tag) {
            $tags[] = $this->normalizer->normalize($tag->name);
        }

        $ingredients = [];
        $ingredientCategories = [];
        $allTrusted = true;

        foreach ($recipe->ingredients as $line) {
            if ($line->ingredient === null) {
                $allTrusted = false;

                continue;
            }

            $ingredients[] = $line->ingredient->name;
            $ingredientCategories[] = $line->ingredient->category->value;

            if (! $line->ingredient->source->isTrusted()) {
                $allTrusted = false;
            }
        }

        return [
            'title' => $this->normalizer->normalize($recipe->title),
            'minutes' => $recipe->total_time_minutes,
            'tags' => $tags,
            'ingredients' => array_values(array_unique($ingredients)),
            'ingredientCategories' => array_values(array_unique($ingredientCategories)),
            'allIngredientsTrusted' => $allTrusted,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{title: string, minutes: int|null, tags: list<string>, ingredients: list<string>, ingredientCategories: list<string>, allIngredientsTrusted: bool}  $facts
     */
    private function matches(array $rule, array $facts): bool
    {
        /*
         * Disqualifiers run first. Polish recipe titles are full of imitations —
         * `"Boczek" z bakłażana`, "Żeberka z kukurydzy", "Pasta z tempehu a la
         * ryba" — and a substring rule cannot tell them from the real thing.
         */
        foreach ($rule['excludeTitles'] ?? [] as $needle) {
            if ($this->titleHas($facts['title'], $needle)) {
                return false;
            }
        }

        /*
         * "Contains no meat or fish" — but only when every ingredient on the
         * recipe is one we vouch for.
         *
         * An unrecognised ingredient is stored under `Other`, so a chicken dish
         * whose "filet z kurczaka" was never curated looks meat-free. Trusting
         * that put 2705 of 4580 recipes in this category, most of them wrongly.
         * Someone avoiding meat is exactly the person who must not be told a
         * guess.
         */
        if (isset($rule['withoutIngredientCategories'])
            && $facts['ingredients'] !== []
            && $facts['allIngredientsTrusted']) {
            $forbidden = array_intersect(
                $rule['withoutIngredientCategories'],
                $facts['ingredientCategories'],
            );

            if ($forbidden === []) {
                return true;
            }
        }

        if (isset($rule['maxMinutes'])
            && $facts['minutes'] !== null
            && $facts['minutes'] <= $rule['maxMinutes']) {
            return true;
        }

        foreach ($rule['ingredients'] ?? [] as $name) {
            if (in_array($name, $facts['ingredients'], true)) {
                return true;
            }
        }

        foreach ($rule['tags'] ?? [] as $tag) {
            if (in_array($tag, $facts['tags'], true)) {
                return true;
            }
        }

        foreach ($rule['titles'] ?? [] as $needle) {
            if ($this->titleHas($facts['title'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole words, not substrings — see `TitleNeedle`, which the meal-slot rules
     * share so that both rule sets cannot drift apart on it.
     */
    private function titleHas(string $title, string $needle): bool
    {
        return TitleNeedle::matches($title, $needle);
    }
}
