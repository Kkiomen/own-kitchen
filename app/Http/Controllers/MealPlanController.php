<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\RecipeListing;
use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Nutrition\RecipeNutrition;
use App\Planning\MealAlternative;
use App\Planning\MealPlan;
use App\Planning\PlanGenerator;
use App\Planning\PlannedShopping;
use App\Planning\PlannedWeek;
use App\Planning\PlanTargets;
use App\Planning\WeekSummary;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * "Plan na tydzień". Scoped to the account throughout, like the kitchen and the
 * list it feeds.
 */
class MealPlanController extends Controller
{
    public function __construct(
        private readonly MealPlan $plan,
        private readonly PlannedShopping $shopping,
        private readonly PlanGenerator $generator,
        private readonly RecipeListing $listing,
        private readonly WeekSummary $summary,
        private readonly RecipeNutrition $nutrition,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Plan/Index', $this->screen($request));
    }

    /**
     * Everything the plan screen reads, shared by the page and by the sheets
     * that open over it.
     *
     * Extracted when the alternatives sheet needed the same week underneath it:
     * the alternative was reaching into the rendered response for its props,
     * which works right up until somebody changes what `index` returns.
     *
     * @return array<string, mixed>
     */
    private function screen(Request $request): array
    {
        $weekStart = MealPlan::weekOf($request->string('week')->toString() ?: null);
        $search = $request->string('search')->trim()->toString();

        /*
         * The picker searches within the meal it was opened from, so looking for
         * "jajka" under Obiad does not offer scrambled eggs. `slot=` empty is the
         * screen's own "szukaj w całym katalogu" escape hatch, for the ~28% of
         * recipes no rule was confident enough to tag.
         */
        $slot = MealSlot::tryFrom($request->string('slot')->toString());

        return [
            'weekStart' => $weekStart->toDateString(),
            'previousWeek' => $weekStart->subWeek()->toDateString(),
            'nextWeek' => $weekStart->addWeek()->toDateString(),
            'thisWeek' => MealPlan::weekOf(null)->toDateString(),
            'days' => $this->plan->week($request->user(), $weekStart),
            'slots' => array_map(
                static fn (MealSlot $slot): array => ['value' => $slot->value, 'label' => $slot->label()],
                MealSlot::ordered(),
            ),
            'defaultServings' => MealPlan::DEFAULT_SERVINGS,
            /*
             * What the generator's form opens with. Shipped from the server so
             * the shares cannot drift from `PlanTargets`, which is the class
             * that refuses a set that does not add up to a day.
             */
            'defaultTargets' => [
                'people' => MealPlan::DEFAULT_SERVINGS,
                'kcal' => PlanTargets::DEFAULT_KCAL,
                'shares' => array_map(
                    static fn (float $share): int => (int) round($share * 100),
                    PlanTargets::everydayShares(),
                ),
            ],
            // A closure, so browsing the week never runs the search query:
            // Inertia drops props a partial visit did not ask for *before* it
            // evaluates them.
            'matches' => fn (): array => $this->listing->search($search, $slot),

            /*
             * The week as it stands, recomputed on every visit rather than
             * carried in a flash from the generator.
             *
             * It used to come back with the generated week and then sit there
             * while the plan changed underneath it: swap a dish, and the panel
             * went on quoting the calories of a week that no longer existed. A
             * number that is only true until somebody touches the screen is
             * worse than no number.
             *
             * A closure for the same reason as `matches`: a partial visit that
             * only wanted the search results must not pay for a pass over the
             * week's recipes.
             */
            'week' => fn (): ?array => $this->weekReport($request, $weekStart),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'slot' => ['required', Rule::enum(MealSlot::class)],
            'recipe_id' => ['nullable', 'exists:recipes,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $recipeId = $data['recipe_id'] ?? null;
        $note = $data['note'] ?? null;

        /*
         * A recipe or a note, never both and never neither. Both would leave two
         * titles for one meal with nothing to say which is the real one; neither
         * is an entry that cannot be shown at all.
         */
        if (($recipeId === null) === ($note === null)) {
            throw ValidationException::withMessages([
                'recipe_id' => 'Wybierz przepis albo wpisz własną notatkę.',
            ]);
        }

        MealPlanEntry::query()->create([
            'user_id' => $request->user()->id,
            'date' => $data['date'],
            'slot' => $data['slot'],
            'recipe_id' => $recipeId,
            'note' => $note,
            // A note feeds nobody a measured portion, so it carries no count.
            'servings' => $recipeId === null
                ? null
                : ($data['servings'] ?? MealPlan::DEFAULT_SERVINGS),
            'position' => $this->nextPosition($request, $data['date'], $data['slot']),
        ]);

        return back();
    }

    /**
     * The two things that change about a meal already planned: how many portions
     * of it, and which day it moves to.
     */
    public function update(Request $request, MealPlanEntry $mealPlanEntry): RedirectResponse
    {
        $this->authoriseOwnership($request, $mealPlanEntry);

        $data = $request->validate([
            'servings' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'date' => ['sometimes', 'date'],
            'slot' => ['sometimes', Rule::enum(MealSlot::class)],
        ]);

        if (isset($data['servings']) && $mealPlanEntry->isNote()) {
            throw ValidationException::withMessages([
                'servings' => 'Notatka nie ma porcji.',
            ]);
        }

        $mealPlanEntry->update($data);

        return back();
    }

    public function destroy(Request $request, MealPlanEntry $mealPlanEntry): RedirectResponse
    {
        $this->authoriseOwnership($request, $mealPlanEntry);

        $mealPlanEntry->delete();

        return back();
    }

    /**
     * Fill the chosen days in from the recipes each meal is suited to.
     *
     * Only the empty slots: a week somebody started by hand must survive this
     * button, and pressing it twice tops the gaps up rather than starting again.
     */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*.date' => ['required', 'date'],
            'days.*.slots' => ['required', 'array', 'min:1'],
            'days.*.slots.*' => ['required', Rule::enum(MealSlot::class)],
            'servings' => ['nullable', 'integer', 'min:1', 'max:99'],
            // The targets are optional as a whole: pressing the button without
            // them is the plain "fill my week" this screen has always had.
            'targets' => ['nullable', 'array'],
            'targets.people' => ['required_with:targets', 'integer', 'min:1', 'max:12'],
            'targets.kcal' => ['required_with:targets', 'integer', 'min:800', 'max:6000'],
            'targets.budget' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'targets.shares' => ['required_with:targets', 'array', 'min:1'],
            'targets.shares.*' => ['integer', 'min:1', 'max:100'],
        ]);

