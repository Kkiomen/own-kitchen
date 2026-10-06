<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Enums\MealSlot;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Works out which meals of the day each recipe suits.
 *
 * Derived rather than imported, exactly like the quick-pick categories, and for
 * the same reason: no source states it. The rules live in
 * `database/data/meal-slots.php`; editing them and re-running the command is the
 * whole workflow — no re-import, no re-crawl.
 *
 * Re-runnable because it **replaces** a recipe's slots rather than adding to
 * them, so a rule that stops matching actually releases the recipe instead of
 * leaving last month's answer behind for ever.
 */
final class TagMealSlots
{
    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    /**
     * @param  callable(int, int): void|null  $onProgress  receives processed and total
     * @return array<string, int> how many recipes each slot took, keyed by slot value
     */
    public function run(?callable $onProgress = null): array
    {
        $report = $onProgress ?? static fn (int $done, int $total): null => null;

        $rules = $this->rules();
        $counts = array_fill_keys(array_keys($rules), 0);

        $total = Recipe::query()->count();
        $processed = 0;

        Recipe::query()
            ->with(['tags:id,name', 'categories:id,slug'])
            ->chunkById(300, function ($recipes) use ($rules, &$counts, &$processed, $total, $report): void {
                $links = [];

                foreach ($recipes as $recipe) {
                    $facts = $this->factsOf($recipe);
                    $named = $this->namedIn($rules, $facts['title']);

                    foreach ($rules as $slot => $rule) {
                        if (! $this->matches($rule, $facts, $named === [] ? null : in_array($slot, $named, true))) {
                            continue;
                        }

                        $links[] = ['recipe_id' => $recipe->id, 'slot' => $slot];
                        $counts[$slot]++;
                    }
                }

                DB::transaction(function () use ($recipes, $links): void {
                    // Replace, never accumulate: a rule that no longer matches
                    // has to be able to let a recipe go.
                    DB::table('recipe_meal_slots')
                        ->whereIn('recipe_id', $recipes->pluck('id'))
                        ->delete();

                    if ($links !== []) {
                        DB::table('recipe_meal_slots')->insert($links);
                    }
                });

                $processed += $recipes->count();
                $report($processed, $total);
            });

        return $counts;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rules(): array
    {
        /** @var array<string, array<string, mixed>> $rules */
        $rules = require database_path('data/meal-slots.php');

        foreach (array_keys($rules) as $slot) {
            // An unknown slot is a bug in the data file, not data: silently
            // skipping it would leave a meal nothing ever suits.
            if (MealSlot::tryFrom($slot) === null) {
                throw new InvalidArgumentException("Unknown meal slot '{$slot}' in data/meal-slots.php.");
            }
        }

        return $rules;
    }

    /**
     * @return array{title: string, tags: list<string>, categories: list<string>, mealPrep: bool}
     */
    private function factsOf(Recipe $recipe): array
    {
        $tags = [];

        foreach ($recipe->tags as $tag) {
            $tags[] = $this->normalizer->normalize($tag->name);
        }

        $categories = [];

        foreach ($recipe->categories as $category) {
            $categories[] = $category->slug;
        }

        return [
            'title' => $this->normalizer->normalize($recipe->title),
            'tags' => $tags,
            'categories' => $categories,
            'mealPrep' => $recipe->is_meal_prep,
        ];
    }

    /**
     * The meals a title names outright — "…idealna na śniadanie albo na obiad".
     *
     * The strongest signal there is, because it is the only one the author
     * stated: "Tostadas z kurczakiem to obiad idealny" is a lunch whatever its
     * tortilla says, and "Nuggetsy z halloumi — przekąska na imprezę" is not a
     * supper. So a title naming any meal is given exactly the meals it names.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<string>
     */
    private function namedIn(array $rules, string $title): array
    {
        $named = [];

        foreach ($rules as $slot => $rule) {
            if (TitleNeedle::matchesAny($title, $rule['named'] ?? [])) {
                $named[] = $slot;
            }
        }

        return $named;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{title: string, tags: list<string>, categories: list<string>, mealPrep: bool}  $facts
     * @param  bool|null  $named  whether the title names this meal; null when it names none
     */
    private function matches(array $rule, array $facts, ?bool $named): bool
    {
        // Disqualifiers first: a cake is never dinner, however its title reads.
        if (array_intersect($rule['excludeCategories'] ?? [], $facts['categories']) !== []) {
            return false;
        }

        if (TitleNeedle::matchesAny($facts['title'], $rule['excludeTitles'] ?? [])) {
            return false;
        }

        if ($this->isSideDish($rule, $facts)) {
            return false;
        }

        if ($named !== null) {
            return $named;
        }

        /*
         * A main course reaching breakfast on a tag or a category alone is how
         * "Schab pieczony w air fryerze" became one. In these categories the
         * title has to name one of this meal's own dishes.
         */
        if (array_intersect($rule['dishRequiredIn'] ?? [], $facts['categories']) !== []) {
            return TitleNeedle::matchesAny($facts['title'], $rule['titles'] ?? []);
        }

        if (($rule['mealPrep'] ?? false) && $facts['mealPrep']) {
            return true;
        }

        if (array_intersect($rule['categories'] ?? [], $facts['categories']) !== []) {
            return true;
        }

        if (array_intersect($rule['tags'] ?? [], $facts['tags']) !== []) {
            return true;
        }

        return TitleNeedle::matchesAny($facts['title'], $rule['titles'] ?? []);
    }

    /**
     * A dish that is the thing beside the meal rather than the meal.
     *
     * The word alone cannot decide it: "Surówka z młodej kapusty" is a side and
     * "Kotlety rybne z łososia z surówką z kapusty" is dinner that comes with
     * one, and beszamel.se.pl's headline titles put the word in the second
     * sentence either way, so neither word order nor position helps. What does
     * help is **corroboration**: if the catalogue independently knows this is
     * chicken, fish, pork, beef, pasta or soup, it is a main course whatever its
     * title mentions. If it does not, a title advertising a surówka is a surówka.
     *
     * It errs towards dropping the recipe, and that is the right way round here:
     * a vegetarian main lost from the suggestions is still one search away,
     * while a bowl of grated carrot served as supper is the joke this exists to
     * prevent.
     *
     * @param  array<string, mixed>  $rule
     * @param  array{title: string, tags: list<string>, categories: list<string>, mealPrep: bool}  $facts
     */
    private function isSideDish(array $rule, array $facts): bool
    {
        // Says outright what it is served with. Nothing overrules that, because
        // the thing it names is exactly what put it in a main-course category.
        if (TitleNeedle::matchesAny($facts['title'], $rule['sideAlways'] ?? [])) {
            return true;
        }

        if (! TitleNeedle::matchesAny($facts['title'], $rule['sideWords'] ?? [])) {
            return false;
        }

        return array_intersect($rule['mainCategories'] ?? [], $facts['categories']) === [];
    }
}
