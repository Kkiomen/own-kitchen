<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\MealPlanEntry;
use App\Models\User;
use App\Nutrition\RecipeNutrition;
use App\Pantry\Pantry;
use App\Support\Measurement\MeasureBook;
use Illuminate\Support\Collection;

/**
 * What a planned stretch of days actually delivers, measured from the plan
 * rather than from what the generator intended.
 *
 * That distinction is the reason this class exists at all. The generator aims:
 * it picks a dish and a number of helpings from an estimate. This reads the
 * plan back and adds it up — so a week that was hand-edited afterwards, or
 * topped up by a second press of the button, reports what is really on it. A
 * summary computed inside the generator could only ever describe the moment it
 * ran.
 *
 * **Calories are per person per day**, because that is the number somebody set:
 * "2 500 na osobę". Reporting a household total would need dividing by the same
 * figure to mean anything, and the division is exactly where a plan for two
 * quietly becomes a plan for one.
 *
 * A day holding a dish nothing can count contributes what it can and says how
 * many meals it could not read. Silence about that would make a light-looking
 * week indistinguishable from an unreadable one.
 */
final readonly class WeekSummary
{
    public function __construct(
        private RecipeNutrition $nutrition,
        private WeekCost $cost,
        private MeasureBook $measures,
    ) {}

    /**
     * @param  list<string>  $dates
     */
    public function of(User $user, array $dates, ?PlanTargets $targets = null, ?ShopOffers $offers = null): PlannedWeek
    {
        $entries = MealPlanEntry::query()
            ->where('user_id', $user->id)
            ->onDates($dates)
            ->with(['recipe.ingredients.ingredient', 'recipe.ingredients.unit'])
            ->get();

        return new PlannedWeek(
            kcalPerPersonByDate: $this->calories($entries, $targets),
            proteinPerPersonByDate: $this->protein($entries, $targets),
            price: $this->cost->of($entries, Pantry::of($user, $this->measures), $offers),
            uncounted: $this->uncounted($entries),
            notes: $entries->filter(static fn (MealPlanEntry $entry): bool => $entry->isNote())->count(),
            targets: $targets,
        );
    }

    /**
     * What each person eats on each day, by the plan.
     *
     * An entry's `servings` is portions of the recipe for the whole household,
     * so one person's share is that divided by the people eating — the mirror of
     * the multiplication the generator did on the way in.
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return array<string, float>
     */
    private function calories(Collection $entries, ?PlanTargets $targets): array
    {
        $people = max($targets === null ? MealPlan::DEFAULT_SERVINGS : $targets->people, 1);
        $byDate = [];

        foreach ($entries as $entry) {
            $date = $entry->date->toDateString();
            $byDate[$date] ??= 0.0;

            $recipe = $entry->recipe;

            if ($recipe === null) {
                continue;
            }

            $kcal = $this->nutrition->for($recipe)->reliableKcalPerPortion();

            if ($kcal === null) {
                continue;
            }

            $byDate[$date] += $kcal * $entry->servings / $people;
        }

        ksort($byDate);

        return $byDate;
    }

    /**
     * How much protein each person gets each day, by the plan.
     *
     * Reported rather than merely aimed at, because it is the one figure that
     * says whether the week is food or just calories — and because the rule that
     * produces it lives in the generator's scoring, where nobody can see it. A
     * day whose dishes never stated their protein reports what it could and is
     * short by exactly that much: the same "stay quiet" rule, and the reason
     * this is a number to read beside the calories rather than instead of them.
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return array<string, float>
     */
    private function protein(Collection $entries, ?PlanTargets $targets): array
    {
        $people = max($targets === null ? MealPlan::DEFAULT_SERVINGS : $targets->people, 1);
        $byDate = [];

        foreach ($entries as $entry) {
            $date = $entry->date->toDateString();
            $byDate[$date] ??= 0.0;

            $recipe = $entry->recipe;

            if ($recipe === null) {
                continue;
            }

            $energy = $this->nutrition->for($recipe);
            $protein = $energy->isReliable() ? $energy->perPortion?->protein : null;

            if ($protein === null) {
                continue;
            }

            $byDate[$date] += $protein * $entry->servings / $people;
        }

        ksort($byDate);

        return $byDate;
    }

    /**
     * How many planned dishes have no calorie figure at all.
     *
     * Notes are not among them: a note was never a dish the catalogue knows, so
     * counting it as unreadable would report a fault where somebody simply wrote
     * "obiad u rodziców".
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     */
    private function uncounted(Collection $entries): int
    {
        return $entries
            ->filter(fn (MealPlanEntry $entry): bool => $entry->recipe !== null
                && $this->nutrition->for($entry->recipe)->reliableKcalPerPortion() === null)
            ->count();
    }
}
