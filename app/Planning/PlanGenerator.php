<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Enums\RecipeVerdict;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\RecipePreference;
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
    private const int TARGETED_POOL = 400;

    /** Second helpings, yes; eight helpings, no. */
    private const int MAX_PORTIONS_EACH = 3;

    /** How many Polish classics every obiad shortlist is sure to look at. */
    private const int CLASSICS_IN_SHORTLIST = 80;

    /**
     * How many of the dishes built most on this week's offers every shortlist
     * is sure to look at, when the week is being bought in one shop.
     *
     * The shortlist is otherwise half the fridge and half chance, and out of
     * five thousand obiady chance finds the thirty that use the cheap chicken
     * about never — the ranking cannot prefer what it never sees.
     */
    private const int PROMOTED_IN_SHORTLIST = 120;

    /**
     * How much each product on offer lifts a dish, up to `PROMOTED_CAP` of them.
     *
     * A nudge on top of the price, which already falls with the offer: the
     * price says "this is cheaper", the lift says "and you are standing in that
     * shop anyway". Two products on offer weigh about as much as a chicken
     * dinner the day after a chicken lunch costs — enough to win a close call,
     * never enough to serve the same discounted chicken four times.
     */
    private const float PROMOTED_LIFT = 0.07;

    private const int PROMOTED_CAP = 3;

    /**
     * How much the price counts when the week is planned around a shop but
     * nobody set a budget: "as cheap as it can be" is a direction, not a
     * ceiling, so the cost is weighed against the meal's typical dish rather
     * than against an allowance that does not exist.
     */
    private const float SHOP_COST_WEIGHT = 0.3;

    /**
     * The kinds of dish both reviewers counted on their fingers: naleśniki for
     * breakfast and again for supper, three egg dishes in four days. Once a
     * day at most, and each one more in the week costs twice what another
     * salad does.
     */
    private const array ONCE_A_DAY = ['nalesniki', 'jajka'];

    /**
     * How many soup obiady a week is pulled towards. Both reviewers of every
     * generated round counted one or two and called it "not a Polish week";
     * a pot eaten on two days counts twice, as it is two soup obiady.
     */
    private const int SOUPS_A_WEEK = 3;

    /**
     * A soup served as the whole obiad has to be a meal: three bowls of rosół
     * each was "a 2 500-calorie day" on paper and, in the reviewer's words,
     * someone going through the cupboards at four. Grochówka and gulaszowa
     * reach the meal in two; a broth that needs three sinks well below them.
     */
    private const float THIN_SOUP_PENALTY = 0.12;

    /** What a third helping costs a dish in the ranking — see `score()`. */
    private const float THIRD_HELPING_PENALTY = 0.18;

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
        private readonly Season $season,
        private readonly DishFamily $families,
        private readonly SideDishes $sides,
    ) {}

    /**
     * Forget everything a previous call worked out. One instance may be asked
     * to fill a week and then to swap a meal in the same request.
     */
    private function reset(User $user): void
    {
        $this->offers = null;
        $this->promotedCount = [];
        $this->typicalCost = [];
        $this->liked = [];
        $this->disliked = [];

        foreach (RecipePreference::query()->of($user)->get(['recipe_id', 'verdict']) as $preference) {
            if ($preference->verdict === RecipeVerdict::Like) {
                $this->liked[$preference->recipe_id] = true;
            } else {
                $this->disliked[$preference->recipe_id] = true;
            }
        }

        $this->used = [];
        $this->known = [];
        $this->eaten = [];
        $this->eatenToday = [];
        $this->scores = [];
        $this->themesOn = [];
        $this->themeCount = [];
        $this->formCount = [];
        $this->familiesOn = [];
        $this->familyCount = [];
        $this->familyWeek = [];
        $this->dominantWeek = [];
        $this->meatOn = [];
        $this->airFryerMeals = 0;
        $this->cuisineWeek = [];
        $this->usedSignatures = [];
        $this->produceWeek = [];
        $this->sideCount = [];
        $this->soupKinds = [];
        $this->soupObiad = [];
        $this->richWeek = 0;
        $this->styleWeek = [];
    }

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
     * @param  ShopOffers|null  $offers  the one shop the week will be bought in —
     *                                   its offers price the dishes and lift the
     *                                   ones built on them
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
        ?ShopOffers $offers = null,
    ): array {
        $this->reset($user);
        $this->offers = $offers === null || $offers->isEmpty() ? null : $offers;
        $this->promotedCount = $this->offers === null ? [] : $this->promotedCounts($this->offers);
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
            $repeatedPlate = null;

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

                // The second day of a batch is the same plate: the potatoes come
                // out of the same pot as the gulasz.
                $plate = $repeatUntil === $index && $repeatedPlate !== null
                    ? $repeatedPlate
                    : $this->plate($recipe, $slot, (string) $date, $targets, $servings);
                $portions = $plate['portions'];

                // The soup first, so the day reads in the order it is eaten.
                if ($plate['starter'] !== null) {
                    $people = $targets === null ? $servings : $targets->people;
                    $rows[] = [
                        'user_id' => $user->id,
                        'date' => $date,
                        'slot' => $slot->value,
                        'recipe_id' => $plate['starter'],
                        'servings' => $people,
                    ];
                    $this->spend($this->factFor($plate['starter']), intdiv($people, max($this->peopleEating, 1)));
                    $this->formCount['zupy'] = ($this->formCount['zupy'] ?? 0) + 1;
                    $this->used[$plate['starter']] = true;
                    $this->usedSignatures[] = $this->factFor($plate['starter'])->signature ?? [];
                    $this->rememberSoup($this->factFor($plate['starter']));
                    $this->soupObiad[(string) $date] = true;
                }

                $rows[] = [
                    'user_id' => $user->id,
                    'date' => $date,
                    'slot' => $slot->value,
                    'recipe_id' => $recipe->id,
                    'servings' => $portions,
                ];
                $added++;

                foreach ($plate['sides'] as $side) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'date' => $date,
                        'slot' => $slot->value,
                        'recipe_id' => $side['id'],
                        'servings' => $side['portions'],
                    ];
                    $this->spend($this->factFor($side['id']), intdiv($side['portions'], max($this->peopleEating, 1)));
                }

                /*
                 * A batch's second day is a second helping out of the same pot,
                 * and `PlannedIngredients` scales the shopping by the portions
                 * both entries add up to — so it is food that has to be paid for
                 * like any other, not a free repeat.
                 */
                $this->spend($this->factFor($recipe->id), intdiv($portions, max($this->peopleEating, 1)));

                /*
                 * Only the obiad is cooked once and eaten twice. Applied to every
                 * meal it put the same sandwich on two breakfasts running and the
                 * same porridge on two suppers — food nobody cooks ahead, repeated
                 * for no reason, which is exactly what reads as a dull week.
                 */
                $isBatch = $slot === MealSlot::Lunch
                    && ($recipe->isMealPrep || $this->feedsTwice($recipe, $portions) || $this->isPot($recipe))
                    && ($this->factFor($recipe->id)?->reheatsWell() ?? true)
                    && $repeatUntil !== $index
                    && ! $this->isWeekendDate($dates[$index + 1] ?? null);

                if ($repeatUntil === $index) {
                    $this->remember($recipe, $slot, (string) $date);
                }
                $repeatUntil = $isBatch ? $index + 1 : null;
                $repeated = $recipe;
                $repeatedPlate = $plate;
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
        $this->reset($user);
        $this->budgetLeft = null;
        $this->mealsLeft = 0;

        $date = $entry->date->toDateString();

        $this->alreadyPlanned($user, [$date]);
        $this->rememberTheDay($user, $entry);

        $kind = $this->sideKindOf($entry);

        if ($kind !== null) {
            $side = $this->otherSides($entry, $kind)[0] ?? null;

            if ($side === null) {
                return null;
            }

            $entry->update(['recipe_id' => $side]);

            return new RecipeCandidate($side, false);
        }

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
        $this->reset($user);
        $this->budgetLeft = null;
        $this->mealsLeft = 0;

        $date = $entry->date->toDateString();

        $this->alreadyPlanned($user, [$date]);
        $this->rememberTheDay($user, $entry);

        $kind = $this->sideKindOf($entry);

        if ($kind !== null) {
            $sides = collect(array_slice($this->otherSides($entry, $kind), 0, $limit))
                ->map(static fn (int $id): RecipeCandidate => new RecipeCandidate($id, false));

            return $this->describe($sides, $entry, null, $user);
        }

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
                    : $this->helpingsFor($fact, $targets->kcalFor($entry->slot), $entry->slot) * $targets->people,
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

        // One potato for another: a side is one helping each, whatever it is.
        if ($this->sideKindOf($entry) !== null) {
            $entry->update(['recipe_id' => $recipe->id]);

            return;
        }

        $targets = $this->sameMealAgain($entry);
        $this->peopleEating = $targets === null ? 1 : $targets->people;

        $fact = $targets === null ? null : $this->factFor($recipe->id);

        // No target and no figure both mean the same thing here: nothing to
        // preserve, so the portions that were planned stay as they were.
        $servings = $targets === null || $fact === null
            ? $entry->servings
            : $this->helpingsFor($fact, $targets->kcalFor($entry->slot), $entry->slot) * $targets->people;

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
            ->select(['recipes.id', 'recipes.is_meal_prep', 'recipes.title'])
            ->get()
            ->map(fn (object $row): RecipeCandidate => new RecipeCandidate(
                (int) $row->id,
                (bool) $row->is_meal_prep,
                // Only the obiad is built on them; asking of every breakfast
                // title would cost a pass of regexes for nothing.
                $slot === MealSlot::Lunch && $this->families->isHomeClassic((string) $row->title),
            ))
            /*
             * Dropped here rather than when one is drawn: a month of dinners is
             * thirty dishes, and filtering after `take(POOL)` could empty a
             * window of eighty down to nothing while thousands of untried
             * recipes sat one row outside it.
             */
            ->reject(fn (RecipeCandidate $recipe): bool => isset($this->used[$recipe->id]) || isset($this->disliked[$recipe->id]));

        if ($missing !== []) {
            $candidates = $candidates
                ->sortBy(static fn (RecipeCandidate $recipe): int => $missing[$recipe->id] ?? PHP_INT_MAX)
                ->values();
        } else {
            // Nothing on the shelves to prefer by, so prefer nothing.
            $candidates = $candidates->shuffle()->values();
        }

        if ($targets === null) {
            return $this->plainPool($candidates, $slot, $missing);
        }

        return $this->fittingPool($candidates, $slot, $targets);
    }

    /**
     * The pool when nobody set a target: no calories to hit, but the same
     * idea of what a dish is.
     *
     * This used to be "the eighty the fridge covers best, shuffled", and that
     * is where the dull weeks came from — no rule about a dish ever reached
     * it, so a pot of plain groats was as good a dinner as anything. It now
     * reads the same facts the targeted pool does and lets the day decide
     * between them (`next()`); the kitchen keeps its say as a small head start.
     *
     * @param  Collection<int, RecipeCandidate>  $candidates
     * @param  array<int, int>  $missing
     * @return Collection<int, RecipeCandidate>
     */
    private function plainPool(Collection $candidates, MealSlot $slot, array $missing): Collection
    {
        $shortlist = $this->shortlist($candidates);

        $facts = $this->facts->forRecipes(array_values(
            $shortlist->map(static fn (RecipeCandidate $recipe): int => $recipe->id)->all(),
        ), $this->offers);

        $this->known += $facts;

        $pool = $shortlist->filter(
            fn (RecipeCandidate $recipe): bool => isset($facts[$recipe->id]) && $this->isSubstantial($facts[$recipe->id], $slot),
        );

        $this->typicalCost[$slot->value] = $this->typicalCostOf($pool, $facts, static fn (): int => 1);

        foreach ($pool as $recipe) {
            $short = $missing === [] ? 0 : min($missing[$recipe->id] ?? self::FRIDGE_CAP, self::FRIDGE_CAP);
            $this->scores[$slot->value][$recipe->id] = self::FRIDGE_WEIGHT * $short
                + $this->proteinPenalty($facts[$recipe->id])
                + $this->shopTerm($facts[$recipe->id], 1, $slot);
        }

        return $pool->values();
    }

    /** Past this many missing products the fridge has stopped helping. */
    private const int FRIDGE_CAP = 4;

    /** How much one missing product counts against a dish when there is no target. */
    private const float FRIDGE_WEIGHT = 0.04;

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
        ), $this->offers);

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

        $this->typicalCost[$slot->value] = $this->typicalCostOf(
            $balanced,
            $facts,
            fn (RecipeFact $fact): int => $this->helpingsFor($fact, $wanted, $slot),
        );

        foreach ($balanced as $recipe) {
            $scores[$recipe->id] = $this->score($facts[$recipe->id], $wanted, $targets, $slot);
        }

        $this->scores[$slot->value] = $scores;

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
        /*
         * What the household said it likes always gets a look. Out of five
         * thousand lunches a random half-shortlist would otherwise find a
         * favourite about once a month, which is not what "more of this" means.
         */
        $liked = $candidates->filter(fn (RecipeCandidate $recipe): bool => isset($this->liked[$recipe->id]));
        $candidates = $candidates->reject(fn (RecipeCandidate $recipe): bool => isset($this->liked[$recipe->id]))->values();

        /*
         * And so do the Polish classics, a slice of them each time. Drawn at
         * random from five thousand obiady they are a minority, and three
         * reviewed weeks in a row had not one schabowy, pierogi or gołąbki
         * between them — the ranking cannot prefer what it never sees.
         */
        $classics = $candidates->filter(static fn (RecipeCandidate $recipe): bool => $recipe->isHomeClassic)
            ->shuffle()
            ->take(self::CLASSICS_IN_SHORTLIST);
        $liked = $liked->concat($classics);
        $candidates = $candidates->reject(
            static fn (RecipeCandidate $recipe): bool => $classics->contains('id', $recipe->id),
        )->values();

        /*
         * And, when the week is bought in one shop, the dishes built most on
         * what that shop has on offer — ranked by how many of their products
         * are discounted, with chance settling ties so two weeks still differ.
         */
        if ($this->promotedCount !== []) {
            $promoted = $candidates
                ->filter(fn (RecipeCandidate $recipe): bool => isset($this->promotedCount[$recipe->id]))
                ->shuffle()
                ->sortByDesc(fn (RecipeCandidate $recipe): int => $this->promotedCount[$recipe->id])
                ->take(self::PROMOTED_IN_SHORTLIST);
            $liked = $liked->concat($promoted);
            $candidates = $candidates->reject(
                static fn (RecipeCandidate $recipe): bool => $promoted->contains('id', $recipe->id),
            )->values();
        }

        $covered = $candidates->take(intdiv(self::TARGETED_POOL, 2));

        $rest = $candidates
            ->skip(intdiv(self::TARGETED_POOL, 2))
            ->shuffle()
            ->take(self::TARGETED_POOL - $covered->count());

        return $liked->concat($covered)->concat($rest)->values();
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

        $miss = $this->bestMiss($fact, $wanted, $slot);

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
        /*
         * Nothing recognised at all is not "one ingredient", it is not knowing:
         * the same "unknown is not zero" rule the protein floor follows. A dish
         * planned against a calorie target never gets here without lines.
         */
        return $slot === MealSlot::Snack
            || $fact->realIngredients === 0
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
    private function bestMiss(?RecipeFact $fact, float $wanted, MealSlot $slot): ?float
    {
        $kcal = $fact?->kcalPerPortion;

        if ($kcal === null || $kcal <= 0) {
            return null;
        }

        $best = null;

        for ($helpings = 1; $helpings <= $this->maxHelpings($slot); $helpings++) {
            $miss = abs($helpings * $kcal - $wanted) / max($wanted, 1.0);
            $best = $best === null ? $miss : min($best, $miss);
        }

        return $best;
    }

    /**
     * Lower is better: how far a whole number of portions lands from the target,
     * plus what it costs when money is being counted.
     */
    private function score(RecipeFact $fact, float $wanted, PlanTargets $targets, MealSlot $slot): float
    {
        $each = $this->helpingsFor($fact, $wanted, $slot);

        /*
         * Three helpings each is allowed and should be the exception: a week
         * that read "6 porcji" of porridge for two people on four mornings out
         * of seven hit its calories and looked absurd. A dish that feeds the
         * meal in one or two helpings is preferred when it fits as well.
         */
        $miss = ($this->bestMiss($fact, $wanted, $slot) ?? 1.0)
            + $this->proteinPenalty($fact)
            + ($each >= self::MAX_PORTIONS_EACH ? self::THIRD_HELPING_PENALTY : 0.0)
            + ($each >= self::MAX_PORTIONS_EACH && $this->isSoup($fact) ? self::THIN_SOUP_PENALTY : 0.0);

        $miss += $this->shopTerm($fact, $each, $slot, $targets->budget === null);

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
    private function helpingsFor(RecipeFact $fact, float $wanted, MealSlot $slot): int
    {
        $each = $fact->portionsFor($wanted) ?? 1.0;

        return min(max((int) round($each), 1), $this->maxHelpings($slot));
    }

    /**
     * Breakfast and supper stop at two. "6 porcji owsianki" for two people is
     * what a third helping looks like on a morning, and "placki z wątróbką ×6"
     * at supper was the same mistake in the evening: both reviewers singled
     * them out. Those meals are eaten from one plate, and a dish that cannot
     * feed them in two is the wrong dish rather than a dish to triple.
     */
    private function maxHelpings(MealSlot $slot): int
    {
        return in_array($slot, [MealSlot::Breakfast, MealSlot::Dinner], true) ? 2 : self::MAX_PORTIONS_EACH;
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

        return $this->helpingsFor($fact, $targets->kcalFor($slot), $slot) * $targets->people;
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

            if ($entry->recipe_id !== null && $this->stillRecent($entry, $dates)) {
                $this->used[$entry->recipe_id] = true;
            }
        }

        $titles = Recipe::query()->whereIn('id', array_keys($this->used))->pluck('title');

        foreach ($titles as $title) {
            $this->usedSignatures[] = $this->families->signatureOf($title);
        }

        return $taken;
    }

    /**
     * Whether an entry is close enough to the days being filled to count as
     * "eaten lately".
     *
     * A month for everything, except a dish the household said it likes, which
     * may come back after a fortnight: holding a favourite back for as long as
     * anything else treats "more of this" as no information at all.
     *
     * @param  list<string>  $dates
     */
    private function stillRecent(MealPlanEntry $entry, array $dates): bool
    {
        if (! isset($this->liked[(int) $entry->recipe_id])) {
            return true;
        }

        foreach ($dates as $date) {
            if (abs($entry->date->diffInDays(CarbonImmutable::parse($date))) < self::LIKED_DAYS) {
                return true;
            }
        }

        return false;
    }

    /** How soon a liked dish may come back. */
    private const int LIKED_DAYS = 14;

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
     * How a dish stands when the week is bought in one shop: lifted for every
     * product it takes from the leaflet and, when nobody set a budget, weighed
     * by what it costs against the meal's typical dish.
     *
     * Zero when no shop was named, so a plain week is scored exactly as before.
     * With a budget the existing cost term already prices the dish — at the
     * shop's offers, through `RecipeFacts` — so the cost is not counted twice.
     */
    private function shopTerm(RecipeFact $fact, int $helpings, MealSlot $slot, bool $weighCost = true): float
    {
        if ($this->offers === null) {
            return 0.0;
        }

        $term = -self::PROMOTED_LIFT * min($fact->promoted, self::PROMOTED_CAP);
        $typical = $this->typicalCost[$slot->value] ?? null;

        if ($weighCost && $typical !== null && $typical > 0 && $fact->costPerPortion !== null) {
            $term += self::SHOP_COST_WEIGHT * ($fact->costPerPortion->toZloty() * $helpings / $typical - 1.0);
        }

        return $term;
    }

    /**
     * The median cost per head of the priced dishes in a pool, or null when too
     * few are priced to say what typical is.
     *
     * A median, for the reason the price book takes one: one beef tenderloin
     * among forty ordinary dinners must not make everything else look cheap.
     *
     * @param  Collection<int, RecipeCandidate>  $pool
     * @param  array<int, RecipeFact>  $facts
     * @param  callable(RecipeFact): int  $helpings
     */
    private function typicalCostOf(Collection $pool, array $facts, callable $helpings): ?float
    {
        if ($this->offers === null) {
            return null;
        }

        $costs = [];

        foreach ($pool as $recipe) {
            $fact = $facts[$recipe->id] ?? null;

            if ($fact?->costPerPortion !== null) {
                $costs[] = $fact->costPerPortion->toZloty() * $helpings($fact);
            }
        }

        if (count($costs) < self::MIN_PRICED_FOR_TYPICAL) {
            return null;
        }

        sort($costs);
        $middle = intdiv(count($costs), 2);

        return count($costs) % 2 === 1 ? $costs[$middle] : ($costs[$middle - 1] + $costs[$middle]) / 2;
    }

    private const int MIN_PRICED_FOR_TYPICAL = 10;

    /**
     * For every recipe, how many distinct products it takes from this shop's
     * offers — one grouped query over the whole catalogue, so the shortlist can
     * find the promotion dishes without reading anybody's ingredient list.
     *
     * Optional lines do not count. The finer exemptions (seasoning, staples)
     * are applied later by `RecipeFacts`, which reads the lines anyway; this is
     * only how the dishes get *looked at*.
     *
     * @return array<int, int>
     */
    private function promotedCounts(ShopOffers $offers): array
    {
        $counts = [];

        foreach (array_chunk($offers->ingredientIds(), 500) as $chunk) {
            $rows = DB::table('recipe_ingredients')
                ->whereIn('ingredient_id', $chunk)
                ->where('is_optional', false)
                ->groupBy('recipe_id')
                ->selectRaw('recipe_id, count(distinct ingredient_id) as products')
                ->pluck('products', 'recipe_id');

            foreach ($rows as $recipeId => $products) {
                $counts[(int) $recipeId] = ($counts[(int) $recipeId] ?? 0) + (int) $products;
            }
        }

        return $counts;
    }

    /** The shop the week is bought in, when somebody named one. */
    private ?ShopOffers $offers = null;

    /** @var array<int, int> recipe id => products it takes from the offers */
    private array $promotedCount = [];

    /** @var array<string, float|null> slot => median cost per head of its pool */
    private array $typicalCost = [];

    /**
     * A recipe's calories and cost, from what this generation already read.
     */
    private function factFor(int $recipeId): ?RecipeFact
    {
        if (! array_key_exists($recipeId, $this->known)) {
            $this->known[$recipeId] = $this->facts->forRecipes([$recipeId], $this->offers)[$recipeId] ?? null;
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
        $day = CarbonImmutable::parse($date);

        $available = $pool->reject(fn (RecipeCandidate $recipe): bool => isset($this->used[$recipe->id])
            || ! $this->isInSeason($this->factFor($recipe->id), $day)
            || $this->isRepeatedDish($this->factFor($recipe->id)));

        if ($available->isEmpty()) {
            return null;
        }

        // Best for *this* day first: the pool knows the dish, only the day knows
        // what was eaten yesterday, whether it is Sunday, and what month it is.
        $available = $this->rankedForDay($available, $slot, $day);

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
            fn (RecipeCandidate $recipe): bool => $this->costPerHelping($recipe, $wanted, $slot) <= $allowance,
        );

        if ($affordable !== null) {
            return $this->take($pool, $affordable, $slot, $date);
        }

        $this->overspent++;

        return $this->take($pool, $available->sortBy(
            fn (RecipeCandidate $recipe): float => $this->costPerHelping($recipe, $wanted, $slot),
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
    private function costPerHelping(RecipeCandidate $recipe, float $wanted, MealSlot $slot): float
    {
        $fact = $this->factFor($recipe->id);

        if ($fact?->costPerPortion === null) {
            return 0.0;
        }

        return $fact->costPerPortion->toZloty() * $this->helpingsFor($fact, $wanted, $slot);
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
        $this->usedSignatures[] = $this->factFor($recipe->id)->signature ?? [];
        $this->rememberSoup($this->factFor($recipe->id));
        $this->remember($recipe, $slot, $date);

        return $recipe;
    }

    /**
     * Whether a dish belongs on this date at all: its occasion has come round,
     * and it does not lean on a product that is close to absent.
     */
    private function isInSeason(?RecipeFact $fact, CarbonImmutable $day): bool
    {
        return $fact === null
            || ($this->season->allows($fact->occasion, $day)
                && ! $this->season->isOutOfSeason($fact->seasonalProduce, $day));
    }

    /**
     * The same dish as one eaten this month, from another source. The month
     * rule is about rows; this is the same rule about plates.
     */
    private function isRepeatedDish(?RecipeFact $fact): bool
    {
        if ($fact?->soupKind !== null && isset($this->soupKinds[$fact->soupKind])) {
            return true;
        }

        if ($fact === null || $fact->signature === []) {
            return false;
        }

        foreach ($this->usedSignatures as $used) {
            if (DishFamily::sameDish($fact->signature, $used)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record what a planned meal is made of, so the rest of the week can be
     * varied against it. Also called for the second day of a batch, which is a
     * chicken day as much as the first was.
     */
    private function remember(RecipeCandidate $recipe, MealSlot $slot, string $date): void
    {
        $fact = $this->factFor($recipe->id);

        if ($fact?->dominantIngredientId !== null) {
            $this->eaten[$slot->value][$fact->dominantIngredientId] = true;
            $this->eatenToday[$date][$fact->dominantIngredientId] = true;
        }

        if ($fact?->dominantIngredientId !== null) {
            $this->dominantWeek[$fact->dominantIngredientId] = ($this->dominantWeek[$fact->dominantIngredientId] ?? 0) + 1;
        }

        if ($fact?->family !== null) {
            $this->familiesOn[$date][$slot->value] = $fact->family;
            $this->familyCount[$slot->value][$fact->family] = ($this->familyCount[$slot->value][$fact->family] ?? 0) + 1;
            $this->familyWeek[$fact->family] = ($this->familyWeek[$fact->family] ?? 0) + 1;
        }

        foreach (array_diff($fact?->themes() ?? [], ['wege']) as $meat) {
            $this->meatOn[$date][$meat] = true;
        }

        if ($fact?->isAirFryer === true) {
            $this->airFryerMeals++;
        }

        if ($fact?->isRich === true) {
            $this->richWeek++;
        }

        if ($fact?->cuisine !== null) {
            $this->cuisineWeek[$fact->cuisine] = ($this->cuisineWeek[$fact->cuisine] ?? 0) + 1;
        }

        foreach ($fact->seasonalProduce ?? [] as $product) {
            $this->produceWeek[$product] = ($this->produceWeek[$product] ?? 0) + 1;
        }

        /*
         * Tofu is counted at every meal, not only the main ones: a tofu spread
         * at breakfast, tofu in Wednesday's obiad and a tofu bowl for supper
         * was a generated week, and the rotation below — which looks at main
         * meals only — saw one of the three.
         */
        if ($fact !== null && ! $this->isMainMeal($slot) && in_array('roslinne', $fact->themes(), true)) {
            $this->themeCount['roslinne'] = ($this->themeCount['roslinne'] ?? 0) + 1;
        }

        if ($fact === null || ! $this->isMainMeal($slot)) {
            return;
        }

        foreach ($fact->themes() as $theme) {
            $this->themesOn[$date][$theme] = true;
            $this->themeCount[$theme] = ($this->themeCount[$theme] ?? 0) + 1;
        }

        // A soup the catalogue never filed under Zupy is still a soup obiad.
        $form = $fact->form() ?? ($this->isSoup($fact) ? 'zupy' : null);

        if ($form !== null) {
            $this->formCount[$form] = ($this->formCount[$form] ?? 0) + 1;
        }
    }

    private const int SET_ASIDE = 10_000;

    /**
     * Whether this entry is a side or a starter beside a main — what the swap
     * sheet needs to know to say "the same calories from each" or not.
     */
    public function isSideOnPlate(MealPlanEntry $entry): bool
    {
        $this->known = [];

        return $this->sideKindOf($entry) !== null;
    }

    /**
     * Whether this entry is a side or a starter on a plate rather than the
     * meal itself, and of which kind.
     *
     * A meal planned as a soup, a main and a surówka is three entries, and the
     * shuffle beside the potatoes used to treat them as the meal: it handed
     * back a main course sized to the potatoes' calories. A side is a side only
     * when something else shares its meal, so a dish planned on its own is
     * swapped like any meal.
     */
    private function sideKindOf(MealPlanEntry $entry): ?string
    {
        if ($entry->recipe_id === null || $this->platemates($entry) === []) {
            return null;
        }

        return $this->sides->kindOf($entry->recipe_id);
    }

    /**
     * The other dishes in the same meal on the same day.
     *
     * @return list<int>
     */
    private function platemates(MealPlanEntry $entry): array
    {
        return array_values(array_map(intval(...), MealPlanEntry::query()
            ->where('user_id', $entry->user_id)
            ->onDates([$entry->date->toDateString()])
            ->where('slot', $entry->slot->value)
            ->whereKeyNot($entry->getKey())
            ->whereNotNull('recipe_id')
            ->pluck('recipe_id')
            ->all()));
    }

    /**
     * The sides of this kind that could take this one's place, best first: the
     * same rules a plate is built by, judged against the main it sits beside —
     * rice beside a curry, a soup of the main's own kitchen.
     *
     * @return list<int>
     */
    private function otherSides(MealPlanEntry $entry, string $kind): array
    {
        $main = null;

        foreach ($this->platemates($entry) as $id) {
            if ($this->sides->kindOf($id) === null) {
                $main = $this->factFor($id);

                break;
            }
        }

        $main ??= $this->factFor((int) $entry->recipe_id);

        if ($main === null) {
            return [];
        }

        $day = $entry->date->toImmutable();
        $styles = $kind === SideDishes::STARCH ? $this->starchStylesFor($main) : null;
        // Far past anything a week reaches, so the outgoing side comes last.
        $this->sideCount[(int) $entry->recipe_id] = self::SET_ASIDE;
        $found = [];

        /*
         * Drawn as a plate would draw them, without repeating an answer — and
         * each pick counts against its kind, so a list of potatoes is a list of
         * potatoes, groats and rice rather than twelve ways to cook kasza.
         */
        for ($i = 0; $i < 24; $i++) {
            $side = $this->pickSide($kind, $day, $main, $styles);

            if ($side === null || isset($found[$side]) || $side === (int) $entry->recipe_id) {
                break;
            }

            $found[$side] = true;
            $this->sideCount[$side] = self::SET_ASIDE;

            if ($kind === SideDishes::STARCH) {
                $style = $this->sides->styleOf($side);
                $this->styleWeek[$style] = ($this->styleWeek[$style] ?? 0) + 1;
            }
        }

        return array_keys($found);
    }

    /**
     * The obiad as it is served: the main course, and beside it potatoes or
     * groats and a surówka when the main is the meat-and-vegetables half of a
     * plate.
     *
     * "Dorsz z porami" for 1 000 kcal was three helpings each of fish and
     * leeks — the calories were right and the plate was not one anybody eats.
     * With a side the main goes back to a helping or two and the rest of the
     * meal is what a Polish obiad always had. A plate that cannot land near the
     * target with its sides is served as before, without them: the sides are
     * an improvement, never a reason to miss the meal.
     *
     * The soup, when there is one, comes first: zupa, then drugie danie. Only
     * while the week is short of soup (`SOUPS_A_WEEK`), never before a main
     * that is a soup itself, and never one built on the main's own meat —
     * rosół before roast chicken is chicken twice.
     *
     * @return array{portions: int, sides: list<array{id: int, portions: int}>, starter: int|null}
     */
    private function plate(RecipeCandidate $main, MealSlot $slot, string $date, ?PlanTargets $targets, int $servings): array
    {
        $alone = [
            'portions' => $targets === null ? $servings : $this->portionsFor($main, $slot, $targets),
            'sides' => [],
            'starter' => null,
        ];
        $fact = $this->factFor($main->id);

        if ($slot !== MealSlot::Lunch || $fact === null) {
            return $alone;
        }

        $day = CarbonImmutable::parse($date);
        $soup = ! $this->isSoup($fact) && ($this->formCount['zupy'] ?? 0) < self::SOUPS_A_WEEK
            ? $this->pickSide(SideDishes::SOUP, $day, $fact, null)
            : null;
        $starch = $this->wantsSides($fact)
            ? $this->pickSide(SideDishes::STARCH, $day, $fact, $this->starchStylesFor($fact))
            : null;
        /*
         * A surówka beside every second course. It used to be meat and fish only,
         * and the dietitian counted four in twenty-one obiady: udka with pyzy,
         * schab with kluski, half the plate missing.
         */
        $salad = $starch !== null || ($fact->vegetables < 2 && ! $this->isSoup($fact) && $fact->family !== 'salatka')
            ? $this->pickSide(SideDishes::SALAD, $day, $fact, null)
            : null;

        /*
         * Pierogi, a pot of pasta, a pomidorowa: a whole dish with next to no
         * vegetable in it gets a surówka on its own — four such obiady in a
         * week were the dietitian's first complaint once every second course
         * had its potatoes.
         */
        $beside = $starch === null
            ? ($salad === null ? [] : [[$salad]])
            : array_values(array_filter([[$starch, $salad], [$starch]], static fn (array $sides): bool => ! in_array(null, $sides, true)));
        $plates = [];

        if ($soup !== null) {
            foreach ($this->besideSoup($this->factFor($soup), $beside, $salad, $day) as $sides) {
                $plates[] = [$soup, ...$sides];
            }
        }

        array_push($plates, ...$beside);

        foreach ($plates as $sides) {
            /** @var list<int> $sides */
            $portions = $targets === null ? $servings : $this->portionsBeside($fact, $sides, $slot, $targets, $starch !== null);

            if ($portions === null) {
                continue;
            }

            foreach ($sides as $side) {
                $this->sideCount[$side] = ($this->sideCount[$side] ?? 0) + 1;
            }

            if ($starch !== null && in_array($starch, $sides, true)) {
                $style = $this->sides->styleOf($starch);
                $this->styleWeek[$style] = ($this->styleWeek[$style] ?? 0) + 1;
            }

            $starter = $soup !== null && $sides[0] === $soup ? $soup : null;

            return [
                'portions' => $portions,
                'sides' => array_map(
                    static fn (int $side): array => ['id' => $side, 'portions' => $targets === null ? $servings : $targets->people],
                    $starter === null ? $sides : array_slice($sides, 1),
                ),
                'starter' => $starter,
            ];
        }

        return $alone;
    }

    /**
     * What kind of starch a main is eaten with. A curry, a stir fry or chili
     * goes on rice; anything in a sauce — gulasz, potrawka, duszone, "w sosie"
     * — on groats, boiled potatoes or kluski, which take the sauce; the rest
     * may have any.
     *
     * @return list<string>|null
     */
    private function starchStylesFor(RecipeFact $fact): ?array
    {
        if (in_array($fact->cuisine, ['azjatycka', 'indyjska', 'meksykanska'], true)) {
            return [SideDishes::RICE];
        }

        if ($fact->family === 'curry' || $fact->inSauce) {
            return [SideDishes::GROATS, SideDishes::BOILED, SideDishes::DUMPLINGS, SideDishes::RICE];
        }

        return null;
    }

    /**
     * A soup before this main: not one already had this week, and not a second
     * meat — "zupa z pulpecikami" before dorsz w curry was two proteins and, to
     * both reviewers, a dinner for four. Before a meatless main any soup will do.
     */
    private function suitsAsStarter(RecipeFact $soup, RecipeFact $main): bool
    {
        // Krupnik before Kung Pao is two kitchens on one table.
        if ($soup->cuisine !== $main->cuisine) {
            return false;
        }

        if ($this->isRepeatedDish($soup) || ! $this->sharesNoMeat($soup, $main)) {
            return false;
        }

        // A different base from the main: pomidorowa z ryżem before gołąbki
        // was rice and tomato twice; roast-tomato soup before cod with tomatoes
        // the same.
        if (array_intersect($soup->seasonalProduce, $main->seasonalProduce) !== []
            || ($soup->namesStarch && $main->namesStarch)) {
            return false;
        }

        return array_diff($main->themes(), ['wege']) === []
            || (array_diff($soup->themes(), ['wege']) === [] && ! $soup->hasMeatInTitle);
    }

    private function rememberSoup(?RecipeFact $fact): void
    {
        if ($fact?->soupKind !== null) {
            $this->soupKinds[$fact->soupKind] = true;
        }
    }

    /**
     * The kinds of soup this week has had, whether as a starter or as the whole
     * obiad. A batch's second day is the same pot, not a second żurek.
     *
     * @var array<string, true>
     */
    private array $soupKinds = [];

    /**
     * The days whose obiad had a soup before the main — supper there is light.
     *
     * @var array<string, true>
     */
    private array $soupObiad = [];

    private function sharesNoMeat(RecipeFact $one, RecipeFact $other): bool
    {
        return array_intersect(
            array_diff($one->themes(), ['wege']),
            array_diff($other->themes(), ['wege']),
        ) === [] && $one->dominantIngredientId !== $other->dominantIngredientId;
    }

    private function isSoup(RecipeFact $fact): bool
    {
        return $fact->form() === 'zupy' || $fact->family === 'zupa';
    }

    /**
     * Whether a main course still wants its potatoes: an obiad whose calories
     * are not already mostly starch, and which is not a dish that is a whole
     * plate by itself — soup, pasta, a salad, something wrapped or baked.
     */
    private function wantsSides(RecipeFact $fact): bool
    {
        // By form only soups and salads: "Pulpety z tofu" is filed under Makarony
        // with no pasta in it, and was served as six bare helpings.
        return $fact->lacksStarch()
            && ! in_array($fact->form(), ['zupy', 'salatki'], true)
            && ! in_array($fact->family, ['zupa', 'makaron', 'salatka', 'kanapki', 'tortille', 'nalesniki', 'owsianka', 'jajka', 'zapiekanka'], true);
    }

    /**
     * How many portions of the main, beside one helping each of these sides,
     * come closest to the meal's calories — or null when none comes close
     * enough, which sends the plate back to the main alone.
     *
     * @param  list<int>  $sides
     */
    private function portionsBeside(RecipeFact $main, array $sides, MealSlot $slot, PlanTargets $targets, bool $wantsSides): ?int
    {
        $kcal = $main->kcalPerPortion;

        if ($kcal === null || $kcal <= 0) {
            return null;
        }

        $wanted = $targets->kcalFor($slot);
        $beside = 0.0;

        foreach ($sides as $side) {
            $beside += $this->factFor($side)->kcalPerPortion ?? 0.0;
        }

        /*
         * Two helpings of the main at most when something comes with it: "dorsz
         * ×6" after a soup was three portions of fish each, and the point of a
         * side is that the plate is filled by the potatoes instead.
         */
        $helpings = min(max((int) round(($wanted - $beside) / $kcal), 1), min($this->maxHelpings($slot), 2));
        $miss = abs($helpings * $kcal + $beside - $wanted) / max($wanted, 1.0);

        /*
         * A cutlet that wants its potatoes may miss the meal by a little more
         * rather than go without them: "Kotlety schabowe" alone on a Sunday
         * plate was the one thing the home cook said she would never serve.
         */
        $allowed = $wantsSides && $sides !== [] ? self::MAX_MISS * 1.5 : self::MAX_MISS;

        return $miss <= $allowed ? $helpings * $targets->people : null;
    }

    /**
     * What goes beside the main when a soup comes first.
     *
     * On a working day three recipes is the most anybody cooks — zupa, the
     * main and one side — so the surówka drops out; a weekend may have all
     * four. A soup that is itself mostly potatoes, rice or groats brings the
     * starch, and a second one on the same plate was "trzy źródła skrobi
     * naraz" to the dietitian.
     *
     * @param  list<list<int|null>>  $beside
     * @return list<list<int>>
     */
    private function besideSoup(?RecipeFact $soup, array $beside, ?int $salad, CarbonImmutable $day): array
    {
        $starchy = $soup !== null && ($soup->namesStarch || ($soup->starchShare ?? 0.0) >= 0.35);

        if ($starchy) {
            return $salad !== null && $day->isWeekend() ? [[$salad], []] : [[]];
        }

        $options = array_values(array_filter(
            $beside,
            static fn (array $sides): bool => $day->isWeekend() || count($sides) < 2,
        ));

        /*
         * A main that wants its potatoes keeps them: when soup and starch
         * together overshoot the meal, the soup goes, not the starch — "zupa,
         * then udka with nothing beside them" was the plate both reviewers
         * named first.
         */
        /** @var list<list<int>> $options */
        return $beside === [] ? [[]] : $options;
    }

    /**
     * One side of this kind for this day: in season, not eaten yet this week
     * when anything else will do, and otherwise left to chance — a side has
     * no calories to fit and no protein to rotate, only a plate to vary.
     *
     * @param  list<string>|null  $styles  the kinds of starch this main is eaten with; null for any
     */
    private function pickSide(string $kind, CarbonImmutable $day, RecipeFact $main, ?array $styles): ?int
    {
        $sides = $this->sides->of($kind);
        $this->known += $sides;

        $open = array_filter(
            $sides,
            fn (RecipeFact $fact, int $id): bool => ! isset($this->disliked[$id])
                && $this->isInSeason($fact, $day)
                && ($styles === null || in_array($this->sides->styleOf($id), $styles, true))
                // An "Azjatycka surówka" belongs beside a stir fry, not a schab.
                && in_array($this->sides->cuisineOf($id), [null, $main->cuisine], true)
                && ($kind !== SideDishes::SOUP || $this->suitsAsStarter($fact, $main)),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($open === []) {
            return null;
        }

        /*
         * Pomidorowa, żurek, krupnik — most of the time. A starter drawn from
         * every soup in the catalogue came back as laksa and brukselkowa, and
         * the reviewers' complaint was precisely that the week had no rosół.
         * One time in three anything goes, so the week does not become the same
         * five soups on rotation.
         */
        if ($kind === SideDishes::SOUP && random_int(0, 2) > 0) {
            $classics = array_filter($open, static fn (RecipeFact $fact): bool => $fact->isHomeClassic);
            $open = $classics === [] ? $open : $classics;
        }

        /*
         * Least used first — this week, and the month around it too: the same
         * kasza jaglana in each of three generated weeks read as the only side
         * the household knew.
         */
        $uses = fn (int $id): int => ($this->sideCount[$id] ?? 0) + (isset($this->used[$id]) ? 1 : 0);
        $fewest = min(array_map($uses, array_keys($open)));
        $fresh = array_values(array_filter(
            array_keys($open),
            fn (int $id): bool => $uses($id) === $fewest,
        ));

        /*
         * Anything not out of season, by chance. Taking the single best season
         * fit served the same Brussels-sprout surówka every week of October:
         * in season is a reason to allow a side, not to insist on it.
         */
        $allowed = array_values(array_filter(
            $fresh,
            fn (int $id): bool => $this->season->fitOf($open[$id]->seasonalProduce, $day) >= 0,
        ));

        /*
         * Roast potatoes one time in three when nothing says otherwise. Left to
         * chance they came up six times in three weeks, because the catalogue
         * holds far more ways to roast a potato than to cook kasza, and both
         * reviewers asked for groats and boiled potatoes instead.
         */
        if ($kind === SideDishes::STARCH) {
            $allowed = $this->leastUsedStyle($allowed);
        }

        if ($allowed === []) {
            return null;
        }

        return $allowed[array_rand($allowed)];
    }

    /**
     * The starch sides of the kind this week has had least of.
     *
     * Rotated by kind and not only by recipe, because the catalogue holds
     * dozens of kluski and kopytka and a handful of kasza: drawn by recipe the
     * week came back with dumplings at ten obiady of twenty. Ties go to groats,
     * then boiled potatoes, rice, roast potatoes and dumplings last — the order
     * both reviewers asked for — and dumplings come once a week at most while
     * anything else is on offer.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function leastUsedStyle(array $ids): array
    {
        $byStyle = [];

        foreach ($ids as $id) {
            $byStyle[$this->sides->styleOf($id)][] = $id;
        }

        if (count($byStyle) > 1 && ($this->styleWeek[SideDishes::DUMPLINGS] ?? 0) >= 1) {
            unset($byStyle[SideDishes::DUMPLINGS]);
        }

        $order = [SideDishes::GROATS, SideDishes::BOILED, SideDishes::RICE, SideDishes::ROASTED, SideDishes::DUMPLINGS];
        $best = null;

        foreach ($order as $style) {
            if (isset($byStyle[$style]) && ($best === null || ($this->styleWeek[$style] ?? 0) < ($this->styleWeek[$best] ?? 0))) {
                $best = $style;
            }
        }

        return $best === null ? $ids : $byStyle[$best];
    }

    /**
     * How many obiady this week each kind of starch side has been.
     *
     * @var array<string, int>
     */
    private array $styleWeek = [];

    /**
     * How often each side has been on a plate this week, so the potatoes give
     * way to groats and the cabbage surówka to a carrot one.
     *
     * @var array<int, int>
     */
    private array $sideCount = [];

    /**
     * Whether the pot this recipe makes is two of these meals.
     *
     * A gulasz for eight eaten by two people at two helpings each is Monday and
     * Tuesday, whether or not its source called it meal prep — and cooking it
     * once is how a working week actually goes. Before this only the lunch-box
     * site's recipes were ever cooked ahead, which both reviewers of a generated
     * week noticed: an obiad cooked from scratch every working day, and pots
     * "for six" planned as three helpings each to use them up.
     */
    private function feedsTwice(RecipeCandidate $recipe, int $portions): bool
    {
        $servings = $this->factFor($recipe->id)?->servings;

        return $servings !== null && $portions > 0 && $servings >= 2 * $portions;
    }

    /**
     * Whether a batch may run on into this day. The weekend is when there is
     * time to cook, and its obiad is the one the week looks forward to — so
     * Friday's pot is not Saturday's lunch.
     */
    private function isWeekendDate(?string $date): bool
    {
        return $date !== null && CarbonImmutable::parse($date)->isWeekend();
    }

    private function isMainMeal(MealSlot $slot): bool
    {
        return $slot === MealSlot::Lunch || $slot === MealSlot::Dinner;
    }

    /**
     * The candidates re-ordered for one particular day, best first.
     *
     * The pool's own score says how good a dish is for this *meal*; this adds
     * what only the day knows. Dishes within `VARIETY_BAND` of the best are
     * shuffled, so a week asked for twice is still not the same week.
     *
     * @param  Collection<int, RecipeCandidate>  $available
     * @return Collection<int, RecipeCandidate>
     */
    private function rankedForDay(Collection $available, MealSlot $slot, CarbonImmutable $day): Collection
    {
        $scores = [];

        foreach ($available as $recipe) {
            $scores[$recipe->id] = ($this->scores[$slot->value][$recipe->id] ?? 0.0)
                + $this->dayPenalty($recipe->id, $slot, $day);
        }

        return $this->variedByRank(
            $available->sortBy(static fn (RecipeCandidate $recipe): float => $scores[$recipe->id])->values(),
            $scores,
        );
    }

    /**
     * How much worse a dish is on this day than its meal score says. Lower is
     * better, and a bonus is a negative number.
     *
     * Every term is a nudge, never a ban — "a repeat beats a hole in the week"
     * holds here as everywhere. The sizes are relative to the calorie miss the
     * score already carries (up to `MAX_MISS`, 0.2): a chicken dinner the day
     * after a chicken lunch costs about as much as missing the calories by a
     * sixth, so it loses to any decent alternative and still wins over nothing.
     */
    private function dayPenalty(int $recipeId, MealSlot $slot, CarbonImmutable $day): float
    {
        $fact = $this->factFor($recipeId);

        if ($fact === null) {
            return 0.0;
        }

        return $this->substancePenalty($fact, $slot)
            + $this->timePenalty($fact, $slot, $day)
            + $this->rotationPenalty($fact, $slot, $day)
            + $this->seasonPenalty($fact, $day)
            + $this->familyPenalty($fact, $slot, $day)
            + $this->shapePenalty($fact, $slot, $day)
            + ($fact->isAirFryer ? 0.05 * $this->airFryerMeals : 0.0)
            + ($fact->cuisine === null ? 0.0 : 0.15 * ($this->cuisineWeek[$fact->cuisine] ?? 0))
            + ($fact->dominantIngredientId === null ? 0.0 : 0.1 * ($this->dominantWeek[$fact->dominantIngredientId] ?? 0))
            + ($fact->isHeadline ? self::HEADLINE_PENALTY : 0.0)
            + ($fact->isRich ? self::RICH_PENALTY * ($this->richWeek + 1) : 0.0)
            + (isset($this->liked[$recipeId]) ? -self::LIKED_BONUS : 0.0);
    }

    /**
     * What one more dish of kiełbasa, chorizo, szynka or halloumi costs, times
     * how many the week already has. A little for the first, so a kiełbasa
     * z cebulką can still be Friday's supper; enough by the third that a week
     * stops reading like a deli counter.
     */
    private const float RICH_PENALTY = 0.08;

    /** How many rich dishes the week already holds — see `RICH_PENALTY`. */
    private int $richWeek = 0;

    /**
     * How far a tabloid headline sinks against a dish with a name.
     *
     * Not a verdict on the cooking — a lot of those recipes are fine — but a
     * week reading "Tani sposób na sycące śniadanie. Ta zapiekanka zachwyca"
     * on Tuesday does not tell anybody what is for breakfast, and a plan you
     * cannot read at a glance is a plan that looks dull. Small enough that a
     * headline still wins when it is the better fit for the day.
     */
    private const float HEADLINE_PENALTY = 0.15;

    /**
     * How far "lubimy to" lifts a dish: more than any single nudge for the day,
     * so a favourite that fits comes back, and less than a missed calorie
     * target, so it cannot buy its way into a meal it does not feed.
     */
    private const float LIKED_BONUS = 0.15;

    /** @var array<int, true> */
    private array $liked = [];

    /**
     * "Nie proponuj więcej" — out of every pool, the swap and the alternatives
     * sheet included. Still in the catalogue for whoever searches for it.
     *
     * @var array<int, true>
     */
    private array $disliked = [];

    /**
     * A main meal of two or three things is a technique, not a dish — "Jajko
     * w koszulce", a bowl of groats. They pass `isSubstantial`, and they are
     * still what makes a week look thin when you scroll through its photos.
     */
    private function substancePenalty(RecipeFact $fact, MealSlot $slot): float
    {
        if (! $this->isMainMeal($slot)) {
            return 0.0;
        }

        return match (true) {
            $fact->realIngredients === 0 => 0.0,
            $fact->realIngredients <= 2 => 0.3,
            $fact->realIngredients === 3 => 0.1,
            $fact->realIngredients >= 6 => -0.03,
            default => 0.0,
        };
    }

    /**
     * Quick on working days, generous at the weekend.
     *
     * A 50-minute breakfast on a Tuesday is a plan nobody follows, and a
     * Sunday obiad of egg cutlets is a plan nobody looks forward to. The time is
     * what the source declared; a dish that never said is judged on nothing.
     */
    private function timePenalty(RecipeFact $fact, MealSlot $slot, CarbonImmutable $day): float
    {
        $minutes = $fact->minutes;

        if ($minutes === null) {
            return 0.0;
        }

        if (! $day->isWeekend()) {
            return match ($slot) {
                MealSlot::Breakfast, MealSlot::SecondBreakfast => $minutes > 45 ? 0.3 : ($minutes > 20 ? 0.12 : 0.0),
                MealSlot::Dinner => $minutes > 60 ? 0.25 : ($minutes > 40 ? 0.1 : 0.0),
                MealSlot::Lunch => $minutes > 90 ? 0.2 : ($minutes > 60 ? 0.1 : 0.0),
                default => 0.0,
            };
        }

        return match ($slot) {
            // The meal a weekend is for: something that takes its time.
            MealSlot::Lunch => ($minutes >= 45 ? -0.12 : ($minutes < 25 ? 0.08 : 0.0)) + $this->sundayPenalty($fact, $day),
            MealSlot::Breakfast => $minutes >= 15 ? -0.05 : 0.0,
            default => 0.0,
        };
    }

    /**
     * The Sunday obiad is a main course, and in Poland it is usually meat or
     * fish: a generated Sunday of "Zupa z pieczonych warzyw" was a perfectly
     * good soup on the one day of the week that is not for a soup alone.
     */
    private function sundayPenalty(RecipeFact $fact, CarbonImmutable $day): float
    {
        if (! $day->isSunday()) {
            return 0.0;
        }

        $penalty = $fact->form() === 'zupy' ? 0.12 : 0.0;

        /*
         * A centrepiece: meat or fish, and something more than a weeknight
         * plate. "Pulpety z tofu" and liver were generated Sundays — fine
         * dishes, and nobody's idea of a Sunday dinner. A dish whose source
         * never stated its time is judged by how much goes into it instead,
         * because one whole source (kwestiasmaku) states no times at all.
         */
        $isCentrepiece = array_diff($fact->themes(), ['wege', 'roslinne']) !== []
            && ! $fact->isOffal
            && ($fact->minutes === null ? $fact->realIngredients >= 6 : $fact->minutes >= 40);

        /*
         * Offal is not merely "not a centrepiece": "Wątróbka z kaczki" kept
         * winning Sundays on the strength of naming a home classic (kaczka), so
         * it gets no lift from that and a push of its own.
         */
        if ($fact->isOffal) {
            return $penalty + 0.4;
        }

        return ($isCentrepiece ? $penalty - 0.1 : $penalty + 0.2) - ($fact->isHomeClassic ? 0.08 : 0.0);
    }

    /**
     * Chicken on Monday, fish on Tuesday, a meat-free Wednesday — not chicken
     * four times because the chicken recipes happened to fit.
     *
     * The dominant-ingredient rule cannot see this: breast, thighs and wings
     * are three different products and one week of chicken. So the obiad and
     * the kolacja are spread across the quick-pick protein categories, and
     * soup and pasta are kept to a couple each.
     */
    private function rotationPenalty(RecipeFact $fact, MealSlot $slot, CarbonImmutable $day): float
    {
        $date = $day->toDateString();

        /*
         * The same meat twice in a day, whichever meals it falls in. Sausages
         * for breakfast, pork shoulder for obiad and kiełbasa for supper was a
         * generated Saturday: three portions of pork, and the rule below only
         * ever compared the obiad with the kolacja.
         */
        $sameDay = 0.0;

        foreach (array_diff($fact->themes(), ['wege']) as $meat) {
            if (isset($this->meatOn[$date][$meat]) && ! isset($this->themesOn[$date][$meat])) {
                $sameDay += 0.25;
            }
        }

        if (! $this->isMainMeal($slot)) {
            return $sameDay + (in_array('roslinne', $fact->themes(), true) ? 0.2 * ($this->themeCount['roslinne'] ?? 0) : 0.0);
        }

        $penalty = $sameDay;

        foreach ($fact->themes() as $theme) {

            // Korean chicken for obiad and chicken skewers for supper, in the
            // fourth round of reviews, after this was 0.3: one meat a day.
            if (isset($this->themesOn[$date][$theme])) {
                $penalty += $theme === 'wege' ? 0.05 : 0.6;
            }

            foreach ([$day->subDay()->toDateString(), $day->addDay()->toDateString()] as $neighbour) {
                if (isset($this->themesOn[$neighbour][$theme])) {
                    $penalty += $theme === 'wege' ? 0.0 : 0.15;
                }
            }

            $penalty += match ($theme) {
                'wege' => 0.03,
                'roslinne' => 0.2,
                default => 0.08,
            } * ($this->themeCount[$theme] ?? 0);

            // Fish twice a week, the advice every dietitian gives and the first
            // thing both reviewers of a generated week found missing.
            if ($theme === 'ryby' && ($this->themeCount['ryby'] ?? 0) < 2) {
                $penalty -= 0.12;
            }
        }

        $form = $fact->form();
        $formsSoFar = $form === null ? 0 : ($this->formCount[$form] ?? 0);

        // Soup is an obiad's own course in Poland, so it may come round three
        // times before it counts as a rut; pasta and salad after two.
        $allowed = $form === 'zupy' ? self::SOUPS_A_WEEK : 2;

        if ($formsSoFar >= $allowed) {
            $penalty += 0.15 * ($formsSoFar - $allowed + 1);
        }

        return $penalty;
    }

    /**
     * Pancakes for breakfast and pancakes again for supper; three sandwich
     * spreads in one week of breakfasts.
     *
     * Every one of those was a different recipe built on a different product,
     * so no other rule saw a repeat — and a week is remembered by the kind of
     * dish on the plate, not by its dominant ingredient. Applied to every meal:
     * the morning repeats itself more than any other.
     */
    private function familyPenalty(RecipeFact $fact, MealSlot $slot, CarbonImmutable $day): float
    {
        $family = $fact->family;

        if ($family === null) {
            return 0.0;
        }

        $penalty = 0.0;
        $today = $this->familiesOn[$day->toDateString()] ?? [];

        $heavy = in_array($family, self::ONCE_A_DAY, true);

        foreach ($today as $otherSlot => $otherFamily) {
            if ($otherSlot !== $slot->value && $otherFamily === $family) {
                $penalty += $heavy ? 0.6 : 0.2;
            }
        }

        if (($this->familiesOn[$day->subDay()->toDateString()][$slot->value] ?? null) === $family
            || ($this->familiesOn[$day->addDay()->toDateString()][$slot->value] ?? null) === $family) {
            $penalty += 0.15;
        }

        // Per meal and per week: pancakes at breakfast on Tuesday and for supper
        // on Thursday are two pancake days, whichever meal they fell in.
        return $penalty
            + 0.15 * ($this->familyCount[$slot->value][$family] ?? 0)
            + ($heavy ? 0.5 : 0.15) * ($this->familyWeek[$family] ?? 0);
    }

    /**
     * What each meal is for, beyond its calories.
     *
     * Kolacja in Poland is the lighter meal: a generated week with a beef bake
     * for supper and roast pork after a fish obiad was two dinners a day. The
     * obiad, the other way round, is where soup belongs — a few times a week,
     * and both reviewers found weeks with none. And pancakes at seven on a
     * Tuesday are a weekend breakfast served on the wrong day.
     *
     * The obiad also leans, a little, towards the dishes a Polish home is built
     * on, and towards fish on a Friday — a reviewer's verdict on a fairly drawn
     * week was that it read like a vegan fitness blog, not a Polish kitchen.
     */
    private function shapePenalty(RecipeFact $fact, MealSlot $slot, CarbonImmutable $day): float
    {
        return match ($slot) {
            MealSlot::Dinner => $this->dinnerShape($fact, $day),
            MealSlot::Lunch => ($this->isSoup($fact) && ($this->formCount['zupy'] ?? 0) < self::SOUPS_A_WEEK ? -0.22 : 0.0)
                + ($fact->isHomeClassic ? -0.15 : 0.0)
                // "W polskim domu piątkowa ryba to odruch" — three fishless
                // Fridays in a row after this was -0.1.
                + ($day->isFriday() && in_array('ryby', $fact->themes(), true) ? -0.3 : 0.0),
            MealSlot::Breakfast => $this->breakfastShape($fact, $day),
            default => 0.0,
        };
    }

    /**
     * Kolacja on a working day is not a second cooking. The obiad was cooked,
     * often yesterday; supper is bread and something on it, a salad, eggs — a
     * reviewer counted three cookings a day on working days and gave the week
     * three out of ten for realism.
     */
    private function dinnerShape(RecipeFact $fact, CarbonImmutable $day): float
    {
        return $this->cookedSupper($fact, $day) + $this->vegetableSupper($fact);
    }

    /**
     * A supper of bread and cheese, or kiełbasa with onion, has no vegetable on
     * it — the dietitian's first complaint in four rounds running. One
     * vegetable takes most of the cost away, two take all of it. A dish whose
     * lines were never read is left alone: unknown is not "none".
     */
    private function vegetableSupper(RecipeFact $fact): float
    {
        if ($fact->realIngredients === 0) {
            return 0.0;
        }

        /*
         * And a salad with no protein is a side, not a supper: "sałatka z
         * marynowanego selera z ananasem" and "sałatka z pora i groszku" were
         * both suppers in the same week.
         */
        $share = $fact->proteinShare();
        $thin = $share !== null && $share < 0.12 ? 0.2 : 0.0;

        return $thin + match (min($fact->vegetables, 2)) {
            0 => 0.2,
            1 => 0.05,
            default => -0.03,
        };
    }

    private function cookedSupper(RecipeFact $fact, CarbonImmutable $day): float
    {
        $cooked = in_array($fact->family, ['zapiekanka', 'curry', 'kotlety', 'makaron', 'nalesniki'], true);

        // After zupa and drugie danie, a bake for supper is a third cooked meal.
        if ($cooked && isset($this->soupObiad[$day->toDateString()])) {
            return 0.35;
        }

        // Five zapiekanki for supper in three weeks was too many for both
        // reviewers: the second in a week costs as much as a soup obiad's.
        if ($fact->family === 'zapiekanka') {
            $bakes = $this->familyCount[MealSlot::Dinner->value]['zapiekanka'] ?? 0;

            if ($bakes > 0) {
                return 0.3 * $bakes;
            }
        }

        if ($day->isWeekend()) {
            return $cooked ? 0.1 : 0.0;
        }

        return $cooked ? 0.15 : (in_array($fact->family, ['kanapki', 'salatka', 'jajka'], true) ? -0.05 : 0.0);
    }

    /**
     * A big pot: soup, stew, curry — what a household cooks on Monday and eats
     * again on Tuesday, whatever the recipe says it serves.
     */
    private function isPot(RecipeCandidate $recipe): bool
    {
        $fact = $this->factFor($recipe->id);

        return $fact !== null
            && ($fact->form() === 'zupy' || in_array($fact->family, ['zupa', 'curry', 'zapiekanka'], true));
    }

    /**
     * A weekday breakfast is a sandwich or a bowl; a weekend one is pancakes or
     * eggs done properly. Liver pâté on a Saturday morning was the first sign
     * the generator did not know the difference.
     */
    private function breakfastShape(RecipeFact $fact, CarbonImmutable $day): float
    {
        $leisurely = in_array($fact->family, ['nalesniki', 'jajka'], true);
        $everyday = in_array($fact->family, ['kanapki', 'owsianka'], true);

        if ($day->isWeekend()) {
            return $leisurely ? -0.08 : ($everyday ? 0.06 : 0.0);
        }

        /*
         * Working days: no batter at seven in the morning (Dutch Baby on a
         * Tuesday), and no beef or pork plate either — a Sloppy Joe was a
         * generated Friday breakfast.
         */
        $heavy = array_intersect($fact->themes(), ['wolowina', 'wieprzowina']) !== [];

        return ($fact->family === 'nalesniki' ? 0.2 : 0.0)
            + ($heavy ? 0.12 : 0.0)
            + ($everyday ? -0.05 : 0.0)
            + ($fact->isPlantProtein ? 0.1 : 0.0);
    }

    /** A pumpkin soup in October rises; strawberries in December sink. */
    private function seasonPenalty(RecipeFact $fact, CarbonImmutable $day): float
    {
        $fit = $this->season->fitOf($fact->seasonalProduce, $day);

        // In season is not the same as every day.
        $repeats = 0;

        foreach ($fact->seasonalProduce as $product) {
            $repeats += $this->produceWeek[$product] ?? 0;
        }

        $penalty = 0.08 * $repeats;

        /*
         * Out of season is nearly a ban, on purpose: 0.2 let "Karkówka ze
         * szparagami" through in October because it fitted the calories well.
         * Asparagus in October is not a fit, it is an import.
         */
        return $penalty + ($fit >= 0 ? -0.06 * min($fit, 2) : 0.2 * min(-$fit, 4));
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

    /**
     * How good each candidate is for its meal, before the day is considered.
     *
     * @var array<string, array<int, float>>
     */
    private array $scores = [];

    /**
     * The protein each day's main meals are built around.
     *
     * @var array<string, array<string, true>>
     */
    private array $themesOn = [];

    /** @var array<string, int> */
    private array $themeCount = [];

    /** @var array<string, int> */
    private array $formCount = [];

    /**
     * The kind of dish each meal of each day became.
     *
     * @var array<string, array<string, string>>
     */
    private array $familiesOn = [];

    /**
     * How often each meal has been each kind of dish this week.
     *
     * @var array<string, array<string, int>>
     */
    private array $familyCount = [];

    /**
     * Every meat each day has had, breakfast included.
     *
     * @var array<string, array<string, true>>
     */
    private array $meatOn = [];

    /**
     * How many air fryer recipes the week holds. One source writes nothing
     * else, and nine of them in three weeks read as a machine, not a kitchen.
     */
    private int $airFryerMeals = 0;

    /**
     * How often the week has gone to each cuisine — Thai soup on Monday and
     * Thai chicken on Friday read as the same dinner twice.
     *
     * @var array<string, int>
     */
    private array $cuisineWeek = [];

    /**
     * The dish names eaten this month, as signatures.
     *
     * @var list<list<string>>
     */
    private array $usedSignatures = [];

    /**
     * How often each seasonal product has been on the table this week. Kale is
     * in season in October, and it was also four obiady running.
     *
     * @var array<string, int>
     */
    private array $produceWeek = [];

    /** @var array<string, int> each kind of dish, across every meal of the week */
    private array $familyWeek = [];

    /**
     * How often the week has leaned on each product, across every meal.
     *
     * The per-meal rule stopped tofu twice at breakfast and let it through at
     * lunch, lunch again and supper — four tofu meals in four days, each in a
     * different slot.
     *
     * @var array<int, int>
     */
    private array $dominantWeek = [];
}
