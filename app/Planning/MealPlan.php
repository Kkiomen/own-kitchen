<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Models\User;
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

    public function __construct(private readonly RecipeAvailability $availability) {}

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
        ];
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
