<?php

declare(strict_types=1);

namespace App\Travel;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The flight-deals app, read over its JSON API.
 *
 * The whole of it is read-only — scanning and pruning stay that app's own
 * scheduled commands, and nothing here writes. It is also the only class in this
 * module that knows the API exists: everything above it is handed arrays.
 *
 * Two rules this exists to hold:
 *
 * - **A board that cannot be fetched is not an exception the user sees.** The
 *   far end is another private app on another machine; it being down is a normal
 *   Tuesday, not a fault of this one. Every failure comes back as
 *   `DealsUnavailable` and the screen says so in Polish.
 * - **A cached answer is a fresh one here.** The deals are rescanned hourly at
 *   the source, so two minutes of cache makes a session of tapping filters
 *   instant and can never show a price anybody would call stale.
 */
final class DealsApi
{
    public function __construct(
        private readonly Http $http,
        /**
         * Injected rather than reached for through the facade, so a test faking
         * the HTTP layer cannot be answered out of a development run's cache —
         * the lesson `phpunit.xml` already records for the price client.
         */
        private readonly Cache $cache,
    ) {}

    /**
     * The whole screen in one request: deals, the filters that took effect,
     * airport options, totals and thresholds.
     *
     * @return array<string, mixed>
     */
    public function dashboard(DealFilters $filters): array
    {
        return $this->get('/api/v1/dashboard', $filters->toQuery());
    }

    /**
     * The vocabulary and bounds the filter controls are built from.
     *
     * Its own request, so that changing a filter does not re-fetch a list of
     * sorts that cannot have changed. Values only — what a `round_trip` is
     * *called* is this app's decision, and lives in `lib/travel.ts`.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->get('/api/v1/meta', []);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     *
     * @throws DealsUnavailable
     */
    private function get(string $path, array $query): array
    {
        $url = (string) config('travel.url').$path;

        return $this->cache->remember(
            // The query is part of the question, so a different filter is a
            // different cache entry rather than a stale hit under one key.
            'travel:'.md5($url.'?'.http_build_query($query)),
            now()->addSeconds((int) config('travel.cache_ttl_seconds', 120)),
            function () use ($url, $query): array {
                try {
                    $response = $this->http
                        ->acceptJson()
                        ->timeout((int) config('travel.timeout_seconds', 8))
                        ->get($url, $query)
                        ->throw();

                    /** @var array<string, mixed> $body */
                    $body = $response->json() ?? [];

                    return $body;
                } catch (Throwable $failure) {
                    /*
                     * Everything is one failure: a refused connection, a
                     * timeout, a 500 and a body that is not JSON all mean the
                     * same thing to the screen — there is no board to show. The
                     * original is kept as the previous exception so the log
                     * still says which it was.
                     */
                    throw new DealsUnavailable(
                        "Aplikacja z okazjami nie odpowiedziała ({$url}).",
                        previous: $failure,
                    );
                }
            },
        );
    }
}
