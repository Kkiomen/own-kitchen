<?php

declare(strict_types=1);

namespace App\Importing\Quality;

/**
 * What the imported catalogue currently looks like. Everything here is a plain
 * number so it can be asserted in a test and compared between import runs.
 */
final readonly class QualitySnapshot
{
    /**
     * @param  list<string>  $unresolvedLines  Raw wording of lines whose product was not recognised.
     * @param  list<string>  $recipesNeedingReview  Slugs.
     */
    public function __construct(
        public int $recipes,
        public int $ingredientLines,
        public int $steps,
        public int $products,
        /** Products the importer invented and nobody has vouched for yet. */
        public int $productsAwaitingCuration,
        public int $linesWithProduct,
        public int $linesWithQuantity,
        public int $linesWithUnit,
        public int $linesNeedingReview,
        public int $stepsWithAction,
        public int $stepsWithAppliance,
        public int $stepsWithTemperature,
        public int $stepsWithDuration,
        public int $stepsWithoutIngredients,
        public int $stepIngredientLinks,
        public int $recipesWithImage,
        public array $unresolvedLines,
        public array $recipesNeedingReview,
    ) {}

    public function percentOfLines(int $count): float
    {
        return $this->ingredientLines === 0 ? 0.0 : round(100 * $count / $this->ingredientLines, 1);
    }

    public function percentOfSteps(int $count): float
    {
        return $this->steps === 0 ? 0.0 : round(100 * $count / $this->steps, 1);
    }

    /**
     * The single number worth gating on: anything above a few percent means the
     * dictionary needs entries before importing more.
     */
    public function reviewPercent(): float
    {
        return $this->percentOfLines($this->linesNeedingReview);
    }
}
