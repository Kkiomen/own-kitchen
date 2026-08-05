<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use InvalidArgumentException;

/**
 * An amount together with the unit it is expressed in. Immutable: every operation
 * returns a new instance, so a quantity can never be mutated by accident while a
 * shopping list or a pantry deduction is being calculated.
 */
final readonly class Quantity
{
    public function __construct(
        public float $amount,
        public UnitDefinition $unit,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException("Quantity cannot be negative, got {$amount}.");
        }
    }

    /**
     * The amount expressed in the dimension's base unit (grams, millilitres, items).
     * This is the form to compare and sum in, never the display form.
     */
    public function toBase(): float
    {
        return $this->amount * $this->unit->factorToBase;
    }

    public function convertTo(UnitDefinition $target): self
    {
        $this->guardCompatible($target);

        return new self($this->toBase() / $target->factorToBase, $target);
    }

    public function add(self $other): self
    {
        $this->guardCompatible($other->unit);

        return new self($this->amount + $other->convertTo($this->unit)->amount, $this->unit);
    }

    public function subtract(self $other): self
    {
        $this->guardCompatible($other->unit);

        return new self(
            max(0.0, $this->amount - $other->convertTo($this->unit)->amount),
            $this->unit,
        );
    }

    public function multipliedBy(float $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException("Cannot scale a quantity by a negative factor, got {$factor}.");
        }

        return new self($this->amount * $factor, $this->unit);
    }

    /**
     * Whether this quantity covers what a recipe asks for — the question the
     * "can I cook this from what is in the fridge?" query is built on.
     */
    public function covers(self $required): bool
    {
        if (! $this->unit->isCompatibleWith($required->unit)) {
            return false;
        }

        return $this->toBase() + self::EPSILON >= $required->toBase();
    }

    /**
     * Two amounts that may not be knowable, added honestly.
     *
     * `null` means "some, amount unknown" everywhere in this app, and it is
     * infectious on purpose: a total that includes an unknown part is itself
     * unknown, and so is a total of grams and millilitres. Inventing a number
     * here would put a wrong figure on a shopping list or claim the fridge holds
     * enough when nobody ever said how much is in it.
     */
    public static function sum(?self $first, ?self $second): ?self
    {
        if ($first === null || $second === null) {
            return null;
        }

        return $first->unit->isCompatibleWith($second->unit)
            ? $first->add($second)
            : null;
    }

    public function equals(self $other): bool
    {
        return $this->unit->isCompatibleWith($other->unit)
            && abs($this->toBase() - $other->toBase()) < self::EPSILON;
    }

    /**
     * Any unit involved being a kitchen approximation ("a pinch") makes the result
     * unfit for budgeting, even if the arithmetic worked.
     */
    public function isApproximate(): bool
    {
        return $this->unit->isApproximate;
    }

    private function guardCompatible(UnitDefinition $target): void
    {
        if (! $this->unit->isCompatibleWith($target)) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->unit->dimension->value} with {$target->dimension->value}: "
                ."units '{$this->unit->code}' and '{$target->code}' measure different things."
            );
        }
    }

    private const float EPSILON = 0.0001;
}