        $wanted = [];

        // Keyed by date, so the same day asked for twice is still one day.
        foreach ($data['days'] as $day) {
            $wanted[$day['date']] = array_map(MealSlot::from(...), array_values($day['slots']));
        }

        ksort($wanted);

        $targets = $this->targetsFrom($data['targets'] ?? null);

        $result = $this->generator->fill(
            $request->user(),
            $wanted,
            $data['servings'] ?? MealPlan::DEFAULT_SERVINGS,
            $targets,
        );

        /*
         * Measured from the plan that was just written, not from what the
         * generator aimed at — see `WeekSummary`. It is also why this is read
         * back rather than returned by `fill()`: a week topped up on top of
         * hand-planned days has to report the whole of what is there.
         */
        return back()->with('generated', $targets === null
            ? $result
            : [...$result, 'week' => $this->reported($this->summary->of(
                $request->user(),
                array_map(strval(...), array_keys($wanted)),
                $targets,
            ))]);
    }

    /**
     * What the week on screen adds up to, or null when nothing is planned.
     *
     * No targets here: the screen has no idea what anybody asked for once the
     * generator's flash is gone, and a "−2%" against a target nobody set would
     * be an invention. The generate response carries the comparison; this
     * carries the facts.
     *
     * @return array<string, mixed>|null
     */
    private function weekReport(Request $request, CarbonImmutable $weekStart): ?array
    {
        $dates = [];

        for ($day = 0; $day < 7; $day++) {
            $dates[] = $weekStart->addDays($day)->toDateString();
        }

        $week = $this->summary->of($request->user(), $dates);

        return $week->kcalPerPersonByDate === [] ? null : $this->reported($week);
    }

    /**
     * The form's percentages, turned into the shares `PlanTargets` insists on.
     *
     * The class throws when they do not add up to a day, and that exception is
     * translated here rather than swallowed: silently normalising 30/45/20 to a
     * whole would hand back a week hitting a target nobody set.
     *
     * @param  array{people: int, kcal: int, budget?: float|null, shares: array<string, int>}|null  $input
     */
    private function targetsFrom(?array $input): ?PlanTargets
    {
        if ($input === null) {
            return null;
        }

        $shares = array_map(static fn (int $percent): float => $percent / 100, $input['shares']);

        try {
            return new PlanTargets(
                people: $input['people'],
                kcalPerPerson: $input['kcal'],
                shares: $shares,
                budget: isset($input['budget']) ? Money::fromZloty((float) $input['budget']) : null,
            );
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages([
                'targets.shares' => $invalid->getMessage(),
            ]);
        }
    }

    /**
     * The summary in the shape the screen reads.
     *
     * Money crosses as integer grosze and is spelled by `lib/money.ts`, like
     * every other price in this app — a float here is the rounding bug that
     * whole rule exists to prevent.
     *
     * @return array<string, mixed>
     */
    private function reported(PlannedWeek $week): array
    {
        return [
            'kcalPerPerson' => $week->averageKcalPerPerson(),
            'proteinPerPerson' => $week->averageProteinPerPerson(),
            'kcalByDate' => $week->kcalPerPersonByDate,
            'calorieGap' => $week->calorieGap(),
            'eats' => $week->price->eats->grosze,
            'buys' => $week->price->buys->grosze,
            'unpricedProducts' => $week->price->unpricedProducts,
            'products' => $week->price->products,
            'confidence' => $week->price->confidence(),
            'fitsBudget' => $week->fitsBudget(),
            'uncounted' => $week->uncounted,
        ];
    }

    /**
     * Swap one planned meal for another of the same kind.
     *
     * A note has nothing to swap it for: it is not a dish the catalogue knows,
     * it is what somebody typed.
     */
    public function swap(Request $request, MealPlanEntry $mealPlanEntry): RedirectResponse
    {
        $this->authoriseOwnership($request, $mealPlanEntry);

        if ($mealPlanEntry->isNote()) {
            throw ValidationException::withMessages([
                'recipe_id' => 'Notatki nie da się podmienić — wpisz ją od nowa.',
            ]);
        }

        $recipe = $this->generator->swap($request->user(), $mealPlanEntry);

        // Nothing left in this meal that has not been eaten lately. Saying so
        // beats silently leaving the same dish and looking like a dead button.
        return back()->with('swapped', [
            'found' => $recipe !== null,
            'slot' => $mealPlanEntry->slot->label(),
        ]);
    }

    /**
     * Which dishes could take this meal's place.
     *
     * A list rather than the shuffle's single answer: pressing shuffle is fine
     * when anything will do and useless when somebody has an opinion about
     * Tuesday. Every row carries the portions that reach the **same meal**, so
     * whichever is picked the day's calories survive.
     *
     * Rendered as a partial visit into the plan screen, like the recipe picker:
     * the sheet is a thing being opened, not a page worth returning to.
     */
    public function alternatives(Request $request, MealPlanEntry $mealPlanEntry): Response
    {
        $this->authoriseOwnership($request, $mealPlanEntry);

        $outgoing = $mealPlanEntry->recipe === null
            ? null
            : ($this->nutrition->for($mealPlanEntry->recipe->load([
                'ingredients.ingredient',
                'ingredients.unit',
            ]))->reliableKcalPerPortion() ?? 0) * $mealPlanEntry->servings;

        return Inertia::render('Plan/Index', [
            ...$this->screen($request),
            /*
             * What is being replaced, in the same units — without it the sheet
             * is a list of numbers with nothing to compare them against.
             */
            'replacing' => [
                'servings' => $mealPlanEntry->servings,
                'kcal' => $outgoing === null ? null : round($outgoing),
            ],
            'alternatives' => array_map(
                fn (MealAlternative $one): array => [
                    'recipeId' => $one->recipeId,
                    'slug' => $one->slug,
                    'title' => $one->title,
                    'imageUrl' => $one->imageUrl,
                    'servings' => $one->servings,
                    /*
                     * Per portion, because that is the unit every other screen
                     * speaks in — the recipe page says "w jednej porcji" and the
                     * week panel says "na osobę". The sheet first showed the
                     * whole meal's calories, so a lunch read as 2 223 kcal
                     * against a 2 500 daily target and looked broken. It was
                     * four portions for two people; the number was right and the
                     * label was missing.
                     */
                    'kcalPerPortion' => $one->kcalPerPortion === null
                        ? null
                        : round($one->kcalPerPortion),
                    'proteinPerPortion' => $one->proteinPerPortion === null
                        ? null
                        : round($one->proteinPerPortion),
                    'kcal' => $one->kcal() === null ? null : round($one->kcal()),
                    'cost' => $one->cost()?->grosze,
                    'missing' => $one->missing,
                ],
                $this->generator->alternativesFor($request->user(), $mealPlanEntry),
            ),
        ]);
    }

    /**
     * Put a chosen dish in a planned meal's place.
     *
     * The portions are recomputed on the way in — see `PlanGenerator::replace()`.
     * A browser is not where the number that keeps the day honest gets decided.
     */
    public function replace(Request $request, MealPlanEntry $mealPlanEntry): RedirectResponse
    {
        $this->authoriseOwnership($request, $mealPlanEntry);

        $data = $request->validate([
            'recipe_id' => ['required', 'exists:recipes,id'],
        ]);

        $this->generator->replace(
            $request->user(),
            $mealPlanEntry,
            Recipe::query()->whereKey($data['recipe_id'])->firstOrFail(),
        );

        return back();
    }

    /** The point of the screen: the days you ticked become the shopping. */
    public function shop(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dates' => ['required', 'array', 'min:1'],
            'dates.*' => ['required', 'date'],
        ]);

        $result = $this->shopping->addMissing($request->user(), array_values($data['dates']));

        return back()->with('plan', $result);
    }

    private function nextPosition(Request $request, string $date, string $slot): int
    {
        return (int) MealPlanEntry::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('date', $date)
            ->where('slot', $slot)
            ->max('position') + 1;
    }

    /** 404 rather than 403, so another account's week is not confirmed to exist. */
    private function authoriseOwnership(Request $request, MealPlanEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
    }
}
