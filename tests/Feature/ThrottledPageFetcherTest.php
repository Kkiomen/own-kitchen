<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Http\PageUnavailable;
use App\Support\Http\ThrottledPageFetcher;
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

    private function fetcher(): ThrottledPageFetcher
    {
        return new ThrottledPageFetcher(
            cache: Cache::store('array'),
            userAgent: 'KitchenBot/1.0 (test)',
            // Nothing here makes a second request, so no test has to wait for it.
            crawlDelaySeconds: 0,
            cacheTtlHours: 1,
        );
    }
}
