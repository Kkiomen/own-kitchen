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
 * What one source said one product cost on one day.
 *
 * Like a `Promotion` it points at a canonical `Ingredient` or at nothing at all,
 * and for the same reason: an invented product would own an alias for good. The
 * difference is what it is for. A promotion answers "what is cheap this week";
 * these accumulate, and a median over them answers "what does this normally
 * cost" — the question a shopping list has to answer before anybody has driven
 * anywhere.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property string $title
 * @property int|null $ingredient_id
 * @property bool $needs_review
 * @property int|null $shop_id
 * @property int $price_minor
 * @property float|null $pack_quantity
 * @property int|null $pack_unit_id
 * @property int|null $unit_price_minor
 * @property UnitDimension|null $unit_price_per
 * @property Carbon $observed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient|null $ingredient
 * @property-read Shop|null $shop
 * @property-read Unit|null $packUnit
 */
#[Fillable([
    'source', 'external_id', 'title', 'ingredient_id', 'needs_review', 'shop_id',
    'price_minor', 'pack_quantity', 'pack_unit_id',
    'unit_price_minor', 'unit_price_per', 'observed_on',
])]
class PriceObservation extends Model
{
    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function packUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'pack_unit_id');
    }

    /**
     * Readings recent enough to quote.
     *
     * An old reading is worse than no reading: quoting last winter's butter as
     * "mniej więcej tyle zapłacisz" is a confident wrong number, and a confident
     * wrong number is the one failure this whole feature has to avoid.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeFresh(Builder $query, int $days): void
    {
        $query->whereDate('observed_on', '>=', now()->subDays($days)->toDateString());
    }

    /**
     * Only readings that can be compared with another. One without a unit price
     * is evidence the source mentioned the product and nothing more.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeComparable(Builder $query): void
    {
        $query->whereNotNull('ingredient_id')->whereNotNull('unit_price_minor');
    }

    public function price(): Money
    {
        return new Money($this->price_minor);
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
            'observed_on' => 'immutable_date',
        ];
    }
}
