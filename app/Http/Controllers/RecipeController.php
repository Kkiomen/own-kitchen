<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Catalogue\RecipeFilter;
use App\Catalogue\RecipeListing;
use App\Catalogue\RecipeScale;
use App\Models\Category;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Nutrition\RecipeEnergy;
use App\Nutrition\RecipeNutrition;
use App\Pantry\Pantry;
use App\Pantry\RecipeAvailability;
use App\Shopping\ShoppingLists;
use App\Support\Measurement\MeasureBook;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browsing surface for the imported catalogue. `show` renders the same page as
 * `index` with one extra prop, which is what lets the detail open as a modal over
 * the list while still having its own shareable URL.
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeListing $listing,
        private readonly IngredientEmoji $emoji,
        private readonly MeasureBook $measures,
        private readonly RecipeAvailability $availability,
        private readonly ShoppingLists $lists,
        private readonly RecipeNutrition $nutrition,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Recipes/Index', $this->list($request));
    }

    public function show(Request $request, Recipe $recipe): Response
    {
        return Inertia::render('Recipes/Index', [
            ...$this->list($request),
            'recipe' => $this->detail($request, $recipe),

            /*
             * So the modal can ask which list the shortfall goes on. Sent only
             * by `show`, and asked for by name in the partial visit that opens
             * the modal: scrolling the catalogue must not pay for a query about
             * shopping lists.
             */
            'shoppingLists' => $this->lists->of($request->user()),
        ]);
    }

    /**
     * The list as both routes render it.
     *
     * `recipes` is a merge prop: asking for the next page appends to what the
     * browser already has instead of replacing it, which is what makes the
     * infinite scroll a partial visit rather than a re-render of everything.
     * Changing the search or the chip resets it from the client.
     *
     * @return array<string, mixed>
     */
    private function list(Request $request): array
    {
        $filter = RecipeFilter::fromRequest($request);
        $page = $this->listing->page($request->user(), $filter);

        return [
            'recipes' => Inertia::merge($page['recipes']),
            'total' => $page['total'],
            'hasMore' => $page['hasMore'],
            /*
             * Closures, because Inertia drops props a partial visit did not ask
             * for *before* it evaluates them. Scrolling the list asks only for
             * the next page, and these three were being computed and thrown away
             * on every one of those — the chip counts included, which is the
             * catalogue-wide shortfall aggregate.
             */
            'counts' => fn (): array => $this->listing->counts($request->user()),
            'categories' => fn (): array => $this->categories(),
            'hasPantry' => fn (): bool => $this->hasPantry($request),
            'search' => $filter->search,
            'filter' => $filter->key,
        ];
    }

    /**
     * Whether this kitchen holds anything at all. Until it does, the two shortcuts
     * that filter by what you have would both read zero, which looks like a fault
     * rather than an invitation.
     */
    private function hasPantry(Request $request): bool
    {
        return PantryItem::query()->where('user_id', $request->user()->id)->exists();
    }

    /**
     * The quick-pick row. Counts come from the pivot rather than being tallied in
     * the browser, so the row is right before a single recipe is rendered.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        $categories = [];

        $rows = Category::query()
            ->withCount('recipes')
            ->orderBy('position')
            ->get();

        foreach ($rows as $category) {
            // A rule that currently matches nothing would be a dead button.
            if ($category->recipes_count === 0) {
                continue;
            }

            $categories[] = [
                'slug' => $category->slug,
                'name' => $category->name,
                'icon' => $category->icon,
                'count' => $category->recipes_count,
            ];
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * What a portion of this is worth, and how sure we are.
     *
     * The coverage travels with the numbers rather than being rounded away. A
     * recipe whose butter never reached grams comes out light and looks
     * perfectly ordinary, so the screen has to be able to say "co najmniej"
     * instead of quoting a figure it cannot stand behind.
     *
     * Null throughout when the source never said how many portions the recipe
     * makes — 78 of ~10 200 — because there is no honest per-portion figure to
     * divide into.
     *
     * @return array<string, mixed>
     */
    private function nutritionOf(RecipeEnergy $energy): array
    {
        $portion = $energy->perPortion;

        return [
            'perPortion' => $portion === null ? null : [
                'kcal' => round($portion->kcal),
                'protein' => $portion->protein === null ? null : round($portion->protein, 1),
                'fat' => $portion->fat === null ? null : round($portion->fat, 1),
                'carbs' => $portion->carbs === null ? null : round($portion->carbs, 1),
            ],
            'total' => [
                'kcal' => round($energy->total->kcal),
            ],
            'coverage' => round($energy->coverage, 2),
            'isReliable' => $energy->isReliable(),
            // Named so the screen can say which lines it could not count rather
            // than only that it could not count them all.
            'unknown' => array_slice($energy->unknown, 0, 5),
        ];
    }

    /**
     * One recipe, in the shape the modal reads.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, Recipe $recipe): array
    {
        $recipe->load([
            'tags',
            'ingredients.ingredient',
            'ingredients.unit',
            'steps.ingredients.ingredient',
            'steps.ingredients.unit',
        ]);

        /*
         * Before the pantry is consulted, so the "masz" marker on every line and
         * the amounts beside it are answers about the same number of portions.
         */
        $scale = RecipeScale::for($recipe, RecipeScale::requestedFrom($request));
        $scale->applyTo($recipe);

        $pantry = Pantry::of($request->user(), $this->measures);

        /*
         * Read after `applyTo`, so the figures describe the portions on screen:
         * the scaled rows are the ones asked, and dividing them by the scaled
         * servings is what makes "w jednej porcji" mean the porcja being shown.
         */
        $energy = $this->nutrition->ofLines($recipe->ingredients, $scale->servings);

        return [
            'slug' => $recipe->slug,
            'nutrition' => $this->nutritionOf($energy),
            'scale' => [
                'base' => $scale->base,
                'servings' => $scale->servings,
                'isScaled' => $scale->isScaled(),
            ],
            'title' => $recipe->title,
            'description' => $recipe->description,
            'imageUrl' => $recipe->image_url,
            'servings' => $recipe->servings,
            'servingsLabel' => $recipe->servings_label,
            'totalTimeMinutes' => $recipe->total_time_minutes,
            'appliance' => $recipe->appliance?->iconKey(),
            'isMealPrep' => $recipe->is_meal_prep,
            'sourceName' => $recipe->source_name,
            'sourceUrl' => $recipe->source_url,
            'importedAt' => $recipe->imported_at?->toDateTimeString(),
            'needsReview' => $recipe->needs_review,
            /*
             * Whether the per-line answers are worth showing at all. On an empty
             * kitchen every one of them is "nie masz", which is not information —
             * it is the same sentence forty times over.
             */
            'hasPantry' => ! $pantry->isEmpty(),
            'tags' => $recipe->tags->pluck('name')->all(),
            'ingredients' => $recipe->ingredients
                ->map(fn (RecipeIngredient $line): array => $this->presentLine($line, $pantry))
                ->all(),
            'steps' => $recipe->steps->map(fn (RecipeStep $step): array => [
                'position' => $step->position,
                'section' => $step->section,
                'instruction' => $step->instruction,
                'action' => $step->action?->value,
                'appliance' => $step->appliance?->iconKey(),
                'temperatureCelsius' => $step->temperature_celsius,
                'durationSeconds' => $step->duration_seconds,
                'needsReview' => $step->needs_review,
                'uses' => $step->ingredients
                    ->map(fn (RecipeIngredient $line): array => $this->presentLine($line, $pantry))
                    ->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLine(RecipeIngredient $line, Pantry $pantry): array
    {
        return [
            'id' => $line->id,
            /*
             * Both sides of the answer, because a cook standing in the kitchen
             * wants to know what to fetch as much as what is already there. The
             * five values are `RecipeAvailability`'s, so the marker on a line and
             * the amount the shopping-list button adds cannot disagree — the two
             * used to be computed separately, and a line could read "masz" while
             * the trolley still asked for more of it.
             */
            'status' => $this->availability->statusFor($line, $pantry),
            'quantity' => $line->quantity,
            'quantityMax' => $line->quantity_max,
            'unit' => $line->unit?->symbol,
            'unitCode' => $line->unit?->code,
            'ingredient' => $line->ingredient?->name,
            'category' => $line->ingredient?->category->value,
            // An unresolved line has no product, so it falls back to the plate.
            'emoji' => $this->emoji->for($line->ingredient?->name, $line->ingredient?->category),
            'note' => $line->note,
            'section' => $line->section,
            'rawText' => $line->raw_text,
            'isOptional' => $line->is_optional,
            'needsReview' => $line->needs_review,
        ];
    }
}
