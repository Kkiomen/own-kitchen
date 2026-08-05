<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Measurement\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product to buy, on one of the household's lists.
 *
 * @property int $id
 * @property int $shopping_list_id
 * @property int $ingredient_id
 * @property float|null $quantity
 * @property int|null $unit_id
 * @property CarbonImmutable|null $bought_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 * @property-read Unit|null $unit
 * @property-read ShoppingList $list
 */
#[Fillable(['shopping_list_id', 'ingredient_id', 'quantity', 'unit_id', 'bought_at', 'note'])]
class ShoppingListItem extends Model
{
    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The list owns the line, and the list knows whose it is — which is why
     * there is no `user_id` here to disagree with it.
     *
     * @return BelongsTo<ShoppingList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeStillToBuy(Builder $query): void
    {
        $query->whereNull('bought_at');
    }

    public function isBought(): bool
    {
        return $this->bought_at !== null;
    }

    /**
     * Null when no amount was given — "kup chleb" is a complete instruction.
     */
    public function toQuantity(): ?Quantity
    {
        if ($this->quantity === null || $this->unit === null) {
            return null;
        }

        return $this->unit->quantity($this->quantity);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'bought_at' => 'immutable_datetime',
        ];
    }
}
