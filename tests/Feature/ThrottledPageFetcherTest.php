<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Http\PageUnavailable;
use App\Support\Http\ThrottledPageFetcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThrottledPageFetcherTest extends TestCase
{
    public function test_it_caches_a_page_so_a_second_read_costs_the_site_nothing(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        $fetcher = $this->fetcher();

        $this->assertSame('<html>ok</html>', $fetcher->get('https://example.test/a'));
        $this->assertSame('<html>ok</html>', $fetcher->get('https://example.test/a'));

        Http::assertSentCount(1);
    }

    public function test_an_error_response_is_reported_as_the_page_being_unavailable(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectException(PageUnavailable::class);

        $this->fetcher()->get('https://example.test/gone');
    }

    /**
     * DNS died 90 minutes into a 4500 recipe import and the transport exception
     * escaped, ending the whole run: only PageUnavailable is caught around a
     * listing walk. A host that cannot be resolved is the same thing to a caller as
     * a page that answers 404 — this page is not available right now.
     */
    public function test_a_connection_failure_is_reported_the_same_way_as_an_error_response(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: example.test'));

        $this->expectException(PageUnavailable::class);
        $this->expectExceptionMessageMatches('/Could not reach/');

        $this->fetcher()->get('https://example.test/unreachable');
    }

    /**
     * The corpus has to survive being used, not expire on a fixed date.
     *
     * Every recipe page the app has ever read lives in this cache, and "a parser
     * fix is a re-run, not a re-crawl" only holds while they are there. Without
     * this, the whole catalogue ages out a fortnight after it was fetched
     * whether or not anybody is working with it, and the next fix costs days of
     * crawling at one page per five to ten seconds.
     */
    public function test_reading_a_page_that_does_not_change_keeps_it_alive(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        $cache = Cache::store('array');
        $fetcher = $this->fetcher($cache, refreshOnHit: true);

        $fetcher->get('https://example.test/recipe');
        $this->travel(45)->minutes();
        $fetcher->get('https://example.test/recipe');
        $this->travel(45)->minutes();

        // Past the original hour, and still there because it was read in between.
        $this->assertSame('<html>ok</html>', $fetcher->get('https://example.test/recipe'));
        Http::assertSentCount(1);
    }

    /**
     * The opposite rule, and it is the reason this is a flag rather than the
     * default: a leaflet page *is* its prices. Keeping one alive because we keep
     * reading it is how last fortnight's prices end up on a plan that tells
     * somebody which shop to drive to.
     */
    public function test_a_page_whose_content_is_the_answer_still_expires(): void
    {
        Http::fake(['*' => Http::response('<html>promocje</html>')]);

        $fetcher = $this->fetcher(Cache::store('array'));

        $fetcher->get('https://example.test/leaflet');
        $this->travel(45)->minutes();
        $fetcher->get('https://example.test/leaflet');
        $this->travel(45)->minutes();
        $fetcher->get('https://example.test/leaflet');

        Http::assertSentCount(2);
    }

    private function fetcher(?Repository $cache = null, bool $refreshOnHit = false): ThrottledPageFetcher
    {
        return new ThrottledPageFetcher(
            cache: $cache ?? Cache::store('array'),
            userAgent: 'KitchenBot/1.0 (test)',
            // Nothing here makes a second request, so no test has to wait for it.
            crawlDelaySeconds: 0,
            cacheTtlHours: 1,
            refreshOnHit: $refreshOnHit,
        );
    }
}
