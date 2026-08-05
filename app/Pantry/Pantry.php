<?php

declare(strict_types=1);

namespace App\Pantry;

use App\Models\PantryItem;
use App\Models\User;
use App\Support\Measurement\IngredientMeasures;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;

/**
 * What one kitchen currently holds, loaded once and asked many questions.
 *
 * The same product may sit in two places — cheese in the fridge and in the
 * freezer — and for "can I cook this?" the answer is the two added together.
 */
final class Pantry
{
    /**
     * @param  array<int, list<PantryItem>>  $itemsByIngredient
     */
    private function __construct(
        private readonly array $itemsByIngredient,
        private readonly MeasureBook $measures,
    ) {}

    public static function of(User $user, MeasureBook $measures): self
    {
        $byIngredient = [];

        $items = PantryItem::query()
            ->where('user_id', $user->id)
            ->with('unit')
            ->get();

        foreach ($items as $item) {
            $byIngredient[$item->ingredient_id][] = $item;
        }

        return new self($byIngredient, $measures);
    }

    /**
     * What a piece or a spoonful of this product weighs.
     *
     * Exposed because the questions asked *about* a kitchen — "is there enough?",
     * "how much still to buy?" — need the same conversions the kitchen itself
     * does, and threading a second collaborator through every caller to say the
     * same thing twice would be the duplication this app keeps avoiding.
     */
    public function measuresFor(int $ingredientId): IngredientMeasures
    {
        return $this->measures->for($ingredientId);
    }

    public function has(int $ingredientId): bool
    {
        return isset($this->itemsByIngredient[$ingredientId]);
    }

    /**
     * The total held across every place, or null when no amount was ever given.
     *
     * Null means "some, amount unknown" — never "none". Someone who wrote down
     * that they have salt should not be told they are short of salt.
     *
     * Two shelves in different units used to make the total unknowable outright.
     * With a weight for the product they often add up now — 2 cebule in the
     * fridge plus 300 g in the pantry is 600 g — and where there is no weight the
     * answer is still null, unchanged.
     */
    public function amountOf(int $ingredientId): ?Quantity
    {
        $total = null;

        $first = true;
        $measures = $this->measures->for($ingredientId);

        foreach ($this->itemsByIngredient[$ingredientId] ?? [] as $item) {
            $quantity = $item->toQuantity();

            // One unmeasured entry still makes the total unknowable — and unknown
            // must not read as a shortage.
            $total = $first ? $quantity : $measures->sum($total, $quantity);
            $first = false;

            if ($total === null) {
                return null;
            }
        }

        return $total;
    }

    /**
     * @return list<PantryItem>
     */
    public function itemsFor(int $ingredientId): array
    {
        return $this->itemsByIngredient[$ingredientId] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->itemsByIngredient === [];
    }
}
