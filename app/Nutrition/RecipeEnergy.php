<?php

declare(strict_types=1);

namespace App\Nutrition;

use App\Enums\IngredientCategory;
use App\Models\RecipeIngredient;

/**
 * What one recipe is worth, and how much of it we actually managed to read.
 *
 * The second half is the point. A calorie figure with no confidence attached is
 * the failure this whole module is built to avoid: a recipe whose butter did not
 * convert to grams comes out light, looks perfectly ordinary, and a week built
 * on six of those is a week of quietly missing meals. So the coverage travels
 * with the number, `isReliable()` is what the planner asks, and the screen can
 * show a figure while saying it is partial.
 *
 * `perPortion` is null when the source never stated how many portions the recipe
 * makes — 78 of ~10 200. Dividing by a guessed number of portions would be the
 * same invention `PlannedIngredients` refuses when it will not scale those
 * recipes.
 */
final readonly class RecipeEnergy
{
    /**
     * @param  Nutrients  $total  the whole dish, at the portions the recipe states
     * @param  Nutrients|null  $perPortion  null when the recipe never said how many
     * @param  float  $coverage  0..1 — the share of countable lines that were read
     * @param  list<string>  $unknown  the products that could not be counted, for the screen to name
     * @param  int|null  $dominantIngredientId  the product this dish gets most of its
     *                                          calories from — what the dish is *made of*,
     *                                          which is not something a title can be
     *                                          trusted to say
     */
    public function __construct(
        public Nutrients $total,
        public ?Nutrients $perPortion,
        public float $coverage,
        public array $unknown = [],
        public ?int $dominantIngredientId = null,
        /**
         * Calories per product across the whole dish, largest first — what the
         * planner reads to tell a dish built on potatoes from one that merely
         * has a soaked roll in its kotlety.
         *
         * @var array<int, float>
         */
        public array $kcalByIngredient = [],
    ) {}

    /**
     * How much of a recipe has to be readable before its calories may be quoted.
     *
     * Four fifths, and the number is a judgement rather than a measurement. Under
     * it the missing fifth can easily be the butter or the rice — the two things
     * that carry a dish's calories — and a figure that might be a third short is
     * not a figure to build a week's eating on. Over it, what is missing is
     * usually a herb or a seasoning, which is the same class of line the shopping
     * shortfall already assumes is at hand.
     *
     * What counts as a line at all is `isCountable()` below, and that decision
     * does more work than this threshold does.
     */
    public const float MINIMUM_COVERAGE = 0.8;

    public function isReliable(): bool
    {
        return $this->coverage >= self::MINIMUM_COVERAGE;
    }

    /**
     * The calories of one portion, but only when there is a portion to speak of
     * and enough of the dish was read to mean it.
     *
     * This is what the planner asks, and it is deliberately the narrowest of the
     * three answers this object holds: everything the screen may show with a
     * caveat, a plan has to be able to add up.
     */
    public function reliableKcalPerPortion(): ?float
    {
        return $this->isReliable() && $this->perPortion !== null
            ? $this->perPortion->kcal
            : null;
    }

    /**
     * Whether a line has to be understood before this recipe's calories can be
     * trusted.
     *
     * **A line that states no amount counts as unread, not as excused**, and
     * that is the correction this rule most needed. The first version excused
     * them — nothing to convert, so nothing to fail at — and on the real
     * catalogue that let 4 412 recipes report full coverage with real food
     * missing: oil heads the list of products named without an amount at 1 017
     * lines, then lemon, olive oil, butter, sugar and flour. A cheesecake whose
     * butter was never weighed is not a cheesecake we can count.
     *
     * The exception is a line whose product cannot move the number whatever the
     * amount — a spice or a herb (`IngredientCategory::isCaloricallyNegligible()`).
     * "Sól do smaku" is the commonest line in the catalogue and it is worth no
     * calories at any plausible amount; counting it as unread would make nearly
     * every dish look uncountable for the most ordinary reason there is.
     *
     * Equipment is never eaten, and an optional line is not cooked unless
     * somebody decides to — the same reason the shopping shortfall leaves it out.
     */
    public static function isCountable(RecipeIngredient $line): bool
    {
        if ($line->is_optional) {
            return false;
        }

        $category = $line->ingredient?->category;

        if ($category === IngredientCategory::Equipment) {
            return false;
        }

        return $line->toQuantity() !== null
            || $category === null
            || ! $category->isCaloricallyNegligible();
    }
}
