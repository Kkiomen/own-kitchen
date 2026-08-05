<?php

declare(strict_types=1);

namespace App\Pricing\Contracts;

use App\Pricing\Drafts\PriceDraft;

/**
 * Inbound port for a place that says what things cost.
 *
 * One implementation per source, exactly as with recipes and leaflets. The two
 * that exist answer different questions and that is the point of having a port
 * rather than one class: a statistical office publishes a national average that
 * is slow, broad and impartial, and a leaflet publishes this week's price in one
 * chain, which is fast, narrow and deliberately flattering. Neither is "the"
 * price, and the pipeline below this line must not know which it is holding.
 *
 * Implementations are responsible for honouring the source's usage policy.
 */
interface PriceSource
{
    /**
     * Stable identifier stored on every observation, e.g. "gus".
     */
    public function name(): string;

    /**
     * What this source is, in the words the quality report prints.
     */
    public function label(): string;

    /**
     * Every price this source currently states.
     *
     * A generator rather than an array: a leaflet source walks thousands of rows,
     * and the pipeline writes as it reads rather than holding the lot.
     *
     * @return iterable<PriceDraft>
     */
    public function fetch(): iterable;
}
