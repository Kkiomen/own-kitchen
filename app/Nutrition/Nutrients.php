<?php

declare(strict_types=1);

namespace App\Nutrition;

/**
 * What some actual amount of food is worth — a whole recipe, one portion, one
 * line of one recipe.
 *
 * Calories are a plain float and the macros are nullable, and that asymmetry is
 * the whole design. A plan is built to hit a calorie target, so a value that
 * cannot state calories is not a value at all; protein, fat and carbohydrate are
 * a second question, and a source may answer the first without the second.
 *
 * **A null macro is infectious across addition**, on the same rule
 * `Quantity::sum()` follows: unknown protein plus 12 g of protein is unknown, not
 * 12. Adding them as zero would quietly report a week as low in protein because
 * one ingredient's reading was incomplete — a wrong number that looks like a
 * finding.
 */
final readonly class Nutrients
{
    public function __construct(
        public float $kcal,
        public ?float $protein = null,
        public ?float $fat = null,
        public ?float $carbs = null,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->kcal + $other->kcal,
            self::add($this->protein, $other->protein),
            self::add($this->fat, $other->fat),
            self::add($this->carbs, $other->carbs),
        );
    }

    public function scaledBy(float $factor): self
    {
        return new self(
            $this->kcal * $factor,
            $this->protein === null ? null : $this->protein * $factor,
            $this->fat === null ? null : $this->fat * $factor,
            $this->carbs === null ? null : $this->carbs * $factor,
        );
    }

    private static function add(?float $first, ?float $second): ?float
    {
        return $first === null || $second === null ? null : $first + $second;
    }
}
