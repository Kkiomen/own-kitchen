<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use App\Enums\UnitDimension;

/**
 * What one product's measures weigh — the bridge between "2 cebule", "pół
 * szklanki mleka" and "300 g".
 *
 * `Quantity` alone can never cross a dimension, and that is correct: grams and
 * millilitres are genuinely different things until you say *of what*. This class
 * is that "of what". Two cloves of garlic weigh 10 g; two cloves of anything else
 * weigh nothing in particular.
 *
 * Lookup is layered, most specific first, exactly like ingredient aliases:
 *
 * 1. An explicit weight for this exact (product, unit) pair. The authority.
 * 2. Density, for any volume unit with no explicit weight of its own.
 * 3. Nothing — and nothing means null, never a guess.
 *
 * The layering is not tidiness. A tablespoon of flour is not 15 ml × 0.53 = 8 g;
 * Polish recipes mean a heaped spoon, nearer 15 g. So flour carries both: a
 * density for millilitres and glasses, and an explicit spoon weight that
 * overrides it.
 */
final readonly class IngredientMeasures
{
    /**
     * @param  array<string, float>  $gramsPerUnit  unit code => what one of them weighs
     */
    public function __construct(
        public ?float $densityGramsPerMl = null,
        public array $gramsPerUnit = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->densityGramsPerMl === null && $this->gramsPerUnit === [];
    }

    /**
     * The same amount expressed in grams, or null when we have no honest way to
     * say. Null is the important half: an unknown weight must never be filled in
     * with an average, because everything downstream — "have I got enough?", "how
     * many packs?", "what will this cost?" — would then be confidently wrong.
     */
    public function toGrams(?Quantity $quantity): ?Quantity
    {
        if ($quantity === null) {
            return null;
        }

        if ($quantity->unit->dimension === UnitDimension::Mass) {
            return $quantity->convertTo(UnitDefinition::base(UnitDimension::Mass));
        }

        $gramsPerUnit = $this->gramsPer($quantity->unit);

        return $gramsPerUnit === null
            ? null
            : new Quantity($quantity->amount * $gramsPerUnit, UnitDefinition::base(UnitDimension::Mass));
    }

    /**
     * Grams back into the measure someone actually shops or cooks in: "1500 g of
     * onion" as "10 sztuk". Null whenever the weight of one is unknown.
     */
    public function fromGrams(?Quantity $grams, UnitDefinition $target): ?Quantity
    {
        if ($grams === null || $grams->unit->dimension !== UnitDimension::Mass) {
            return null;
        }

        if ($target->dimension === UnitDimension::Mass) {
            return $grams->convertTo($target);
        }

        $gramsPerUnit = $this->gramsPer($target);

        return $gramsPerUnit === null || $gramsPerUnit <= 0.0
            ? null
            : new Quantity($grams->toBase() / $gramsPerUnit, $target);
    }

    /**
     * Whether what is held covers what is needed, across units that do not
     * otherwise compare.
     *
     * Three answers, not two: yes, no, and **null for "cannot say"**. Callers must
     * read null as "stay quiet", never as "short" — telling someone they are out
     * of onions because nobody recorded what an onion weighs is worse than saying
     * nothing.
     */
    public function covers(?Quantity $held, ?Quantity $needed): ?bool
    {
        if ($held === null || $needed === null) {
            return null;
        }

        if ($held->unit->isCompatibleWith($needed->unit)) {
            return $held->covers($needed);
        }

        $heldGrams = $this->toGrams($held);
        $neededGrams = $this->toGrams($needed);

        return $heldGrams === null || $neededGrams === null
            ? null
            : $heldGrams->covers($neededGrams);
    }

    /**
     * Two amounts of this product added up, converting where it takes conversion.
     *
     * Deliberately not folded into `Quantity::sum()`: that function knows nothing
     * about products and must keep refusing grams + millilitres, because without a
     * product there is no answer. This is the same rule with the missing half
     * supplied.
     *
     * The result keeps the first amount's unit where it can, so a kitchen counted
     * in pieces goes on reading in pieces rather than turning into grams the
     * moment a second entry arrives.
     */
    public function sum(?Quantity $first, ?Quantity $second): ?Quantity
    {
        if ($first === null || $second === null) {
            return null;
        }

        if ($first->unit->isCompatibleWith($second->unit)) {
            return $first->add($second);
        }

        $firstGrams = $this->toGrams($first);
        $secondGrams = $this->toGrams($second);

        if ($firstGrams === null || $secondGrams === null) {
            return null;
        }

        $total = $firstGrams->add($secondGrams);

        return $this->fromGrams($total, $first->unit) ?? $total;
    }

    /**
     * How much of this product still has to be bought.
     *
     * Nothing held means the whole amount; some held means the difference, across
     * units where the product has been weighed. Where it has not, the honest
     * answer is the **whole** amount rather than null: this is the one place
     * where "cannot say" must not turn into "buy nothing", or a kitchen holding
     * one unmeasured onion would quietly cancel a recipe's line for four.
     *
     * `Quantity::subtract()` floors at zero, so a covered line comes back as an
     * amount of nothing — callers drop those rather than writing them down.
     */
    public function shortfall(?Quantity $held, ?Quantity $required): ?Quantity
    {
        if ($required === null || $held === null) {
            return $required;
        }

        if ($held->unit->isCompatibleWith($required->unit)) {
            return $required->subtract($held);
        }

        $requiredGrams = $this->toGrams($required);
        $heldGrams = $this->toGrams($held);

        if ($requiredGrams === null || $heldGrams === null) {
            return $required;
        }

        return $this->fromGrams($requiredGrams->subtract($heldGrams), $required->unit) ?? $required;
    }

    /**
     * Grams per one of this unit: the explicit weight if there is one, otherwise
     * density for a volume.
     */
    private function gramsPer(UnitDefinition $unit): ?float
    {
        $explicit = $this->gramsPerUnit[$unit->code] ?? null;

        if ($explicit !== null) {
            return $explicit;
        }

        if ($unit->dimension === UnitDimension::Volume && $this->densityGramsPerMl !== null) {
            return $unit->factorToBase * $this->densityGramsPerMl;
        }

        return null;
    }
}
