<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\User;
use App\Pantry\RecipeAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills a week in for you, from the recipes each meal is actually suited to.
 *
 * This is what `recipe_meal_slots` was tagged for. Three rules make the result
 * something a household would really cook:
 *
 * **It never overwrites.** Only empty (day, meal) pairs are filled, so a week
 * you have started planning by hand survives pressing the button, and pressing
 * it twice tops up the gaps rather than throwing yesterday's decision away.
 *
 * **The fridge comes first.** Candidates are ordered by how many products they
 * are short of, then shuffled inside that — so the suggestions lean on what is
 * already in the kitchen without proposing the same three dishes for ever. An
 * empty kitchen skips that ordering entirely: there it would only rank recipes
 * by how few ingredients they have, which is a different question and a worse
 * week.
 *
 * **A meal-prep dish is planned twice in a row.** `is_meal_prep` is the source
 * saying "cooked ahead and carried", which is how this household eats: one pot,
 * two days. The two entries then sum their portions into a single batch when the
 * shopping is worked out — see `PlannedIngredients`.
 *
 * **Nothing repeats within a month.** Not within the week being filled, and not
 * against the weeks either side of it: a generator that only looked at its own
 * seven days would offer the same gulasz every Monday, and the point of a
 * catalogue this size is that it never has to.
 */
final class PlanGenerator
{
    /**
     * How many candidates a slot draws from once the kitchen has narrowed them.
     *
     * Wide enough that a week is not the same seven dishes every time, narrow
     * enough that "cook what you have" still means something.
     */
    private const int POOL = 80;

    public function __construct(private readonly RecipeAvailability $availability) {}

    /**
     * Fill in exactly the meals asked for, day by day.
     *
     * Per day rather than "these meals on all these days", because a week is not
     * uniform: a podwieczorek on Saturday and a drugie śniadanie on the working
     * days is a normal way to eat, and a generator that could only do all-or-
     * nothing would fill in meals nobody intends to make.
     *
     * @param  array<string, list<MealSlot>>  $wanted  meals wanted, keyed by date,
     *                                                 in the order they are eaten
     * @return array{added: int, skipped: int, empty: list<string>} `empty` names
     *                                                              the meals no
     *                                                              recipe is
     *                                                              tagged for
     */
    public function fill(
        User $user,
        array $wanted,
        int $servings = MealPlan::DEFAULT_SERVINGS,
    ): array {
        $this->used = [];
        $taken = $this->alreadyPlanned($user, array_keys($wanted));
        $missing = $this->shortfallFor($user);

        $added = 0;
        $skipped = 0;
        $empty = [];
        $rows = [];

        foreach ($this->datesBySlot($wanted) as $slotValue => $dates) {
            $slot = MealSlot::from($slotValue);
            $pool = $this->poolFor($slot, $missing);

            if ($pool->isEmpty()) {
                $empty[] = $slot->label();

                continue;
            }

            $repeatUntil = null;
            $repeated = null;

            foreach ($dates as $index => $date) {
                if (isset($taken[$date][$slot->value])) {
                    $skipped++;
                    $repeatUntil = null;

                    continue;
                }

                // The second day of a batch: same pot, no second decision.
                $recipe = $repeatUntil === $index ? $repeated : $this->next($pool);

                if ($recipe === null) {
                    break;
                }

                $rows[] = [
                    'user_id' => $user->id,
                    'date' => $date,
                    'slot' => $slot->value,
                    'recipe_id' => $recipe->id,
                    'servings' => $servings,
                ];
                $added++;

                $isBatch = $recipe->isMealPrep && $repeatUntil !== $index;
                $repeatUntil = $isBatch ? $index + 1 : null;
                $repeated = $recipe;
            }
        }

        /*
         * Created one by one rather than in a single `insert()`: a bulk insert
         * skips the casts, and a `date` written raw would not match the
         * timestamp form the rest of the app stores — the generated week would
         * be invisible on the very screen that asked for it.
         */
        DB::transaction(static function () use ($rows): void {
            foreach ($rows as $row) {
                MealPlanEntry::query()->create($row);
            }
        });

        return ['added' => $added, 'skipped' => $skipped, 'empty' => $empty];
    }

    /**
     * Swap one planned meal for another suggestion of the same kind.
     *
     * The same rules as filling a week, applied to one slot: nothing eaten
     * within the month around it, the kitchen first. The dish being replaced is
     * inside that window, so it cannot be handed back — which is the whole point
     * of pressing the button. The portions are kept: swapping the dish is not a
     * decision about how many people are eating.
     *
     * Null when the meal has nothing left to offer, and then nothing changes.
     */
    public function swap(User $user, MealPlanEntry $entry): ?RecipeCandidate
    {
        $this->used = [];
        $this->alreadyPlanned($user, [$entry->date->toDateString()]);

        $recipe = $this->next($this->poolFor($entry->slot, $this->shortfallFor($user)));

        if ($recipe === null) {
            return null;
        }

        $entry->update(['recipe_id' => $recipe->id]);

        return $recipe;
    }

