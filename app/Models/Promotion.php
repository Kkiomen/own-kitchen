<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnitDimension;
use App\Support\Measurement\Quantity;
use App\Support\Money\Money;
use App\Support\Money\UnitPrice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in one shop's current leaflet.
 *
 * A promotion points at an `Ingredient` for exactly the same reason a recipe line
 * does: so "masło" on the shopping list, "Masło Ekstra Mleczna Dolina 200 g" in
 * Biedronka's leaflet and "Masła" in a recipe are one product and not three.
 * When it cannot point at one it points at nothing — see `needs_review`.
 *
 * @property int $id
 * @property int $shop_id
 * @property string $source
 * @property string $external_id
 * @property string $title
 * @property int|null $ingredient_id
 * @property bool $needs_review
 * @property int $price_minor
 * @property int|null $regular_price_minor
 * @property int|null $discount_percent
 * @property float|null $pack_quantity
 * @property int|null $pack_unit_id
 * @property int|null $unit_price_minor
 * @property UnitDimension|null $unit_price_per
 * @property Carbon|null $valid_to
 * @property string $url
 * @property string|null $image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read Ingredient|null $ingredient
 * @property-read Unit|null $packUnit
 */
#[Fillable([
    'shop_id', 'source', 'external_id', 'title', 'ingredient_id', 'needs_review',
    'price_minor', 'regular_price_minor', 'discount_percent',
    'pack_quantity', 'pack_unit_id', 'unit_price_minor', 'unit_price_per',
    'valid_to', 'url', 'image_url',
])]
class Promotion extends Model
{
    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

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
    public function packUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'pack_unit_id');
    }

    /**
     * Offers that have not run out.
     *
     * An offer with no end date counts as running: the source states a duration
     * for most entries but not all, and dropping the ones it stayed quiet about
     * would silently hide real promotions. A stale one costs a wasted look at a
     * shelf; a hidden one costs the whole feature.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString());
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @param  list<int>  $ingredientIds
     */
    public function scopeForIngredients(Builder $query, array $ingredientIds): void
    {
        $query->whereIn('ingredient_id', $ingredientIds);
    }

    /**
     * Narrowed to the chains the household drives to.
     *
     * Null narrows nothing — an empty selection means "not chosen yet", and a
     * caller that turned that into `whereIn('shop_id', [])` would answer every
     * question with silence. See `App\Shopping\SelectedShops`.
     *
     * @param  Builder<$this>  $query
     * @param  list<int>|null  $shopIds
     */
    public function scopeInShops(Builder $query, ?array $shopIds): void
    {
        if ($shopIds !== null) {
            $query->whereIn('shop_id', $shopIds);
        }
    }

    public function price(): Money
    {
        return new Money($this->price_minor);
    }

    public function regularPrice(): ?Money
    {
        return $this->regular_price_minor === null ? null : new Money($this->regular_price_minor);
    }

    /**
     * What this offer saves against the same shop's own normal price. Null when
     * the leaflet never printed a "before" — most entries do not, and a saving
     * of "0 zł" reads as "no saving" rather than "unknown".
     */
    public function savings(): ?Money
    {
        $regular = $this->regularPrice();

        if ($regular === null || ! $this->price()->isLessThan($regular)) {
            return null;
        }

        return $regular->minus($this->price());
    }

    public function packSize(): ?Quantity
    {
        if ($this->pack_quantity === null || $this->packUnit === null) {
            return null;
        }

        return $this->packUnit->quantity($this->pack_quantity);
    }

    public function unitPrice(): ?UnitPrice
    {
        if ($this->unit_price_minor === null || $this->unit_price_per === null) {
            return null;
        }

        // The columns already hold the derived figure, so this reads it back
        // rather than dividing the pack price a second time.
        return new UnitPrice(new Money($this->unit_price_minor), $this->unit_price_per);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'pack_quantity' => 'float',
            'unit_price_per' => UnitDimension::class,
            'valid_to' => 'immutable_date',
        ];
    }
}
