<?php

declare(strict_types=1);

namespace App\Pricing\Sources\Leaflets;

use App\Models\Promotion;
use App\Pricing\Contracts\PriceSource;
use App\Pricing\Drafts\PriceDraft;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What this week's leaflets say a thing normally costs.
 *
 * It reads no website. Every price it emits was already fetched, parsed and
 * matched to a canonical product by `App\Offers`, so this is a projection of
 * rows we hold rather than a second crawler over the same pages — and it
 * inherits that module's central guarantee for free: a promotion never invented
 * a product, so neither can this.
 *
 * **It emits only the "before" price, and that is the whole design.** A leaflet
 * states two figures: what a thing costs this week, and — sometimes — what it
 * costs the rest of the time. Only the second is a price to estimate a shopping
 * trip from. Averaging the first would make the estimate say a household pays
 * promotional prices for everything all year, which is both wrong and wrong in
 * the direction that matters: it would quietly under-promise the bill on exactly
 * the products bought most often. An offer with no "before" printed is therefore
 * skipped rather than stored at its shelf price.
 *
 * Nothing is lost by skipping. The promotion row keeps every figure the leaflet
 * printed; what is being decided here is only which of them is evidence of a
 * *normal* price, and re-running this over the same rows is free.
 *
 * The point of keeping these at all is memory. `promotions` is a picture of this
 * week and is pruned when an offer stops running; an observation outlives it, so
 * a product that was on offer in March still helps price a list in August.
 */
final class LeafletPriceSource implements PriceSource
{
    public function name(): string
    {
        return 'leaflets';
    }

    public function label(): string
    {
        return 'Gazetki — ceny regularne';
    }

    /**
     * @return iterable<PriceDraft>
     */
    public function fetch(): iterable
    {
        $offers = Promotion::query()
            ->whereNotNull('regular_price_minor')
            ->whereNotNull('ingredient_id')
            ->with(['shop:id,slug', 'packUnit:id,code'])
            // Chunked because a full refresh across thirteen chains is tens of
            // thousands of rows, and the pipeline writes as it reads.
            ->lazyById(500);

        foreach ($offers as $offer) {
            yield new PriceDraft(
                externalId: $offer->source.':'.$offer->external_id,
                title: $offer->title,
                price: new Money((int) $offer->regular_price_minor),
                packQuantity: $offer->pack_quantity,
                packUnitCode: $offer->packUnit?->code,
                /*
                 * The day the offer was last seen, not the day it started. We know
                 * when we read the page; the leaflet does not say when the regular
                 * price it quotes began, and inventing that would be a date nobody
                 * stated.
                 */
                observedOn: CarbonImmutable::parse($offer->updated_at ?? now())->startOfDay(),
                shopSlug: $offer->shop->slug,
                // Already matched, by a pipeline with a noise list this one does
                // not have. Re-deriving it from the title could disagree with the
                // plan about what the same offer is.
                ingredientId: $offer->ingredient_id,
            );
        }
    }
}