    /**
     * How short of each recipe this kitchen is, or nothing at all.
     *
     * An empty kitchen must not be asked which recipe it nearly covers: the
     * answer is "the one with the fewest ingredients", which is a different
     * question and a worse week. The aggregate is skipped outright then — it is
     * the expensive part of this and it would only mislead.
     *
     * @return array<int, int>
     */
    private function shortfallFor(User $user): array
    {
        return PantryItem::query()->where('user_id', $user->id)->exists()
            ? $this->availability->missingCounts($user)
            : [];
    }

    /**
     * The same request read the other way round: one ordered run of days per
     * meal, which is what a pool is drawn for and what a batch runs along.
     *
     * A dish cooked ahead covers the *next day that wants that meal*, not the
     * next day in the calendar — Monday and Wednesday lunches with nothing on
     * Tuesday is one pot, because Tuesday was never going to eat it.
     *
     * @param  array<string, list<MealSlot>>  $wanted
     * @return array<string, list<string>>
     */
    private function datesBySlot(array $wanted): array
    {
        $bySlot = [];

        foreach ($wanted as $date => $slots) {
            foreach ($slots as $slot) {
                $bySlot[$slot->value][] = (string) $date;
            }
        }

        return $bySlot;
    }

    /**
     * The recipes this meal can draw on, best first and then shuffled.
     *
     * @param  array<int, int>  $missing
     * @return Collection<int, RecipeCandidate>
     */
    private function poolFor(MealSlot $slot, array $missing): Collection
    {
        $candidates = DB::table('recipes')
            ->join('recipe_meal_slots as suits', 'suits.recipe_id', '=', 'recipes.id')
            ->where('suits.slot', $slot->value)
            ->select(['recipes.id', 'recipes.is_meal_prep'])
            ->get()
            ->map(static fn (object $row): RecipeCandidate => new RecipeCandidate(
                (int) $row->id,
                (bool) $row->is_meal_prep,
            ))
            /*
             * Dropped here rather than when one is drawn: a month of dinners is
             * thirty dishes, and filtering after `take(POOL)` could empty a
             * window of eighty down to nothing while thousands of untried
             * recipes sat one row outside it.
             */
            ->reject(fn (RecipeCandidate $recipe): bool => isset($this->used[$recipe->id]));

        if ($missing === []) {
            // Nothing on the shelves to prefer by, so prefer nothing.
            return $candidates->shuffle()->take(self::POOL)->values();
        }

        return $candidates
            ->sortBy(static fn (RecipeCandidate $recipe): int => $missing[$recipe->id] ?? PHP_INT_MAX)
            ->take(self::POOL)
            ->shuffle()
            ->values();
    }

    /**
     * What the chosen days already hold, so nothing planned is overwritten.
     *
     * @param  list<string>  $dates
     * @return array<string, array<string, true>>
     */
    private function alreadyPlanned(User $user, array $dates): array
    {
        $taken = [];

        foreach ($this->nearbyEntries($user, $dates) as $entry) {
            $date = $entry->date->toDateString();

            if (in_array($date, $dates, true)) {
                $taken[$date][$entry->slot->value] = true;
            }

            if ($entry->recipe_id !== null) {
                $this->used[$entry->recipe_id] = true;
            }
        }

        return $taken;
    }

    /**
     * Everything planned within a month either side of the days being filled.
     *
     * The days themselves say which meals are already spoken for. The month
     * around them says what **not to suggest again**: a generator that only
     * looked at its own week would offer the same gulasz every Monday, and the
     * point of a catalogue this size is that it never has to. Both directions,
     * because weeks are not always planned in order.
     *
     * @param  list<string>  $dates
     * @return Collection<int, MealPlanEntry>
     */
    private function nearbyEntries(User $user, array $dates): Collection
    {
        $sorted = $dates;
        sort($sorted);

        $from = CarbonImmutable::parse($sorted[0])->subDays(self::VARIETY_DAYS);
        $to = CarbonImmutable::parse($sorted[count($sorted) - 1])->addDays(self::VARIETY_DAYS);

        return MealPlanEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'slot', 'recipe_id']);
    }

    /**
     * How far back and forward a dish counts as "recently eaten".
     *
     * A month, because that is the span somebody notices — a fortnight is short
     * enough that repeats feel deliberate, and a year would exhaust even a
     * catalogue of ten thousand into always proposing the same obscure tail.
     */
    private const int VARIETY_DAYS = 30;

    /**
     * Recipes this week already leans on, so nothing is proposed twice.
     *
     * The one deliberate exception is a batch: a meal-prep dish is placed on two
     * days on purpose, and that second placement never goes through the pool.
     *
     * @var array<int, true>
     */
    private array $used = [];

    /**
     * @param  Collection<int, RecipeCandidate>  $pool
     */
    private function next(Collection $pool): ?RecipeCandidate
    {
        while (($recipe = $pool->shift()) !== null) {
            if (! isset($this->used[$recipe->id])) {
                $this->used[$recipe->id] = true;

                return $recipe;
            }
        }

        return null;
    }
}
