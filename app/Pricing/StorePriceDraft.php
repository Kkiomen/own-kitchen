<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Importing\Resolving\UnitResolver;
use App\Models\PriceObservation;
use App\Offers\ShopDirectory;
use App\Pricing\Drafts\PriceDraft;
use App\Pricing\Resolving\PriceIngredientResolver;
use App\Support\Money\UnitPrice;

/**
 * Turns one price reading into one row, matched to a product where it can be.
 *
 * Everything source-specific has already happened by the time a draft arrives.
 * What is left is the part that must behave identically whichever source a price
 * came from: giving the pack a real unit, deriving a price per kilo, and
 * deciding which canonical product this is — or admitting that we cannot tell.
 */
final class StorePriceDraft
{
    public function __construct(
        private readonly PriceIngredientResolver $ingredients,
        private readonly UnitResolver $units,
        private readonly ShopDirectory $shops,
    ) {}

    public function store(PriceDraft $draft, string $source): PriceObservation
    {
        $unit = $this->units->byCode($draft->packUnitCode);

        // Both or neither: a number with no unit behind it cannot be compared,
        // and storing it would make the pack column look richer than it is.
        $size = null;
        $packUnitId = null;

        if ($draft->packQuantity !== null && $unit !== null) {
            $size = $unit->quantity($draft->packQuantity);
            $packUnitId = $unit->id;
        }

        $unitPrice = UnitPrice::of($draft->price, $size);
        $ingredientId = $draft->ingredientId ?? $this->ingredients->resolve($draft->title)?->id;

        $values = [
            'title' => $draft->title,
            'ingredient_id' => $ingredientId,
            'needs_review' => $ingredientId === null,
            'shop_id' => $this->shopId($draft->shopSlug),
            'price_minor' => $draft->price->grosze,
            'pack_quantity' => $size?->amount,
            'pack_unit_id' => $packUnitId,
            'unit_price_minor' => $unitPrice?->price->grosze,
            'unit_price_per' => $unitPrice?->per,
        ];

        $existing = $this->existing($draft, $source);

        if ($existing !== null) {
            $existing->fill($values)->save();

            return $existing;
        }

        return PriceObservation::query()->create($values + [
            'source' => $source,
            'external_id' => $draft->externalId,
            'observed_on' => $draft->observedOn,
        ]);
    }

    /**
     * The reading this one replaces, if there is one.
     *
     * The day is part of the identity, not just a column: re-running a source on
     * the same day corrects that day's reading, and running it next month adds a
     * second one — which is what gives the median anything to be a median of.
     *
     * Looked up with `whereDate` rather than through `updateOrCreate`, and that
     * is not a style choice. The column is a date, but Eloquent writes it through
     * the model's datetime format, so the stored value is "2025-12-31 00:00:00"
     * while the value handed to a `where` is "2025-12-31". They never matched, so
     * every re-run tried to insert a duplicate and lost the whole refresh to
     * constraint violations — reported as failures, which at least made it loud.
     */
    private function existing(PriceDraft $draft, string $source): ?PriceObservation
    {
        return PriceObservation::query()
            ->where('source', $source)
            ->where('external_id', $draft->externalId)
            ->whereDate('observed_on', $draft->observedOn->toDateString())
            ->first();
    }

    /**
     * A reading that names no chain belongs to no chain — a national average is
     * not a price in a particular shop, and filing it under one would let it be
     * picked up by a plan that narrowed to that shop.
     */
    private function shopId(?string $slug): ?int
    {
        // Lookup-or-create, cached per run: the directory is the one place a
        // chain's row is made, so a price source cannot split Biedronka in two.
        return $slug === null ? null : $this->shops->for($slug)->id;
    }
}
