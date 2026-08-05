<?php

declare(strict_types=1);

return [
    /*
     * The same identification we send when crawling recipes — one bot, one
     * contact address, whatever it is reading.
     */
    'user_agent' => env(
        'IMPORT_USER_AGENT',
        'KitchenBot/1.0 (private meal planner; contact: '.env('MAIL_FROM_ADDRESS', 'unknown').')',
    ),

    /*
     * Deliberately far shorter than the recipe cache's two weeks.
     *
     * A recipe page is effectively immutable, so caching it for a fortnight costs
     * nothing and saves a re-crawl. A leaflet is the opposite: its whole content
     * is a price that expires. Serving a cached listing for two weeks would put
     * last fortnight's prices on a plan and look authoritative doing it. Six
     * hours is long enough that re-running an import the same evening is free.
     */
    'page_cache_ttl_hours' => (int) env('OFFERS_PAGE_CACHE_TTL_HOURS', 6),

    /*
     * An offer this source has not shown us for this long is treated as
     * withdrawn and deleted. Longer than the refresh interval on purpose: a run
     * that only reached page 3 of 120 must not delete what it never got to.
     */
    'stale_after_days' => (int) env('OFFERS_STALE_AFTER_DAYS', 14),

    /*
     * How old the newest offer we hold may get before the scheduler goes and
     * reads the leaflets again. Polish leaflets turn over weekly, so seven days
     * is one cycle — shorter would re-crawl the same prices, longer would plan
     * shopping around a leaflet that has already been replaced.
     *
     * Deliberately shorter than `stale_after_days`: an offer must survive at
     * least one refresh cycle without being seen before it counts as withdrawn.
     */
    'refresh_after_days' => (int) env('OFFERS_REFRESH_AFTER_DAYS', 7),

    /*
     * Listing pages walked per shop by default. A chain publishes around 4000
     * offers over 120 pages and most of them are not food, so a full walk is for
     * the weekly refresh; a smaller number is what you run while working on the
     * matcher.
     */
    'default_pages' => (int) env('OFFERS_DEFAULT_PAGES', 12),

    /*
     * The chains worth reading, in the order a plan should list them. Grocers
     * only: the aggregator also carries clothing and DIY chains, which would
     * spend a throttled request per page to find nothing edible.
     */
    'shops' => [
        'biedronka' => 'Biedronka',
        'lidl' => 'Lidl',
        'aldi' => 'Aldi',
        'netto' => 'Netto',
        'kaufland' => 'Kaufland',
        'dino' => 'Dino',
        'stokrotka' => 'Stokrotka',
        'polomarket' => 'PoloMarket',
        'carrefour' => 'Carrefour',
        'auchan' => 'Auchan',
        'intermarche' => 'Intermarché',
        'topaz' => 'Topaz',
        'zabka' => 'Żabka',
    ],

    'sources' => [
        'gazetki' => [
            'name' => 'gazetki.pl',
            'base_url' => 'https://www.gazetki.pl',

            /*
             * The site's robots.txt bans Scrapy and the SEO crawlers outright and
             * gives the AI crawlers it names a Crawl-delay of 10. We are not on
             * that list, so this is the number the site itself calls polite
             * rather than one addressed to us. Do not lower it.
             */
            'crawl_delay_seconds' => 10,
        ],
    ],
];
