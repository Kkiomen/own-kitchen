<?php

declare(strict_types=1);

namespace App\Offers\Sources\GazetkiPl;

use App\Offers\Contracts\OfferSource;
use App\Offers\Drafts\OfferDraft;
use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * gazetki.pl adapter.
 *
 * Chosen over the other Polish leaflet aggregators because it is the only one
 * that publishes offers as text. blix.pl disallows its own /api/ and shows
 * mostly page scans; ding.pl disallows its search. Both were checked — do not
 * re-tread them without a reason.
 *
 * Its robots.txt bans Scrapy and the SEO crawlers outright and gives the AI
 * crawlers it names a Crawl-delay of 10. We are not on that list, so ten seconds
 * is the delay we take anyway: it is the number the site itself considers polite.
 */
final class GazetkiPlOfferSource implements OfferSource
{
    /**
     * @param  list<string>  $shopSlugs
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly GazetkiPlOfferParser $parser,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly array $shopSlugs,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function shops(): array
    {
        return $this->shopSlugs;
    }

    /**
     * @return Generator<OfferDraft>
     */
    public function discover(string $shopSlug, int $maxPages): Generator
    {
        $firstPage = $this->page($shopSlug, 1);

        if ($firstPage === null) {
            return;
        }

        foreach ($this->parser->parseOffers($firstPage, $shopSlug, $this->baseUrl) as $offer) {
            yield $offer;
        }

        $lastPage = min($this->parser->parseLastPage($firstPage), $maxPages);

        for ($page = 2; $page <= $lastPage; $page++) {
            $html = $this->page($shopSlug, $page);

            if ($html === null) {
                return;
            }

            foreach ($this->parser->parseOffers($html, $shopSlug, $this->baseUrl) as $offer) {
                yield $offer;
            }
        }
    }

    private function page(string $shopSlug, int $number): ?string
    {
        $url = $this->baseUrl.$this->path($shopSlug, $number);

        try {
            return $this->fetcher->get($url);
        } catch (PageUnavailable $exception) {
            // One unreachable page must not end a walk that is otherwise working,
            // but it must not vanish either: a shop slug that quietly 404s would
            // just look like a chain with no promotions this week.
            Log::warning('Offer listing unavailable, skipping it.', [
                'source' => $this->name,
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * `page` must be the FIRST query parameter and the only one.
     *
     * The site's own paginator links carry `?sort=…&page=N`, and its robots.txt
     * disallows `/sklepy/*?*` while allowing exactly `/sklepy/*?page=*`. Copying
     * the site's own href would therefore crawl a path we have been asked not to.
     * The bare form returns the same offers in the same order.
     */
    private function path(string $shopSlug, int $number): string
    {
        return '/sklepy/'.$shopSlug.'/oferty'.($number > 1 ? '?page='.$number : '');
    }
}
