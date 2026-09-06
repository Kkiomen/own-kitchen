<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Recipe;
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

    /**
     * How many of the fridge-ranked candidates are given a calorie and a price
     * before the band narrows them.
     *
     * Wider than `POOL` because most of what it looks at is discarded: a recipe
     * has to be countable *and* the right size for this meal, which on the live
     * catalogue is roughly one candidate in three. Wider still would only buy
     * variety the shuffle already provides, at the cost of reading every one of
     * their ingredient lists.
     */
    private const int TARGETED_POOL = 240;

    /** Second helpings, yes; eight helpings, no. */
    private const int MAX_PORTIONS_EACH = 3;

    /**
     * How many real ingredients a main meal has to be built from.
     *
     * Seasoning and water do not count. Two is a low bar and it still removes a
     * great deal: on the live catalogue **236 recipes tagged as a meal have two
     * or fewer real ingredients and 63 have exactly one** — "Jak ugotować kaszę
     * bulgur w Air Fryer", "Chipsy z banana", "Przepis na grzanki do zupy". They
     * are components and instructions, and a generated week that offered one as
     * Tuesday's dinner would be laughed at, however well it hit its calories.
     * The first browser test of this produced exactly that, twice in one week.
     *
     * Not applied to a podwieczorek, where a bowl of something simple is the
     * whole idea.
     */
    private const int MIN_REAL_INGREDIENTS = 2;

    /**
     * How close two scores have to be before the choice between them is left to
     * chance.
     *
     * The score is a sum of relative errors, so this is "within five per cent of
     * the meal" — near enough that no cook would notice which arrived, wide
     * enough that a week asked for twice is not the same week.
     */
    private const float VARIETY_BAND = 0.05;

    /**
     * How far a meal may land from its share of the day and still be planned.
     *
     * A fifth, which on a 625 kcal supper is 125 kcal — about a slice of bread,
     * and well inside the error of the calorie figures themselves. Tighter and
     * the pool empties on the meals the catalogue is thin for; looser and a
     * week of near-misses in the same direction adds up to a missed target.
     */
    private const float MAX_MISS = 0.2;

    /**
     * The least of a meal's calories that should come from protein.
     *
     * Fifteen per cent is the low end of what a balanced diet is usually put at,
     * and it is used here as a floor rather than a goal: above it a dish is not
     * rewarded for being leaner still, because a week of chicken breast is not
     * the request either.
     *
     * Without this the week is not merely unbalanced, it is *reliably* so — a
     * planner told to hit a calorie number cheaply will find that flour and
     * potatoes are the cheapest calories there are, and the first live run of
     * this returned pancakes, challah, gnocchi and macaroni cheese.
     */
    private const float MIN_PROTEIN_SHARE = 0.15;

    /**
     * How many dishes have to clear that floor before it is enforced.
     *
     * Below this the meal has too little to choose from and the floor is dropped
     * rather than the meal — an empty Thursday is a worse answer than a light one.
     * Twenty is enough that the variety shuffle still has room to work.
     */
    private const int MIN_BALANCED = 20;

    /**
     * How hard a meal is pushed towards that floor.
     *
     * A dish with no protein at all is penalised as much as missing the calorie
     * target by half, which is enough to lose to any ordinary meal — and, unlike
     * a hard rule, still lets a genuinely low-protein dish through when a meal
     * has nothing else to offer. Porridge for breakfast is fine; porridge three
     * times a day is what this stops.
     */
    private const float PROTEIN_WEIGHT = 3.3;

    /**
     * How much a meal's price counts against its calorie fit when a budget is set.
     *
     * A meal at exactly the affordable average weighs about as much as missing
     * the calorie target by a third. Enough that the week leans cheap; not
     * enough that it stops feeding anybody.
     */
    private const float COST_WEIGHT = 0.35;

    public function __construct(
        private readonly RecipeAvailability $availability,
        private readonly RecipeFacts $facts,
    ) {}

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
     * @return array{added: int, skipped: int, empty: list<string>, overspent: int}
     *                                                                              `empty` names the meals no recipe is
     *                                                                              tagged for; `overspent` counts the
     *                                                                              meals nothing affordable was left for
     */
    public function fill(
        User $user,
        array $wanted,
        int $servings = MealPlan::DEFAULT_SERVINGS,
        ?PlanTargets $targets = null,
    ): array {
        $this->used = [];
        $this->known = [];
        $this->eaten = [];
        $this->eatenToday = [];
        $taken = $this->alreadyPlanned($user, array_keys($wanted));
        $missing = $this->shortfallFor($user);

        $this->budgetLeft = $targets?->budget?->toZloty();
        $this->mealsLeft = $targets === null ? 0 : $this->mealsToFill($wanted, $taken);
        $this->peopleEating = $targets === null ? 1 : $targets->people;
        $this->overspent = 0;

        $added = 0;
        $skipped = 0;
        $empty = [];
        $rows = [];

        foreach ($this->datesBySlot($wanted) as $slotValue => $dates) {
            $slot = MealSlot::from($slotValue);
            $pool = $this->poolFor($slot, $missing, $targets);

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
                $recipe = $repeatUntil === $index ? $repeated : $this->next($pool, $slot, (string) $date, $targets);

                if ($recipe === null) {
                    break;
                }

                $portions = $targets === null
                    ? $servings
                    : $this->portionsFor($recipe, $slot, $targets);

                $rows[] = [
                    'user_id' => $user->id,
                    'date' => $date,
                    'slot' => $slot->value,
                    'recipe_id' => $recipe->id,
                    'servings' => $portions,
                ];
                $added++;

                /*
                 * A batch's second day is a second helping out of the same pot,
                 * and `PlannedIngredients` scales the shopping by the portions
                 * both entries add up to — so it is food that has to be paid for
                 * like any other, not a free repeat.
                 */
                $this->spend($this->factFor($recipe->id), intdiv($portions, max($this->peopleEating, 1)));

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

        return [
            'added' => $added,
            'skipped' => $skipped,
            'empty' => $empty,
            /*
             * Reported only when the week actually ended over. The counter rises
             * whenever a meal had to break the allowance *at that moment*, and
             * on a real week that fires a dozen times while the total comes in
             * comfortably under — the allowance recovers every time a cheap dish
             * is chosen. Quoting the raw count told somebody their 350 zł week
             * had blown the budget twelve times over when it cost 283 zł.
             */
            'overspent' => $this->budgetLeft !== null && $this->budgetLeft < 0 ? $this->overspent : 0,
        ];
    }

    /**
     * Swap one planned meal for another of the same kind.
     *
     * The same rules as filling a week, applied to one slot: nothing eaten
     * within the month around it, the kitchen first. The dish being replaced is
     * inside that window, so it cannot be handed back — which is the whole point
     * of pressing the button.
     *
     * **What is kept is the meal's calories, not its portions.** The portions
     * were worked out for the dish being replaced, so carrying them over to a
     * dish of a different size quietly changes what the day feeds you: swap a
     * 200 kcal portion for a 500 kcal one at the same four portions and the day
     * gains 1 200 calories without saying so. The replacement is chosen to land
     * on the same total instead, and its portions are recomputed to get there —
     * so the week's numbers still hold after somebody changes their mind about
     * Tuesday.
     *
     * A dish nobody can count keeps the old behaviour: any suggestion of the
     * right meal, portions unchanged. There is nothing to preserve.
     *
     * Null when the meal has nothing left to offer, and then nothing changes.
     */
    public function swap(User $user, MealPlanEntry $entry): ?RecipeCandidate
    {
        $this->used = [];
        $this->known = [];
        $this->eaten = [];
        $this->eatenToday = [];
        $this->budgetLeft = null;
        $this->mealsLeft = 0;

        $date = $entry->date->toDateString();

        $this->alreadyPlanned($user, [$date]);
        $this->rememberTheDay($user, $entry);

        $targets = $this->sameMealAgain($entry);
        $this->peopleEating = $targets === null ? 1 : $targets->people;

        $recipe = $this->next(
            $this->poolFor($entry->slot, $this->shortfallFor($user), $targets),
            $entry->slot,
            $date,
            $targets,
        );

        if ($recipe === null) {
            return null;
        }

        $entry->update([
            'recipe_id' => $recipe->id,
            'servings' => $targets === null
                ? $entry->servings
                : $this->portionsFor($recipe, $entry->slot, $targets),
        ]);

        return $recipe;
    }

    /**
     * The dishes that could take this meal's place, best first.
     *
     * The same machinery the shuffle uses, handed back as a list instead of a
     * single answer. That is the whole difference: pressing shuffle is fine when
     * anything will do, and useless when somebody has an opinion about Tuesday.
     *
     * Every candidate carries the portions that reach the **same meal**, not the
     * portions the outgoing dish had, so a lighter dish is offered as more of it
     * and the day's calories survive whichever one is picked.
     *
     * @return list<MealAlternative>
     */
    public function alternativesFor(User $user, MealPlanEntry $entry, int $limit = 12): array
    {
        $this->used = [];
        $this->known = [];
        $this->eaten = [];
        $this->eatenToday = [];
        $this->budgetLeft = null;
        $this->mealsLeft = 0;

        $date = $entry->date->toDateString();

        $this->alreadyPlanned($user, [$date]);
        $this->rememberTheDay($user, $entry);

        $targets = $this->sameMealAgain($entry);
        $this->peopleEating = $targets === null ? 1 : $targets->people;

        $pool = $this->poolFor($entry->slot, $this->shortfallFor($user), $targets)
            ->reject(fn (RecipeCandidate $recipe): bool => isset($this->used[$recipe->id]))
            /*
             * The day's other meals are pushed down rather than hidden. Somebody
             * who wants eggs again after being warned should be able to have
             * them — the rule exists to stop the generator choosing that way, not
             * to stop a person.
             */
            ->sortBy(fn (RecipeCandidate $recipe): int => $this->repeats($recipe, $entry->slot, $date) ? 1 : 0)
            ->take($limit);

        return $this->describe($pool, $entry, $targets, $user);
    }

    /**
     * Turn a pool into something a screen can show a person.
     *
     * @param  Collection<int, RecipeCandidate>  $pool
     * @return list<MealAlternative>
     */
    private function describe(Collection $pool, MealPlanEntry $entry, ?PlanTargets $targets, User $user): array
    {
        $ids = array_values($pool->map(static fn (RecipeCandidate $recipe): int => $recipe->id)->all());

        if ($ids === []) {
            return [];
        }

        $recipes = Recipe::query()->whereIn('id', $ids)->get(['id', 'slug', 'title', 'image_url'])->keyBy('id');
        $missing = $this->shortfallFor($user);

        $alternatives = [];

        foreach ($ids as $id) {
            $recipe = $recipes->get($id);
            $fact = $this->factFor($id);

            if ($recipe === null || $fact === null) {
                continue;
            }

            $alternatives[] = new MealAlternative(
                recipeId: $id,
                slug: $recipe->slug,
                title: $recipe->title,
                imageUrl: $recipe->image_url,
                servings: $targets === null
                    ? $entry->servings
                    : $this->helpingsFor($fact, $targets->kcalFor($entry->slot)) * $targets->people,
                kcalPerPortion: $fact->kcalPerPortion,
                proteinPerPortion: $fact->proteinPerPortion,
                costPerPortion: $fact->costPerPortion,
                missing: $missing === [] ? null : ($missing[$id] ?? 0),
            );
        }

        return $alternatives;
    }

    /**
     * Put a chosen dish in a planned meal's place.
     *
     * The portions are worked out here rather than taken from the screen, for
     * the reason the whole feature exists: the number that keeps the day honest
     * depends on how big the new dish's portion is, and a browser is not where
     * that should be decided.
     */
    public function replace(User $user, MealPlanEntry $entry, Recipe $recipe): void
    {
        $this->known = [];
        $targets = $this->sameMealAgain($entry);
        $this->peopleEating = $targets === null ? 1 : $targets->people;

        $fact = $targets === null ? null : $this->factFor($recipe->id);

        // No target and no figure both mean the same thing here: nothing to
        // preserve, so the portions that were planned stay as they were.
        $servings = $targets === null || $fact === null
            ? $entry->servings
            : $this->helpingsFor($fact, $targets->kcalFor($entry->slot)) * $targets->people;

        $entry->update(['recipe_id' => $recipe->id, 'servings' => $servings]);
    }

    /**
     * The outgoing meal expressed as something to aim at.
     *
     * A one-meal target carrying the calories the meal actually delivered —
     * the portion figure times the portions planned. What comes back is a dish
     * that reaches the same total, with its own portions worked out to get
     * there.
     *
     * Null when the dish being replaced has no calorie figure — there is no
     * total to preserve, and inventing one would be worse than not trying.
     */
    private function sameMealAgain(MealPlanEntry $entry): ?PlanTargets
    {
        $recipe = $entry->recipe;

        if ($recipe === null || $entry->servings < 1) {
            return null;
        }

        $fact = $this->facts->forRecipes([$recipe->id])[$recipe->id] ?? null;
        $kcal = $fact === null ? null : $fact->kcalPerPortion;

        if ($kcal === null || $kcal <= 0) {
            return null;
        }

        /*
         * One "person" eating the **whole** meal, so the portions are free to
         * move: a dish half the size comes back as twice as many, and the total
         * lands where it was. Aiming at the outgoing *portion* instead was the
         * first attempt and it only ever found dishes of the same portion size —
         * which is a much smaller thing to offer somebody who wants a change.
         */
        return new PlanTargets(
            people: 1,
            kcalPerPerson: max(1, (int) round($kcal * $entry->servings)),
            shares: [$entry->slot->value => 1.0],
        );
    }

    /**
     * What the rest of that day is already made of.
     *
     * Without this a swap can hand back the third egg dish of the day — the
     * generator refuses to build a day that way, and a swap that could is the
     * same mistake with a button on it.
     */
    private function rememberTheDay(User $user, MealPlanEntry $entry): void
    {
        $sameDay = MealPlanEntry::query()
            ->where('user_id', $user->id)
            ->onDates([$entry->date->toDateString()])
            ->whereKeyNot($entry->getKey())
            ->whereNotNull('recipe_id')
            ->pluck('recipe_id')
            ->all();

        foreach ($this->facts->forRecipes(array_values(array_map(intval(...), $sameDay))) as $fact) {
            if ($fact->dominantIngredientId !== null) {
                $this->eatenToday[$entry->date->toDateString()][$fact->dominantIngredientId] = true;
            }
        }
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
    private function poolFor(MealSlot $slot, array $missing, ?PlanTargets $targets): Collection
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

        if ($missing !== []) {
            $candidates = $candidates
                ->sortBy(static fn (RecipeCandidate $recipe): int => $missing[$recipe->id] ?? PHP_INT_MAX)
                ->values();
        } else {
            // Nothing on the shelves to prefer by, so prefer nothing.
            $candidates = $candidates->shuffle()->values();
        }

        if ($targets === null) {
            return $candidates->take(self::POOL)->shuffle()->values();
        }

        return $this->fittingPool($candidates, $slot, $targets);
    }

    /**
     * The same pool narrowed to what can actually hit this meal's calories.
     *
     * Two thirds of the shortlist is thrown away here, and it has to be: most of
     * what a catalogue offers for a given meal is either uncountable or the
     * wrong size, and a plan that ignored that would hit its target by accident
     * or not at all.
     *
     * **The kitchen still comes first, but it no longer comes only.** Candidates
     * arrive already ordered by what the fridge covers; the fit and the price
     * re-rank them within that window rather than replacing the order, so "cook
     * what you have" survives being given a calorie target.
     *
     * @param  Collection<int, RecipeCandidate>  $candidates
     * @return Collection<int, RecipeCandidate>
     */
    private function fittingPool(Collection $candidates, MealSlot $slot, PlanTargets $targets): Collection
    {
        $shortlist = $this->shortlist($candidates);

        // `array_values`, not `Collection::values()`: `map()` keeps the keys a
        // filter or a sort left behind, and a gap in them is not a list.
        $facts = $this->facts->forRecipes(array_values(
            $shortlist->map(static fn (RecipeCandidate $recipe): int => $recipe->id)->all(),
        ));

        $this->known += $facts;

        $wanted = $targets->kcalFor($slot);

        /*
         * Ranked by how close a whole number of portions lands to the target,
         * and — when there is a budget — by what those portions cost. A dish
         * nobody has priced scores as neither cheap nor dear rather than last:
         * refusing the unpriced would narrow ten thousand recipes down to
         * whatever happened to appear in a leaflet.
         */
        $fitting = $shortlist
            ->filter(fn (RecipeCandidate $recipe): bool => $this->fits($facts[$recipe->id] ?? null, $wanted, $slot));

        $balanced = $this->balanced($fitting, $facts);
        $scores = [];

        foreach ($balanced as $recipe) {
            $scores[$recipe->id] = $this->score($facts[$recipe->id], $wanted, $targets);
        }

        return $this->variedByRank(
            $balanced
                ->sortBy(static fn (RecipeCandidate $recipe): float => $scores[$recipe->id])
                ->take(self::POOL),
            $scores,
        );
    }

    /**
     * The ones that clear the protein floor — unless too few do.
     *
     * A floor rather than a preference, because a preference lost. Weighted into
     * the score it was outvoted by price and fit, and the week came back as
     * pancakes on three mornings and potato cakes on four evenings: each
     * individually only a little under, all of them together a diet.
     *
     * **The fallback is the honest half.** Some meals genuinely have little to
     * offer — the catalogue's podwieczorek is mostly cake — and a hard floor
     * there would leave the slot empty, which is worse than a low-protein snack.
     * So when fewer than `MIN_BALANCED` dishes clear it, the floor is dropped for
     * that meal and the score's weighting decides as before. A dish whose protein
     * nobody recorded is let through either way: unknown is not zero.
     *
     * @param  Collection<int, RecipeCandidate>  $fitting
     * @param  array<int, RecipeFact>  $facts
     * @return Collection<int, RecipeCandidate>
     */
    private function balanced(Collection $fitting, array $facts): Collection
    {
        $balanced = $fitting->filter(static function (RecipeCandidate $recipe) use ($facts): bool {
            $share = ($facts[$recipe->id] ?? null)?->proteinShare();

            return $share === null || $share >= self::MIN_PROTEIN_SHARE;
        });

        return $balanced->count() >= self::MIN_BALANCED ? $balanced : $fitting;
    }

    /**
     * Shuffled, but only among dishes that really did score about the same.
     *
     * A plain `shuffle()` after a `sortBy()` throws the ranking away, which is
     * not subtle: with the pool shuffled whole, the meal is chosen at random
     * from eighty candidates and every rule above — the calorie fit, the protein
     * floor, the price — decides nothing at all.
     *
     * The first attempt at fixing that shuffled inside runs of six **by
     * position**, and it was wrong in a way that only showed up intermittently:
     * position says nothing about score, so two dishes a chasm apart land in the
     * same run whenever there are few candidates, and the choice between them
     * goes back to a coin toss. It passed on its own and failed in the full
     * suite, which is exactly how a flaky rule announces itself.
     *
     * Grouping by score fixes the thing the runs were meant to do. Dishes within
     * `VARIETY_BAND` of the one heading their group are interchangeable, so
     * shuffling them is free variety; anything scoring worse than that stays
     * behind them.
     *
     * @param  Collection<int, RecipeCandidate>  $ranked  already sorted, best first
     * @param  array<int, float>  $scores
     * @return Collection<int, RecipeCandidate>
     */
    private function variedByRank(Collection $ranked, array $scores): Collection
    {
        $varied = [];
        $group = [];
        $leader = null;

        // Built by hand rather than with `flatten()`, which loses what the
        // collection holds and leaves the caller with an untyped pool.
        foreach ($ranked as $recipe) {
            $score = $scores[$recipe->id] ?? 0.0;

            if ($leader !== null && $score - $leader > self::VARIETY_BAND) {
                $varied = [...$varied, ...$this->shuffled($group)];
                $group = [];
                $leader = null;
            }

            $leader ??= $score;
            $group[] = $recipe;
        }

        return new Collection([...$varied, ...$this->shuffled($group)]);
    }

    /**
     * @param  list<RecipeCandidate>  $group
     * @return list<RecipeCandidate>
     */
    private function shuffled(array $group): array
    {
        shuffle($group);

        return $group;
    }

    /**
     * The candidates that get a calorie, a price and a protein figure.
     *
     * **Half from what the fridge covers, half from everywhere else**, and the
     * second half is not decoration. Ranking by the kitchen first and only then
     * looking at nutrition sounds harmless and is not: a starter cupboard holds
     * flour, potatoes and eggs, so the best-covered recipes for every meal are
     * potato and flour dishes, and the nutrition scoring never sees anything
     * else to prefer. The first live run of this produced placki ziemniaczane on
     * four days out of seven and 75 g of protein a day against a 94 g floor —
     * not because the rule was too weak, but because the shortlist it ran on had
     * already been decided.
     *
     * So the fridge still gets half the say, which keeps "cook what you have"
     * meaningful, and the rest of the catalogue gets the other half so there is
     * something to choose between.
     *
     * @param  Collection<int, RecipeCandidate>  $candidates
     * @return Collection<int, RecipeCandidate>
     */
    private function shortlist(Collection $candidates): Collection
    {
        $covered = $candidates->take(intdiv(self::TARGETED_POOL, 2));

        $rest = $candidates
            ->skip(intdiv(self::TARGETED_POOL, 2))
            ->shuffle()
            ->take(self::TARGETED_POOL - $covered->count());

        return $covered->concat($rest)->values();
    }

    /**
     * Whether a whole number of helpings of this lands near the meal's calories.
     *
     * Asked of the **outcome**, not of the portion. An earlier version checked
     * the portion against a band — at least a third of the meal, at most a
     * quarter over — and that let through dishes that could not land anywhere
     * near it: a 460 kcal portion against a 625 kcal supper is one helping and
     * 26% short, or two and 47% over, and the band was happy with both. Measured
     * over a real week the worst meal came out 33% under.
     *
     * Judging the best achievable helping instead makes the rule say what it
     * means, and it subsumes the band: a portion under a third of the meal
     * cannot reach it in three helpings, and one far over it cannot help but
     * overshoot.
     */
    private function fits(?RecipeFact $fact, float $wanted, MealSlot $slot): bool
    {
        if ($fact === null || ! $this->isSubstantial($fact, $slot)) {
            return false;
        }

        $miss = $this->bestMiss($fact, $wanted);

        return $miss !== null && $miss <= self::MAX_MISS;
    }

    /**
     * Whether this is a dish rather than a component.
     *
     * A snack is exempt: chipsy and a piece of fruit are what a podwieczorek is
     * for, and demanding two ingredients of it would empty the pool for the one
     * meal the catalogue is already thinnest on.
     */
    private function isSubstantial(RecipeFact $fact, MealSlot $slot): bool
    {
        return $slot === MealSlot::Snack
            || $fact->realIngredients >= self::MIN_REAL_INGREDIENTS;
    }

    /**
     * How far the closest whole number of helpings lands from the target, as a
     * share of it. Null when the dish has no calorie figure at all.
     *
     * Whole helpings because that is what a person eats. Nobody serves 1.4
     * portions, so a plan that quietly assumed one would be describing a meal
     * that never happens.
     */
    private function bestMiss(?RecipeFact $fact, float $wanted): ?float
    {
        $kcal = $fact?->kcalPerPortion;

        if ($kcal === null || $kcal <= 0) {
            return null;
        }

        $best = null;

        for ($helpings = 1; $helpings <= self::MAX_PORTIONS_EACH; $helpings++) {
            $miss = abs($helpings * $kcal - $wanted) / max($wanted, 1.0);
            $best = $best === null ? $miss : min($best, $miss);
        }

        return $best;
    }

    /**
     * Lower is better: how far a whole number of portions lands from the target,
     * plus what it costs when money is being counted.
     */
    private function score(RecipeFact $fact, float $wanted, PlanTargets $targets): float
    {
        $each = $this->helpingsFor($fact, $wanted);

        $miss = ($this->bestMiss($fact, $wanted) ?? 1.0) + $this->proteinPenalty($fact);

        if ($targets->budget === null || $fact->costPerPortion === null) {
            return $miss;
        }

        /*
         * A weighting rather than a second sort, so neither can shut the other
         * out: a perfect calorie fit costing three times the going rate loses to
         * a near fit that does not, and the cheapest thing in the catalogue
         * cannot win a slot it does not feed.
         */
        $costPerHead = $fact->costPerPortion->toZloty() * $each;

        return $miss + self::COST_WEIGHT * ($costPerHead / max($this->allowancePerHelping(), 0.01));
    }

    /**
     * How far short of the protein floor this dish falls, as a score penalty.
     *
     * Zero for a dish that clears the floor, and **zero for a dish whose protein
     * nobody recorded**: an unknown is not a shortfall, and treating it as one
     * would quietly bar every product the dictionary has not fully broken down
     * from ever being planned — the same "null means stay quiet" rule the rest of
     * this app runs on.
     */
    private function proteinPenalty(RecipeFact $fact): float
    {
        $share = $fact->proteinShare();

        return $share === null
            ? 0.0
            : self::PROTEIN_WEIGHT * max(0.0, self::MIN_PROTEIN_SHARE - $share);
    }

    /**
     * How many portions one person eats of this dish, as a whole number.
     *
     * At least one — nobody plans half a person's dinner — and never more than
     * the band allows, so a rounding error cannot turn a light snack into eight
     * helpings.
     */
    private function helpingsFor(RecipeFact $fact, float $wanted): int
    {
        $each = $fact->portionsFor($wanted) ?? 1.0;

        return min(max((int) round($each), 1), self::MAX_PORTIONS_EACH);
    }

    /**
     * How many portions the whole meal is planned for.
     *
     * The stored `servings` is portions of the recipe, not people, which is what
     * `PlannedIngredients` scales the shopping by — so a household of two eating
     * two helpings each is four.
     */
    private function portionsFor(RecipeCandidate $recipe, MealSlot $slot, PlanTargets $targets): int
    {
        $fact = $this->factFor($recipe->id);

        if ($fact === null) {
            return $targets->people;
        }

        return $this->helpingsFor($fact, $targets->kcalFor($slot)) * $targets->people;
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
     * Facts about the recipes this generation has already looked at.
     *
     * The pool works them out for a slot in one read; the row that is written
     * then needs the same figures again to decide how many portions to plan.
     * Asking twice would be a query per meal for an answer already in hand.
     *
     * @var array<int, RecipeFact>
     */
    private array $known = [];

    /**
     * How many meals this run will actually fill.
     *
     * The meals **being filled**, not the days asked for: a week where four
     * dinners are already planned by hand has three left to spend on, and
     * spreading the budget over seven would plan three meals as though they were
     * cheap and then blow the total.
     *
     * @param  array<string, list<MealSlot>>  $wanted
     * @param  array<string, array<string, true>>  $taken
     */
    private function mealsToFill(array $wanted, array $taken): int
    {
        $meals = 0;

        foreach ($wanted as $date => $slots) {
            foreach ($slots as $slot) {
                if (! isset($taken[(string) $date][$slot->value])) {
                    $meals++;
                }
            }
        }

        return $meals;
    }

    /**
     * What one person's helping of the meal being chosen right now may cost.
     *
     * Everything still unspent, spread evenly over everything still unplanned.
     * It rises when a cheap dish is chosen and falls when a dear one is, which
     * is what makes the total hold rather than merely being aimed at.
     */
    private function allowancePerHelping(): float
    {
        if ($this->budgetLeft === null || $this->mealsLeft < 1) {
            return INF;
        }

        return max($this->budgetLeft, 0.0) / max($this->mealsLeft * $this->peopleEating, 1);
    }

    /**
     * Take one meal's worth of money off what is left.
     */
    private function spend(?RecipeFact $fact, int $helpings): void
    {
        if ($this->mealsLeft > 0) {
            $this->mealsLeft--;
        }

        if ($this->budgetLeft === null || $fact?->costPerPortion === null) {
            return;
        }

        $this->budgetLeft -= $fact->costPerPortion->toZloty() * $helpings * $this->peopleEating;
    }

    /**
     * A recipe's calories and cost, from what this generation already read.
     */
    private function factFor(int $recipeId): ?RecipeFact
    {
        if (! array_key_exists($recipeId, $this->known)) {
            $this->known[$recipeId] = $this->facts->forRecipes([$recipeId])[$recipeId] ?? null;
        }

        return $this->known[$recipeId];
    }

    /**
     * What is left of the budget, in złoty, and how many meals still have to
     * come out of it.
     *
     * Kept as a running pair rather than a fixed share per meal, because a fixed
     * share cannot bind: it has no way to know that Monday's dinner came in
     * cheap, so either it forbids Tuesday something affordable or it lets every
     * meal spend the average and lands over the total. Spending against what is
     * genuinely left is the only version of this that keeps a promise.
     *
     * Null budget means nobody set one, and then none of this applies.
     */
    private ?float $budgetLeft = null;

    private int $mealsLeft = 0;

    private int $peopleEating = 1;

    /**
     * Meals that had nothing affordable left to offer.
     *
     * The week is still planned — going hungry is not a budgeting strategy — but
     * the count travels back so the screen can say the target was tight rather
     * than quietly presenting a week that costs more than was asked.
     *
     * **Judged on what the week eats, not on what it buys**, and that asymmetry
     * is deliberate. A candidate's price can only be the cost of its ingredients
     * as written: what the cupboard already covers is a fact about the week as a
     * whole, worked out once by `PlannedIngredients::toBuy()` after the last meal
     * is chosen, and there is no per-dish version of it that would not be a
     * guess. So the allowance is spent against the larger of the two numbers.
     *
     * That errs the safe way round. Shopping is never more than eating, so a
     * week that fits the budget here cannot break it at the till — and a count
     * above zero means "there was nothing cheap enough left by this measure",
     * which is a warning about the target, not a claim that the plan is over.
     * The screen says both figures, so the difference is visible rather than
     * argued about.
     */
    private int $overspent = 0;

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
     * The next dish for this meal: the best-ranked one the budget still allows.
     *
     * With no budget this is simply the front of the pool, which is where the
     * fridge and the calorie fit already put the best candidate.
     *
     * With one, the pool is scanned for the first dish whose helpings come in
     * under what is left per meal. **When nothing does, a dish is still chosen** —
     * the cheapest on offer — and the meal is counted as overspent. Refusing to
     * plan is not a budgeting strategy: a week with a hole in Thursday is worse
     * than a week that costs twelve złoty more and says so.
     *
     * @param  Collection<int, RecipeCandidate>  $pool
     */
    private function next(Collection $pool, MealSlot $slot, string $date, ?PlanTargets $targets): ?RecipeCandidate
    {
        $available = $pool->reject(fn (RecipeCandidate $recipe): bool => isset($this->used[$recipe->id]));

        if ($available->isEmpty()) {
            return null;
        }

        $fresh = $available->reject(
            fn (RecipeCandidate $recipe): bool => $this->repeats($recipe, $slot, $date),
        );

        // Everything left is something this meal has already had this week. A
        // repeat beats a hole in the week, so the rule yields rather than the
        // meal going unplanned.
        $available = $fresh->isEmpty() ? $available : $fresh;

        if ($targets?->budget === null) {
            return $this->take($pool, $available->first(), $slot, $date);
        }

        $allowance = $this->allowancePerHelping();
        $wanted = $targets->kcalFor($slot);

        $affordable = $available->first(
            fn (RecipeCandidate $recipe): bool => $this->costPerHelping($recipe, $wanted) <= $allowance,
        );

        if ($affordable !== null) {
            return $this->take($pool, $affordable, $slot, $date);
        }

        $this->overspent++;

        return $this->take($pool, $available->sortBy(
            fn (RecipeCandidate $recipe): float => $this->costPerHelping($recipe, $wanted),
        )->first(), $slot, $date);
    }

    /**
     * What one person's share of this dish would cost at this meal.
     *
     * A dish nobody has priced counts as costing nothing here, and that is the
     * deliberate choice: the alternative is to treat "unknown" as "expensive",
     * which would quietly exclude two thirds of the catalogue from every
     * budgeted week and leave the household eating whatever last week's leaflet
     * happened to mention. What it cannot be priced at is reported instead —
     * see `WeekPrice::confidence()`.
     */
    private function costPerHelping(RecipeCandidate $recipe, float $wanted): float
    {
        $fact = $this->factFor($recipe->id);

        if ($fact?->costPerPortion === null) {
            return 0.0;
        }

        return $fact->costPerPortion->toZloty() * $this->helpingsFor($fact, $wanted);
    }

    /**
     * @param  Collection<int, RecipeCandidate>  $pool
     */
    private function take(Collection $pool, ?RecipeCandidate $recipe, MealSlot $slot, string $date): ?RecipeCandidate
    {
        if ($recipe === null) {
            return null;
        }

        $this->used[$recipe->id] = true;

        $dominant = $this->factFor($recipe->id)?->dominantIngredientId;

        if ($dominant !== null) {
            $this->eaten[$slot->value][$dominant] = true;
            $this->eatenToday[$date][$dominant] = true;
        }

        return $recipe;
    }

    /**
     * Whether this meal has already had a dish made of the same thing this week.
     *
     * **"Nothing repeats within a month" is a rule about recipe rows, and a week
     * is not eaten by rows.** The browser test of the finished generator came
     * back with five different pancake recipes across seven breakfasts — every
     * one a distinct id, none of them a repeat by the old rule, and to anybody
     * sitting at the table it was pancakes every morning.
     *
     * What a dish is *made of* is the thing that repeats, so the test is the
     * product it draws most of its calories from. It is scoped per meal: eggs at
     * breakfast and eggs in a supper omelette are not the same complaint.
     */
    private function repeats(RecipeCandidate $recipe, MealSlot $slot, string $date): bool
    {
        $dominant = $this->factFor($recipe->id)?->dominantIngredientId;

        if ($dominant === null) {
            return false;
        }

        return isset($this->eaten[$slot->value][$dominant])
            || isset($this->eatenToday[$date][$dominant]);
    }

    /**
     * What each meal has already been built from this week.
     *
     * @var array<string, array<int, true>>
     */
    private array $eaten = [];

    /**
     * And what each *day* has already been built from.
     *
     * The week rule alone is not enough, and a browser found why: it is scoped
     * per meal, so eggs at breakfast said nothing about eggs at lunch, and a
     * generated Thursday came back as jajecznica, zapiekanka jajeczna and
     * suflet jajeczny — three egg dishes between waking up and going to bed.
     * Each was a fine dish and no rule had been broken.
     *
     * @var array<string, array<int, true>>
     */
    private array $eatenToday = [];
}
