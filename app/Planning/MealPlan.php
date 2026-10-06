<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Enums\RecipeVerdict;
use App\Models\MealPlanEntry;
use App\Models\RecipePreference;
use App\Models\User;
use App\Nutrition\RecipeNutrition;
use App\Pantry\RecipeAvailability;
use App\Support\PolishDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One household's week of meals.
 *
 * A week rather than an endless list because that is the unit the cooking is
 * planned in: one shop, one Sunday at the stove, seven days of lunchboxes. The
 * screen is built around choosing days out of it and turning them into shopping.
 */
final class MealPlan
{
    public const int DAYS = 7;

    /**
     * Two people eat here. It is the amount that is right nearly every time and
     * one tap from being changed, which is the only kind of prefilled number this
     * app allows itself.
     */
    public const int DEFAULT_SERVINGS = 2;

    /**
     * The household's verdict on each recipe in the week being shown.
     *
     * @var array<int, string>
     */
    private array $verdicts = [];

    public function __construct(
        private readonly RecipeAvailability $availability,
        private readonly RecipeNutrition $nutrition,
    ) {}

    /** Weeks start on Monday, the way a Polish calendar and a shop leaflet do. */
    public static function weekOf(?string $date): CarbonImmutable
    {
        $day = $date === null
            ? CarbonImmutable::today()
            : CarbonImmutable::parse($date);

        return $day->startOfWeek();
    }

    /**
     * @return list<string> the seven dates of this week, as `Y-m-d`
     */
    public static function datesOf(CarbonImmutable $weekStart): array
    {
        $dates = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $dates[] = $weekStart->addDays($offset)->toDateString();
        }

