<?php

declare(strict_types=1);

namespace App\Pricing\Drafts;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One reading of one price, as the source stated it.
 *
 * This is the boundary. Everything above it knows a particular source — an API's
 * JSON, a leaflet's markup; everything below it knows only this shape. Nothing
 * here has been matched to a product: doing that in an adapter would put the
 * catalogue's central rule inside a class that changes whenever a source does.
 *
 * The pack is carried as text *and* as numbers because sources state it both
 * ways. GUS puts it in the variable's own name ("mąka pszenna - za 1kg"), a
 * leaflet puts it in the product title, and both are parsed before they get
 * here — but `title` is kept whole regardless, because a wrong match is
 * diagnosed from what the source said, never from what we made of it.
 */
final readonly class PriceDraft
{
    public function __construct(
        public string $externalId,
        public string $title,
        public Money $price,
        /**
         * What that price buys. Both null together: a size with no unit cannot be
         * compared with anything, and a unit with no size is not a size.
         */
        public ?float $packQuantity,
        public ?string $packUnitCode,
        /**
         * The day the price held. Sources are not more precise than this, and the
         * ones that publish a whole month or year are given its first day rather
         * than the day we happened to read them — re-reading the same figure must
         * not look like a fresh reading.
         */
        public CarbonImmutable $observedOn,
        /**
         * Which chain, when the reading belongs to one. A national average
         * belongs to no shop and leaves this null.
         */
        public ?string $shopSlug = null,
        /**
         * The product, when the source already knows it.
         *
         * The one exception to "nothing here has been matched yet", and it earns
         * it: a leaflet price comes from a `Promotion` row that the offers
         * pipeline already matched, with a noise list and a negation rule this
         * pipeline does not have. Matching the same title a second time would at
         * best repeat that work and at worst disagree with it — pricing butter
         * from an offer the plan has filed under something else. Null means the
         * source genuinely does not know, and the pipeline matches the title.
         */
        public ?int $ingredientId = null,
    ) {}
}
