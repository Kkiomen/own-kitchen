<?php

declare(strict_types=1);

namespace App\Offers;

use App\Importing\Resolving\UnitResolver;
use App\Models\Promotion;
use App\Models\Shop;
use App\Offers\Drafts\OfferDraft;
use App\Offers\Parsing\PackSizeParser;
use App\Offers\Resolving\PromotionIngredientResolver;
use App\Support\Money\UnitPrice;
use Illuminate\Support\Carbon;

/**
 * Turns one leaflet entry into one row, matched to a product where it can be.
 *
 * Everything site-specific has already happened by the time a draft arrives here.
 * What is left is the part that must behave identically whichever site an offer
 * came from: reading the pack size, deriving a price per kilo, and deciding which
 * canonical product this is — or admitting that we cannot tell.
 */
final class StorePromotionDraft
{
    public function __construct(
        private readonly PackSizeParser $packs,
        private readonly PromotionIngredientResolver $ingredients,
        private readonly UnitResolver $units,
    ) {}

    public function store(OfferDraft $draft, Shop $shop, string $source): Promotion
    {
        $pack = $this->packs->parse($draft->title);
        $unit = $this->units->byCode($pack?->unitCode);

        // Both or neither: a number with no unit behind it cannot be compared,
        // and storing it would make the pack column look richer than it is.
        $size = null;
        $packUnitId = null;

        if ($pack !== null && $unit !== null) {
            $size = $unit->quantity($pack->amount);
            $packUnitId = $unit->id;
        }

        $unitPrice = UnitPrice::of($draft->price, $size);

        // The size is taken out of the name before matching: "200 g" can only
        // ever be noise to a matcher looking for a product.
        $ingredient = $this->ingredients->resolve($pack === null ? $draft->title : $pack->remainingTitle);

        return Promotion::query()->updateOrCreate(
            [
                'source' => $source,
                'external_id' => $draft->externalId,
            ],
            [
                'shop_id' => $shop->id,
                'title' => $draft->title,
                'ingredient_id' => $ingredient?->id,
                'needs_review' => $ingredient === null,
                'price_minor' => $draft->price->grosze,
                'regular_price_minor' => $draft->regularPrice?->grosze,
                'discount_percent' => $draft->price->percentOff($draft->regularPrice),
                'pack_quantity' => $size?->amount,
                'pack_unit_id' => $packUnitId,
                'unit_price_minor' => $unitPrice?->price->grosze,
                'unit_price_per' => $unitPrice?->per,
                'valid_to' => $this->endsOn($draft->endsInDays),
                'url' => $draft->url,
                'image_url' => $draft->imageUrl,
            ],
        );
    }

    /**
     * The listing states how much longer an offer runs, not when it ends, so the
     * date is only ever as good as the day the page was read. That is fine for
     * what it is used for — dropping offers that have run out — and is why
     * nothing in the app quotes it as a promise about a particular day.
     */
    private function endsOn(?int $days): ?Carbon
    {
        return $days === null ? null : Carbon::today()->addDays($days);
    }
}
