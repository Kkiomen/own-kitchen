<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches pages politely: one request per crawl-delay window, and never twice for
 * the same URL while the cache entry lives. Re-running an import, or fixing the
 * parser and importing again, therefore costs the source site nothing.
 */
final class ThrottledPageFetcher implements PageFetcher
{
    private ?float $lastRequestAt = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $userAgent,
        private readonly int $crawlDelaySeconds,
        private readonly int $cacheTtlHours,
        /**
         * Whether serving a page from the cache also restarts its clock.
         *
         * True only where the page is effectively immutable — a recipe published
         * three years ago is the same recipe today. There it is what keeps
         * "re-processing is free" actually true: without it the corpus ages out
         * on a fixed date whether or not anybody is using it, and the first
         * parser fix after that date costs days of polite crawling rather than
         * minutes. With it, the corpus lives as long as it is being worked with
         * and only expires once it is genuinely abandoned.
         *
         * False for anything whose content *is* the answer. A leaflet page is its
         * prices; keeping one alive because we keep reading it is how last
         * fortnight's prices end up on a plan that says "drive here".
         */
        private readonly bool $refreshOnHit = false,
    ) {}

    public function get(string $url): string
    {
        // Still "import:" though promotions crawl through here too. The prefix is
        // not a namespace, it is the key of a cache holding thousands of already
        // fetched recipe pages; renaming it would silently orphan every one of
        // them and cost a full re-crawl of four sites to gain nothing.
        $cacheKey = 'import:page:'.sha1($url);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            if ($this->refreshOnHit) {
                $this->cache->put($cacheKey, $cached, now()->addHours($this->cacheTtlHours));
            }

            return $cached;
        }

        $this->waitForCrawlWindow();

        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent])
                ->timeout(30)
                ->retry(2, 2000, throw: false)
                ->get($url);
        } catch (ConnectionException $exception) {
            // A page that answers with an error and a host that cannot be resolved
            // are the same thing to a caller: this page is not available right now.
            // Leaving the transport exception to escape ended a 90 minute import on
            // one DNS blip, because only PageUnavailable is caught around a listing.
            $this->lastRequestAt = microtime(true);

            throw PageUnavailable::unreachable($url, $exception->getMessage());
        }

        $this->lastRequestAt = microtime(true);

        if (! $response->successful()) {
            throw PageUnavailable::forUrl($url, $response->status());
        }

        $body = $response->body();
        $this->cache->put($cacheKey, $body, now()->addHours($this->cacheTtlHours));

        return $body;
    }

    private function waitForCrawlWindow(): void
    {
        if ($this->lastRequestAt === null) {
            return;
        }

        $elapsed = microtime(true) - $this->lastRequestAt;
        $remaining = $this->crawlDelaySeconds - $elapsed;

        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }
}
