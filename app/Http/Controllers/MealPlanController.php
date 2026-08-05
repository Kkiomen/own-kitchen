<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\RecipeListing;
use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Planning\MealPlan;
use App\Planning\PlanGenerator;
use App\Planning\PlannedShopping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
    ) {}

    public function index(Request $request): Response
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

        return Inertia::render('Plan/Index', [
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
            // A closure, so browsing the week never runs the search query:
            // Inertia drops props a partial visit did not ask for *before* it
            // evaluates them.
            'matches' => fn (): array => $this->listing->search($search, $slot),
        ]);
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
        ]);

        $wanted = [];

        // Keyed by date, so the same day asked for twice is still one day.
        foreach ($data['days'] as $day) {
            $wanted[$day['date']] = array_map(MealSlot::from(...), array_values($day['slots']));
        }

        ksort($wanted);

        $result = $this->generator->fill(
            $request->user(),
            $wanted,
            $data['servings'] ?? MealPlan::DEFAULT_SERVINGS,
        );

        return back()->with('generated', $result);
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
