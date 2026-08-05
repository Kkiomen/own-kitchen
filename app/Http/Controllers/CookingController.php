<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Catalogue\RecipeScale;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cooking one dish, one step at a time.
 *
 * A screen of its own rather than a mode of the recipe modal: the modal is for
 * reading and shopping, this is for standing at the hob with wet hands. It is
 * also a different payload — deliberately not `RecipeController::detail()`.
 * Nothing here needs the source, the tags or what the kitchen holds, and asking
 * the pantry about every line would be work done for a question nobody is
 * asking mid-recipe.
 */
class CookingController extends Controller
{
    public function __construct(private readonly IngredientEmoji $emoji) {}

    public function show(Request $request, Recipe $recipe): Response
    {
        $recipe->load(['steps.ingredients.ingredient', 'steps.ingredients.unit']);

        /*
         * The same `?porcje=` the recipe screen reads, applied to the loaded
         * lines before anything is presented. Cooking a recipe scaled to six and
         * being told to add the amount for four is the one way this screen could
         * be actively wrong, and the link from the modal carries the number over.
         */
        $scale = RecipeScale::for($recipe, RecipeScale::requestedFrom($request));
        $scale->applyTo($recipe);

        return Inertia::render('Cooking/Show', [
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'servingsLabel' => $recipe->servings_label,
                'servings' => $scale->servings,
                'isScaled' => $scale->isScaled(),
                'appliance' => $recipe->appliance?->iconKey(),
                'steps' => $recipe->steps
                    ->map(fn (RecipeStep $step): array => $this->present($step))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RecipeStep $step): array
    {
        return [
            'position' => $step->position,
            'section' => $step->section,
            'instruction' => $step->instruction,
            'action' => $step->action?->value,
            'appliance' => $step->appliance?->iconKey(),
            'temperatureCelsius' => $step->temperature_celsius,
            /*
             * What the timer is offered for. It is the step's own stated time —
             * never inferred from the words — so a step that says nothing about
             * waiting simply gets no clock.
             */
            'durationSeconds' => $step->duration_seconds,
            'uses' => $step->ingredients
                ->map(fn (RecipeIngredient $line): array => [
                    'id' => $line->id,
                    'name' => $line->ingredient?->name,
                    'emoji' => $this->emoji->for($line->ingredient?->name, $line->ingredient?->category),
                    'quantity' => $line->quantity,
                    'quantityMax' => $line->quantity_max,
                    'unit' => $line->unit?->symbol,
                    'note' => $line->note,
                    // An unresolved line still has to be cookable: the original
                    // wording is all there is, and it is better than nothing.
                    'rawText' => $line->raw_text,
                ])
                ->values()
                ->all(),
        ];
    }
}
