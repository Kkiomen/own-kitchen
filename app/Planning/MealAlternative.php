<?php

declare(strict_types=1);

namespace App\Planning;

use App\Support\Money\Money;

/**
 * One dish offered as a replacement for a planned meal, with the two things
 * somebody needs to choose between them.
 *
 * **The portions are part of the offer, not an afterthought.** Each candidate is
 * a different size, so the number of portions that reaches the same meal differs
 * from dish to dish — showing the dishes without it would leave the person
 * picking to guess, and picking wrong is how a day quietly gains a thousand
 * calories.
 */
final readonly class MealAlternative
{
    /**
     * @param  int  $servings  portions of this dish that deliver the same meal
     * @param  int|null  $missing  products the kitchen is short of, or null when
     *                             there is no kitchen to compare against
     */
    public function __construct(
        public int $recipeId,
        public string $slug,
        public string $title,
        public ?string $imageUrl,
        public int $servings,
        public ?float $kcalPerPortion,
        public ?float $proteinPerPortion,
        public ?Money $costPerPortion,
        public ?int $missing,
    ) {}

    /** What the whole meal comes to, which is the figure being matched. */
    public function kcal(): ?float
    {
        return $this->kcalPerPortion === null ? null : $this->kcalPerPortion * $this->servings;
    }

    public function protein(): ?float
    {
        return $this->proteinPerPortion === null ? null : $this->proteinPerPortion * $this->servings;
    }

    public function cost(): ?Money
    {
        return $this->costPerPortion?->scaledBy($this->servings);
    }
}
