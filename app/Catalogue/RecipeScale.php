<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Http\Request;

/**
 * Reading a recipe for a different number of portions than it was written for.
 *
 * The arithmetic is trivial; where it is applied is the whole design. Scaling in
 * the browser would leave every other answer on the screen speaking about the
 * original amounts — the "masz" marker beside each line, and the shortfall the
 * shopping-list button writes down. Both come from `RecipeAvailability`, which
 * reads `RecipeIngredient::toQuantity()`. So the factor is applied **to the
 * loaded lines, once, before anything asks them anything**, and every later
 * answer is about the amounts actually on screen without knowing this class
 * exists.
 *
 * Nothing is saved. These are hydrated models serving one request; `raw_text` is
 * untouched, so "pokaż oryginalny tekst" still shows what the source wrote — the
 * one place on the screen that must never be scaled, since it is the evidence
 * the import is checked against.
 *
 * **A recipe that never said how many portions it makes cannot be scaled**, and
 * 78 of ~10 200 do not. There is no factor to compute, so the control is not
 * offered at all rather than offered as a multiplier the cook would have to
 * reason about in the abstract.
 *
 * Note the deliberate difference from `App\Planning\PlannedIngredients`, which
 * never scales *below* one whole batch: cooking two portions of a recipe for
 * four means half an egg and a third of a tin, so a week's shopping buys the
 * pot whole. That guard belongs there because the planner chooses the portions
 * on the household's behalf. Here the cook has typed the number, and answering
 * "6 porcji" with the amounts for eight would be refusing to answer.
 */
final readonly class RecipeScale
{
    /**
     * Enough for a party, small enough that a typo cannot ask for a tonne of
     * flour. Nobody cooks a recipe fifty times over in a home kitchen.
     */
    public const int MAX_SERVINGS = 50;

    private function __construct(
        /** What the source wrote the recipe for, or null when it never said. */
        public ?int $base,
        /** How many portions the cook asked for. Null exactly when `base` is. */
        public ?int $servings,
        public float $factor,
    ) {}

    public static function for(Recipe $recipe, ?int $servings): self
    {
        $base = $recipe->servings;

        if ($base === null || $base <= 0) {
            return new self(null, null, 1.0);
        }

        $wanted = max(1, min(self::MAX_SERVINGS, $servings ?? $base));

        return new self($base, $wanted, $wanted / $base);
    }

    /**
     * How many portions the URL is asking for.
     *
     * Read leniently rather than validated into a 422: this arrives in a link
     * somebody may have shared or edited by hand, and a nonsense value should
     * show the recipe as written rather than an error page. `for()` clamps it.
     *
     * Lives here so every screen that reads amounts — the recipe and the cooking
     * steps — takes the number from one place. Two readers drifting apart would
     * put one set of amounts on the page and another on the hob.
     */
    public static function requestedFrom(Request $request): ?int
    {
        $asked = $request->query('porcje');

        return is_numeric($asked) ? (int) $asked : null;
    }

    /** Whether the screen can offer the control at all. */
    public function isAvailable(): bool
    {
        return $this->base !== null;
    }

    public function isScaled(): bool
    {
        return $this->factor !== 1.0;
    }

    /**
     * Rewrite the loaded amounts in place.
     *
     * Both collections, and that is not belt-and-braces: `$recipe->ingredients`
     * and `$step->ingredients` are separate hydrations of overlapping rows, so
     * scaling one leaves the guided-cooking lines quoting the original amounts
     * beside the scaled ingredient list.
     */
    public function applyTo(Recipe $recipe): void
    {
        if (! $this->isScaled()) {
            return;
        }

        if ($recipe->relationLoaded('ingredients')) {
            $recipe->ingredients->each($this->scaleLine(...));
        }

        if (! $recipe->relationLoaded('steps')) {
            return;
        }

        foreach ($recipe->steps as $step) {
            if ($step->relationLoaded('ingredients')) {
                $step->ingredients->each($this->scaleLine(...));
            }
        }
    }

    private function scaleLine(RecipeIngredient $line): void
    {
        if ($line->quantity !== null) {
            $line->quantity *= $this->factor;
        }

        if ($line->quantity_max !== null) {
            $line->quantity_max *= $this->factor;
        }
    }
}
