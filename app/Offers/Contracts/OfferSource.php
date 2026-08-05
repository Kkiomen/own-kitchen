<?php

declare(strict_types=1);

namespace App\Offers\Contracts;

use App\Offers\Drafts\OfferDraft;

/**
 * Inbound port for a place that publishes shop leaflets. One implementation per
 * site, exactly as with recipes: the pipeline below this line must not know
 * whether an offer came from an aggregator or from a chain's own website.
 *
 * Implementations are responsible for honouring the site's crawl policy.
 */
interface OfferSource
{
    /**
     * Stable identifier stored on every promotion, e.g. "gazetki.pl".
     */
    public function name(): string;

    /**
     * The shops this source can be asked about, as its own slugs.
     *
     * @return list<string>
     */
    public function shops(): array;

    /**
     * Walk one shop's current offers.
     *
     * @param  int  $maxPages  Cap on listing pages, because a chain publishes
     *                         thousands of offers and most are not food.
     * @return iterable<OfferDraft>
     */
    public function discover(string $shopSlug, int $maxPages): iterable;
}
