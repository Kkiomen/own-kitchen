<?php

declare(strict_types=1);

return [
    /*
     * The same identification we send when crawling recipes and leaflets — one
     * bot, one contact address, whatever it is reading.
     */
    'user_agent' => env(
        'IMPORT_USER_AGENT',
        'KitchenBot/1.0 (private meal planner; contact: '.env('MAIL_FROM_ADDRESS', 'unknown').')',
    ),

    /*
     * How old a reading may be and still be quoted on an estimate.
     *
     * Well over a year, and that is not laziness. The only broad, impartial
     * source publishes an annual average some months after the year it covers, so
     * on any given day the newest figure it has is between six and eighteen
     * months old. A window of a few months would discard it entirely and leave
     * the estimate blank for flour, milk, butter and eggs — the products a
     * household buys most. The cost is stated where it is read: the screen says
     * "mniej więcej", never a price.
     */
    'max_age_days' => (int) env('PRICING_MAX_AGE_DAYS', 500),

    /*
     * How stale the prices may get before the scheduler refreshes them.
     *
     * Weekly rather than the leaflets' seven-day cycle for a different reason:
     * the statistical source barely changes, but the leaflet-derived half is
     * rebuilt from whatever promotions we currently hold, and those turn over
     * every week. Running it weekly keeps the two in step without asking the API
     * for a figure that will not have moved.
     */
    'refresh_after_days' => (int) env('PRICING_REFRESH_AFTER_DAYS', 7),

    'sources' => [
        'gus' => [
            /*
             * Statistics Poland's Local Data Bank. A public JSON API, no key
             * required — so there is no crawl policy to honour beyond its rate
             * limit, which a batched read of two requests does not come near.
             *
             * Setting `client_id` raises that limit. The API answers without one,
             * so it is not needed to run this.
             */
            'base_url' => env('PRICING_GUS_BASE_URL', 'https://bdl.stat.gov.pl/api/v1'),
            'client_id' => env('PRICING_GUS_CLIENT_ID'),
            'timeout_seconds' => (int) env('PRICING_GUS_TIMEOUT', 30),

            /*
             * A day, because the answer changes once a year at most. Long enough
             * that re-running the command while working on the matcher costs the
             * API nothing; short enough that a newly published year is picked up
             * without anybody clearing a cache.
             */
            'cache_ttl_hours' => (int) env('PRICING_GUS_CACHE_TTL_HOURS', 24),

            /*
             * The file store in development, like the crawlers use, so a
             * `migrate:fresh` does not spend the API's rate limit again.
             *
             * `phpunit.xml` overrides it to the array store, and that is not
             * tidiness: a test that fakes the HTTP layer was answered out of a
             * live run's cached response instead, and failed with a number that
             * appeared nowhere in it.
             */
            'cache_store' => env('PRICING_CACHE_STORE', 'file'),
        ],
    ],
];
