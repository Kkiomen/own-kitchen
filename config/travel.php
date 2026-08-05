<?php

declare(strict_types=1);

return [
    /*
     * Where the flight-deals app answers. It has no authentication of its own
     * and is not built to have any — it is single-owner and its protection is
     * that the port is not on the internet. So this URL must stay a private one
     * (localhost, a container name, a tunnel or an overlay address); pointing it
     * at a public host would publish that board to anyone who found it.
     */
    'url' => rtrim((string) env('TRAVEL_API_URL', 'http://145.239.89.142:8000'), '/'),

    /*
     * Short on purpose. This call happens while somebody is waiting for a page,
     * not in a queued job — a deals app that has gone away must cost a couple of
     * seconds and an honest "nie mogę się połączyć", never a spinner and a
     * request that eventually 500s.
     */
    'timeout_seconds' => (int) env('TRAVEL_API_TIMEOUT_SECONDS', 8),

    /*
     * The deals are rescanned hourly at the far end, so anything under a few
     * minutes buys nothing and only makes tapping a filter slower. Two minutes
     * keeps a session of "a co z Krakowa?" instant while never showing prices
     * anyone would call stale.
     */
    'cache_ttl_seconds' => (int) env('TRAVEL_CACHE_TTL_SECONDS', 120),

    /*
     * The file store rather than the default, for the same reason the price
     * client uses it: a `migrate:fresh` must not throw away answers we are
     * allowed to keep for two minutes. `phpunit.xml` forces `array` here — a
     * test that fakes the HTTP layer being answered out of a development run's
     * cache is a failure with a number appearing nowhere in the test.
     */
    'cache_store' => env('TRAVEL_CACHE_STORE', 'file'),
];
