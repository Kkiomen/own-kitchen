<?php

declare(strict_types=1);

namespace App\Offers\Drafts;

use App\Support\Money\Money;

/**
 * One leaflet entry as the site wrote it: a price and a line of text.
 *
 * This is the boundary. Everything above it knows a particular site's markup;
 * everything below it knows only this shape. Nothing here has been matched to a
 * product yet — that is the pipeline's job, and doing it in an adapter would put
 * the catalogue's central rule inside a class that changes whenever a site
 * redesigns.
 */
final readonly class OfferDraft
{
    public function __construct(
        public string $externalId,
        public string $shopSlug,
        public string $title,
        public Money $price,
        public ?Money $regularPrice,
        /**
         * How many days the listing says the offer still runs. The sites state a
         * remaining duration rather than an end date, so this is deliberately not
         * called a date: turning it into one is the pipeline's decision, made
         * against the day the page was actually read.
         */
        public ?int $endsInDays,
        public string $url,
        public ?string $imageUrl,
    ) {}
}
