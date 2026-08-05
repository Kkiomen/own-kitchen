<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;

/**
 * Serves canned pages so the import pipeline can be exercised end to end without
 * touching the network.
 */
final class FakePageFetcher implements PageFetcher
{
    /**
     * @var list<string>
     */
    public array $requestedUrls = [];

    /**
     * @param  array<string, string>  $pages  URL => body
     */
    public function __construct(private readonly array $pages) {}

    public function get(string $url): string
    {
        $this->requestedUrls[] = $url;

        return $this->pages[$url] ?? throw PageUnavailable::forUrl($url, 404);
    }
}
