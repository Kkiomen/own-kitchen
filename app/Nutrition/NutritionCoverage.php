<?php

declare(strict_types=1);

namespace App\Nutrition;

use App\Enums\IngredientCategory;
use App\Enums\UnitDimension;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much of the catalogue we can honestly put a calorie on.
 *
 * Measured the same way `MeasureCoverage` measures weights, and for the same
 * reason: against **recipe lines**, then against **whole recipes**, never against
 * the product list. A dictionary of three hundred products that happens to miss
 * the twenty every dinner uses is not 95% done, and only the second of those two
 * numbers answers the question the planner actually asks — *can I build a week
 * out of this?* A recipe is only usable if nearly all of it reads, so line
 * coverage of 96% and recipe coverage of 96% are very different worlds.
 *
 * The gaps come back sorted by how often the catalogue leans on them, which is
 * the "attack by frequency" rule the ingredient review queue and the weights both
 * follow: the top of that list is worth a hundred entries from the tail.
 */
final class NutritionCoverage
{
    /**
     * @return array{
     *     lines: int,
     *     readable: int,
     *     recipes: int,
     *     usable: int,
     *     withoutServings: int,
     *     missing: list<array{name: string, lines: int}>,
     * }
     */
    public function report(int $missingLimit = 30): array
    {
        $lines = $this->countable()
            ->selectRaw('count(*) as lines, sum(case when '.$this->readableSql().' then 1 else 0 end) as readable')
            ->first();

        return [
            'lines' => (int) ($lines->lines ?? 0),
            'readable' => (int) ($lines->readable ?? 0),
            ...$this->recipes(),
            'missing' => $this->missing($missingLimit),
        ];
    }

    /**
     * How many recipes a calorie-targeted plan may actually draw on.
     *
     * "Usable" is the planner's own test spelled in SQL: enough of the dish reads
     * (`RecipeEnergy::MINIMUM_COVERAGE`) **and** the source said how many portions
     * it makes. Both halves are needed — a perfectly readable recipe that never
     * stated its portions still cannot be given to somebody as "one portion of
     * this is 640 kcal".
     *
     * A recipe with nothing countable in it at all counts as covered, exactly as
     * `RecipeNutrition` treats it: there was nothing to fail to read.
     *
     * @return array{recipes: int, usable: int, withoutServings: int}
     */
    private function recipes(): array
    {
        $perRecipe = $this->countable()
            ->selectRaw('line.recipe_id as recipe_id, count(*) as countable, sum(case when '.$this->readableSql().' then 1 else 0 end) as readable')
            ->groupBy('line.recipe_id');

        $row = DB::query()
            ->fromSub($perRecipe, 'per')
            ->join('recipes as r', 'r.id', '=', 'per.recipe_id')
            ->selectRaw('count(*) as recipes')
            ->selectRaw(
                'sum(case when per.readable >= per.countable * ? and r.servings is not null then 1 else 0 end) as usable',
                [RecipeEnergy::MINIMUM_COVERAGE],
            )
            ->selectRaw('sum(case when r.servings is null then 1 else 0 end) as without_servings')
            ->first();

        return [
            'recipes' => (int) ($row->recipes ?? 0),
            'usable' => (int) ($row->usable ?? 0),
            'withoutServings' => (int) ($row->without_servings ?? 0),
        ];
    }

    /**
     * The products the catalogue leans on hardest and we still cannot put a
     * calorie on.
     *
     * @return list<array{name: string, lines: int}>
     */
    private function missing(int $limit): array
    {
        $rows = $this->countable()
            ->whereRaw('not ('.$this->readableSql().')')
            ->whereNull('n.ingredient_id')
            // A line the importer never resolved has no product to look up, so it
            // is a gap in the review queue rather than in this dictionary. Listing
            // it here would send somebody to add a calorie for a nameless thing.
            ->whereNotNull('i.name')
            ->selectRaw('i.name as name, count(*) as lines')
            ->groupBy('i.name')
            ->orderByDesc('lines')
            ->limit($limit)
            ->get();

        return array_values(array_map(
            static fn (object $row): array => [
                'name' => (string) $row->name,
                'lines' => (int) $row->lines,
            ],
            $rows->all(),
        ));
    }

    /**
     * The lines a calorie has to be found for.
     *
     * The same test as `RecipeEnergy::isCountable()`, and it has to stay the
     * same or this report is measuring a different app than the one that runs.
     * Not optional, not equipment, and — the part that is easy to get wrong —
     * **a line stating no amount is still counted**, because a recipe naming
     * butter without weighing it is a recipe we cannot count, not one with
     * nothing to count. The single exception is a spice or a herb, which is
     * worth no calories at any amount somebody might have meant.
     *
     * Note the joins are left joins throughout, unlike `MeasureCoverage`: an
     * inner join to `units` would drop exactly the amountless lines this rule
     * exists to catch, and the report would go back to claiming the coverage the
     * first version of it claimed.
     */
    private function countable(): Builder
    {
        return DB::table('recipe_ingredients as line')
            ->leftJoin('units as u', 'u.id', '=', 'line.unit_id')
            ->leftJoin('ingredients as i', 'i.id', '=', 'line.ingredient_id')
            ->leftJoin('ingredient_measures as m', function ($join): void {
                $join->on('m.ingredient_id', '=', 'line.ingredient_id')
                    ->on('m.unit_id', '=', 'line.unit_id');
            })
            ->leftJoin('ingredient_nutrition as n', 'n.ingredient_id', '=', 'line.ingredient_id')
            ->where('line.is_optional', false)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('i.category')
                ->orWhere('i.category', '!=', IngredientCategory::Equipment->value))
            // Either the line states an amount, or its product is one whose
            // amount could not matter. A null category is nothing we can excuse.
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $stated): Builder => $stated
                    ->whereNotNull('line.quantity')
                    ->whereNotNull('line.unit_id'))
                ->orWhereNull('i.category')
                ->orWhereNotIn('i.category', IngredientCategory::caloricallyNegligible()));
    }

    /**
     * Both halves a line needs, expressed once in SQL so this report cannot drift
     * from what `RecipeNutrition` will actually manage to read: the amount has to
     * reach grams, and the product has to have a figure.
     *
     * The leading `line.quantity is not null` is what makes an unweighed
     * ingredient count as unread rather than as read — the mirror of the same
     * decision in `RecipeEnergy::isCountable()`.
     *
     * Declared `literal-string` for the reason `MeasureCoverage` documents — that
     * is what `selectRaw()` and `whereRaw()` require, and it is the property that
     * makes interpolating a request value here impossible. Every piece is a
     * constant or an enum case, never data.
     *
     * @return literal-string
     */
    private function readableSql(): string
    {
        return '(n.ingredient_id is not null and line.quantity is not null and ('
            ."u.dimension = '".UnitDimension::Mass->value."'"
            .' or m.grams is not null'
            ." or (u.dimension = '".UnitDimension::Volume->value."' and i.density_g_per_ml is not null)"
            .'))';
    }
}