        return $dates;
    }

    /**
     * @param  list<string>  $dates
     * @return Collection<int, MealPlanEntry>
     */
    public function entriesOn(User $user, array $dates): Collection
    {
        return MealPlanEntry::query()
            ->where('user_id', $user->id)
            ->onDates($dates)
            ->with(['recipe.ingredients.ingredient', 'recipe.ingredients.unit'])
            ->orderBy('date')
            ->orderBy('position')
            ->get();
    }

    /**
     * The week as the screen reads it: seven days, each with its slots in the
     * order they are eaten, each slot holding what was planned for it.
     *
     * A slot with nothing in it is still sent. An empty row is what an unplanned
     * meal looks like, and it is where the "+" lives — but only the three meals
     * of `MealSlot::everyday()` show unasked, or seven days of five empty rows
     * would be a wall rather than a plan.
     *
     * @return list<array<string, mixed>>
     */
    public function week(User $user, CarbonImmutable $weekStart): array
    {
        $dates = self::datesOf($weekStart);
        $entries = $this->entriesOn($user, $dates);

        $shortfall = $this->availability->missingCounts($user, $this->recipeIds($entries));
        $this->verdicts = RecipePreference::query()
            ->of($user)
            ->whereIn('recipe_id', $this->recipeIds($entries))
            ->pluck('verdict', 'recipe_id')
            ->map(static fn (RecipeVerdict $verdict): string => $verdict->value)
            ->all();

        $byDay = [];

        foreach ($entries as $entry) {
            $byDay[$entry->date->toDateString()][$entry->slot->value][] = $entry;
        }

        $days = [];

        foreach ($dates as $date) {
            $days[] = [
                'date' => $date,
                'weekday' => PolishDate::weekdayShort(CarbonImmutable::parse($date)),
                'dayOfMonth' => (int) CarbonImmutable::parse($date)->format('j'),
                'isToday' => $date === CarbonImmutable::today()->toDateString(),
                'slots' => $this->slotsOf($byDay[$date] ?? [], $shortfall),
                'nutrition' => $this->dayTotals($byDay[$date] ?? []),
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, list<MealPlanEntry>>  $planned
     * @param  array<int, int>  $shortfall
     * @return list<array<string, mixed>>
     */
    private function slotsOf(array $planned, array $shortfall): array
    {
        $slots = [];

        foreach (MealSlot::ordered() as $slot) {
            $entries = $planned[$slot->value] ?? [];

            // The two extra meals earn their row by being used, not by existing.
            if ($entries === [] && ! in_array($slot, MealSlot::everyday(), true)) {
                continue;
            }

            $slots[] = [
                'value' => $slot->value,
                'label' => $slot->label(),
                'entries' => array_map(
                    fn (MealPlanEntry $entry): array => $this->present($entry, $shortfall),
                    $entries,
                ),
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, int>  $shortfall
     * @return array<string, mixed>
     */
    private function present(MealPlanEntry $entry, array $shortfall): array
    {
        $recipe = $entry->recipe;

        return [
            'id' => $entry->id,
            'slot' => $entry->slot->value,
            'date' => $entry->date->toDateString(),
            'title' => $entry->label(),
            'note' => $entry->note,
            'slug' => $recipe?->slug,
            'imageUrl' => $recipe?->image_url,
            'servings' => $entry->servings,
            /** What the recipe itself makes, so the screen can say "przepis na 4". */
            'recipeServings' => $recipe?->servings,
            'totalTimeMinutes' => $recipe?->total_time_minutes,
            'isMealPrep' => $recipe !== null && $recipe->is_meal_prep,
            'missing' => $recipe === null ? null : ($shortfall[$recipe->id] ?? 0),
            // "lubimy" / "nie proponuj", so the card can show which it already is.
            'verdict' => $recipe === null ? null : ($this->verdicts[$recipe->id] ?? null),
            'nutrition' => $this->share($entry),
        ];
    }

    /**
     * What one person gets from this meal.
     *
     * **Per person, not per meal**, because that is the unit the whole feature
     * speaks in — the week panel says "kcal / osobę / dzień" and the recipe says
     * "w jednej porcji". A meal figure sitting under a 2 500 daily target reads
     * as a fault: the first version of the alternatives sheet showed one and a
     * lunch looked like it had blown the day on its own.
     *
     * The household is `DEFAULT_SERVINGS`, the same assumption `WeekSummary`
     * makes when nobody has stated a target. It is the one number this app is
     * entitled to assume: one household, one account, two phones.
     *
     * Null when the dish cannot be counted — a zero would read as a light meal.
     *
     * @return array<string, float>|null
     */
    private function share(MealPlanEntry $entry): ?array
    {
        $recipe = $entry->recipe;

        if ($recipe === null) {
            return null;
        }

        $energy = $this->nutrition->for($recipe);

        if (! $energy->isReliable() || $energy->perPortion === null) {
            return null;
        }

        $portions = $entry->servings / max(self::DEFAULT_SERVINGS, 1);
        $portion = $energy->perPortion;

        return array_filter([
            'kcal' => round($portion->kcal * $portions),
            'protein' => $portion->protein === null ? null : round($portion->protein * $portions),
            'fat' => $portion->fat === null ? null : round($portion->fat * $portions),
            'carbs' => $portion->carbs === null ? null : round($portion->carbs * $portions),
        ], static fn (?float $value): bool => $value !== null);
    }

    /**
     * The day added up, and how many of its dishes went uncounted.
     *
     * The count travels with the total for the reason it does everywhere else:
     * a day missing one unreadable dinner is not a light day, and a bare number
     * cannot tell the difference.
     *
     * @param  array<string, list<MealPlanEntry>>  $planned
     * @return array<string, mixed>|null
     */
    private function dayTotals(array $planned): ?array
    {
        $totals = ['kcal' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0];
        $counted = 0;
        $uncounted = 0;

        foreach ($planned as $entries) {
            foreach ($entries as $entry) {
                $share = $this->share($entry);

                if ($share === null) {
                    if (! $entry->isNote()) {
                        $uncounted++;
                    }

                    continue;
                }

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + ($share[$key] ?? 0.0);
                }

                $counted++;
            }
        }

        if ($counted === 0) {
            return null;
        }

        return [...array_map(round(...), $totals), 'uncounted' => $uncounted];
    }

    /**
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return list<int>
     */
    private function recipeIds(Collection $entries): array
    {
        return array_values(array_unique(array_filter(
            $entries->map(static fn (MealPlanEntry $entry): ?int => $entry->recipe_id)->all(),
            static fn (?int $id): bool => $id !== null,
        )));
    }
}
