<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Outbound port for reading a web page. Keeping this behind an interface is what
 * lets everything that crawls — recipes and shop promotions alike — run against
 * fixtures in tests, with no network.
 *
 * It sits in Support rather than in either module because reading a page politely
 * is knowledge both of them need and neither of them owns.
 */
interface PageFetcher
{
    /**
     * @throws PageUnavailable
     */
    public function get(string $url): string;
}
